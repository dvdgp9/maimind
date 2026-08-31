<?php

declare(strict_types=1);

namespace MaiMind\Http;

use MaiMind\Domain\Auth\Authenticator;
use MaiMind\Domain\Capture\AudioStore;
use MaiMind\Domain\Capture\CaptureClock;
use MaiMind\Domain\Auth\LoginThrottle;
use MaiMind\Domain\Jobs\JobQueue;
use MaiMind\Domain\Auth\PasswordHasher;
use MaiMind\Domain\Auth\SessionManager;
use MaiMind\Domain\User;
use MaiMind\Repository\EntryRepository;
use MaiMind\Repository\TranscriptRepository;
use MaiMind\Repository\UserRepository;
use MaiMind\Support\AssetVersion;
use MaiMind\Support\Config;
use MaiMind\Support\Lang;
use MaiMind\Support\Ulid;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Punto único por el que pasa toda petición.
 *
 * Aquí viven las tres garantías transversales:
 *
 *  1. Ninguna ruta marcada como privada se ejecuta sin usuario resuelto.
 *  2. Ningún POST se ejecuta sin testigo CSRF válido.
 *  3. El usuario resuelto es lo único que construye repositorios, así que un
 *     controlador no puede pedir datos de otra persona ni queriendo.
 */
final class Kernel
{
    private Router $router;

    private SessionManager $sessions;

    private Authenticator $auth;

    private UserRepository $users;

    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
        private readonly string $appKey,
        private readonly bool $secureCookies = false,
        private readonly bool $debug = false,
    ) {
        $this->users    = new UserRepository($this->pdo);
        $this->sessions = new SessionManager($this->pdo, $this->users);

        $this->auth = new Authenticator(
            $this->users,
            new PasswordHasher(),
            $this->sessions,
            new LoginThrottle($this->pdo),
        );

        $this->router = new Router();
        $this->registerRoutes();
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->route($request);
        } catch (Throwable $e) {
            $this->logger->error('Excepción no controlada', [
                'path'  => $request->path,
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ]);

            if ($request->wantsJson()) {
                return Response::json([
                    'error'  => $this->debug ? $e->getMessage() : t('errors.generic'),
                ], 500);
            }

            return Response::html(
                View::render('error', [
                    'title'   => t('errors.generic'),
                    'message' => $this->debug ? $e->getMessage() : '',
                    'user'    => null,
                ]),
                500,
            );
        }
    }

    private function route(Request $request): Response
    {
        $match = $this->router->match($request->method, $request->path);

        if ($match === null) {
            $status = $this->router->pathExists($request->path) ? 405 : 404;

            return $request->wantsJson()
                ? Response::json(['error' => t('errors.not_found')], $status)
                : Response::html(
                    View::render('error', [
                        'title' => t('errors.not_found'), 'message' => '', 'user' => null,
                    ]),
                    $status,
                );
        }

        $request->attributes = $match['params'];

        $token = $request->cookie(SessionManager::COOKIE);
        $user  = $this->sessions->resolve($token);

        if ($user !== null) {
            Lang::setLocale($user->locale);
        }

        if ($match['auth'] && $user === null) {
            return $request->wantsJson()
                ? Response::json(['error' => t('errors.unauthorized')], 401)
                : Response::redirect('/acceder');
        }

        if ($request->method === 'POST' && ! $this->csrfIsValid($request, $token)) {
            $this->logger->warning('Testigo CSRF inválido', ['path' => $request->path]);

            // 419 y no una redirección: un 302 sería indistinguible de un envío
            // correcto, y devolvería al usuario a una URL que para algunas rutas
            // ni siquiera existe como GET.
            return $request->wantsJson()
                ? Response::json(['error' => t('errors.csrf')], 419)
                : Response::html(
                    View::render('error', [
                        'title' => t('errors.csrf'), 'message' => '', 'user' => null,
                    ]),
                    419,
                );
        }

        return ($match['handler'])($request, $user);
    }

    private function csrfIsValid(Request $request, ?string $sessionToken): bool
    {
        $submitted = $request->input(Csrf::FIELD) ?? $request->header('x-csrf-token');

        $expected = $sessionToken !== null
            ? Csrf::forSession($this->sessions->fingerprint($sessionToken), $this->appKey)
            : $request->cookie(Csrf::ANON_COOKIE);

        return Csrf::matches($submitted, $expected);
    }

    private function csrfTokenFor(Request $request): string
    {
        $sessionToken = $request->cookie(SessionManager::COOKIE);

        return $sessionToken !== null
            ? Csrf::forSession($this->sessions->fingerprint($sessionToken), $this->appKey)
            : ($request->cookie(Csrf::ANON_COOKIE) ?? Csrf::newAnonymous());
    }

    private function withAnonymousCsrf(Response $response, Request $request, string $token): Response
    {
        if ($request->cookie(Csrf::ANON_COOKIE) === $token) {
            return $response;
        }

        return $response->withCookie(
            Csrf::ANON_COOKIE,
            $token,
            Csrf::anonymousCookieOptions($this->secureCookies),
        );
    }

    private function registerRoutes(): void
    {
        $r = $this->router;

        // ---------------------------------------------------------- públicas

        $r->get('/acceder', function (Request $request): Response {
            $token = $this->csrfTokenFor($request);

            return $this->withAnonymousCsrf(
                Response::html(View::render('auth/acceder', [
                    'csrf' => $token, 'error' => null, 'email' => '', 'user' => null,
                ])),
                $request,
                $token,
            );
        }, auth: false);

        $r->post('/acceder', function (Request $request): Response {
            $email    = (string) $request->input('email', '');
            $password = (string) $request->input('password', '');

            $result = $this->auth->attempt($email, $password, $request->ip);

            if (! $result['ok']) {
                $this->logger->info('Acceso fallido', [
                    'reason' => $result['error'],
                    // Nunca el correo en claro: acaba en ficheros de log.
                    'email'  => substr(hash('sha256', mb_strtolower($email)), 0, 12),
                ]);

                $token = $this->csrfTokenFor($request);

                return $this->withAnonymousCsrf(
                    Response::html(View::render('auth/acceder', [
                        'csrf'  => $token,
                        'error' => t((string) $result['error'], [
                            'minutes' => (int) ceil(($result['retryAfter'] ?? 0) / 60),
                        ]),
                        'email' => $email,
                        'user'  => null,
                    ]), 422),
                    $request,
                    $token,
                );
            }

            return $this->startSessionAndRedirect($result['user'], $request);
        }, auth: false);

        // El service worker se sirve desde PHP y no como fichero estático, para
        // poder inyectarle la versión de la caché. Ver AssetVersion.
        $r->get('/sw.js', function (Request $request): Response {
            $codigo = (string) file_get_contents(Config::basePath('resources/sw.js'));

            return (new Response(200, str_replace('__VERSION__', AssetVersion::current(), $codigo)))
                ->withHeader('Content-Type', 'application/javascript; charset=utf-8')
                // Sin esto, una versión nueva del worker puede tardar hasta un
                // día en llegar, y con ella todo lo que ese worker decide
                // servir desde su propia caché.
                ->withHeader('Cache-Control', 'no-cache, max-age=0')
                // Aunque hoy se sirve desde la raíz y no haría falta.
                ->withHeader('Service-Worker-Allowed', '/');
        }, auth: false);

        // La enseña el service worker cuando no hay red ni copia de la página
        // que se pedía. Pública y sin datos de nadie: se cachea tal cual.
        $r->get('/sin-conexion', function (Request $request): Response {
            return Response::html(View::render('sin-conexion', ['user' => null]));
        }, auth: false);

        $r->get('/registro', function (Request $request): Response {
            $token = $this->csrfTokenFor($request);

            return $this->withAnonymousCsrf(
                Response::html(View::render('auth/registro', [
                    'csrf' => $token, 'error' => null, 'field' => null,
                    'email' => '', 'displayName' => '', 'user' => null,
                ])),
                $request,
                $token,
            );
        }, auth: false);

        $r->post('/registro', function (Request $request): Response {
            $email = (string) $request->input('email', '');

            $result = $this->auth->register(
                email: $email,
                password: (string) $request->input('password', ''),
                displayName: $request->input('display_name'),
                timezone: (string) ($request->input('timezone') ?: 'Europe/Madrid'),
            );

            if (! $result['ok']) {
                $token = $this->csrfTokenFor($request);

                return $this->withAnonymousCsrf(
                    Response::html(View::render('auth/registro', [
                        'csrf'        => $token,
                        'error'       => t((string) $result['error']),
                        'field'       => $result['field'] ?? null,
                        'email'       => $email,
                        'displayName' => (string) $request->input('display_name', ''),
                        'user'        => null,
                    ]), 422),
                    $request,
                    $token,
                );
            }

            $this->logger->info('Usuario registrado', ['user_id' => $result['user']->id]);

            return $this->startSessionAndRedirect($result['user'], $request);
        }, auth: false);

        // ---------------------------------------------------------- privadas

        $r->post('/salir', function (Request $request, ?User $user): Response {
            $this->sessions->destroy($request->cookie(SessionManager::COOKIE));

            return Response::redirect('/acceder')->withCookie(
                SessionManager::COOKIE,
                '',
                $this->sessions->cookieOptions(time() - 3600, $this->secureCookies),
            );
        });

        $r->get('/', function (Request $request, ?User $user): Response {
            $entries = $this->entriesFor($user);

            return Response::html(View::render('inicio', [
                'user'    => $user,
                'csrf'    => $this->csrfTokenFor($request),
                'latest'  => $entries->latest(),
                'total'   => $entries->countAll(),
            ]));
        });

        $r->get('/grabaciones', function (Request $request, ?User $user): Response {
            $entries = $this->entriesFor($user);

            return Response::html(View::render('grabaciones', [
                'user'     => $user,
                'entradas' => $entries->timeline(),
                'total'    => $entries->countAll(),
            ]));
        });

        // --------------------------------------------------- una grabación

        $r->get('/entrada/{uid}', function (Request $request, ?User $user): Response {
            $uid     = (string) $request->attribute('uid');
            $entries = $this->entriesFor($user);
            $entrada = $entries->detail($uid);

            if ($entrada === null) {
                // 404 y no 403: un 403 confirmaría que ese identificador existe.
                return Response::html(View::render('error', [
                    'title' => t('errors.not_found'), 'message' => '', 'user' => $user,
                ]), 404);
            }

            return Response::html(View::render('entrada', [
                'user'          => $user,
                'csrf'          => $this->csrfTokenFor($request),
                'entrada'       => $entrada,
                'transcripcion' => $this->transcriptsFor($user)->currentFor((int) $entrada['id']),
                'guardado'      => isset($request->query['guardado']),
                'volverA'       => $this->dondeVolver($request),
            ]));
        });

        // El audio de una grabación. Sin poder escucharlo, corregir una
        // transcripción es hacer memoria a ciegas.
        $r->get('/entrada/{uid}/audio', function (Request $request, ?User $user): Response {
            $entrada = $this->entriesFor($user)->forTranscription(
                (string) $request->attribute('uid')
            );

            // Purgada, nunca guardada, o de otra persona: lo mismo, 404.
            if ($entrada === null
                || $entrada['audio_state'] !== 'present'
                || $entrada['audio_path'] === null) {
                return Response::json(['error' => t('errors.not_found')], 404);
            }

            $store = new AudioStore(Config::basePath((string) config('app.paths.storage')));
            $ruta  = $store->absolutePath((string) $entrada['audio_path']);

            if (! is_file($ruta)) {
                // La fila dice que está y no está. Vale la pena saberlo.
                $this->logger->warning('Audio ausente en disco', [
                    'user_id' => $user->id, 'entry' => $entrada['uid'],
                ]);

                return Response::json(['error' => t('errors.not_found')], 404);
            }

            $rango = $request->byteRange((int) filesize($ruta));

            return Response::file(
                $ruta,
                (string) ($entrada['audio_mime'] ?: 'application/octet-stream'),
                $rango,
                $rango === null ? 200 : 206,
            );
        });

        $r->post('/entrada/{uid}/transcripcion', function (Request $request, ?User $user): Response {
            $uid     = (string) $request->attribute('uid');
            $entries = $this->entriesFor($user);
            $entrada = $entries->detail($uid);

            if ($entrada === null) {
                return Response::html(View::render('error', [
                    'title' => t('errors.not_found'), 'message' => '', 'user' => $user,
                ]), 404);
            }

            $texto = trim((string) $request->input('text', ''));

            $transcripts = $this->transcriptsFor($user);
            $actual      = $transcripts->currentFor((int) $entrada['id']);

            if ($texto === '' || $actual === null || $texto === (string) $actual['text']) {
                // Nada que guardar. Guardar una copia idéntica llenaría el
                // historial de versiones que no dicen nada.
                return Response::redirect('/entrada/' . $uid);
            }

            $transcripts->storeManualEdit(
                (int) $entrada['id'],
                $texto,
                $entrada['audio_duration_ms'] === null ? null : (int) $entrada['audio_duration_ms'],
            );

            // El texto ha cambiado, así que lo que se extraiga de él también.
            // La clave de deduplicación se encarga de que no se encole dos
            // veces si ya había una extracción esperando.
            (new JobQueue($this->pdo))->push(
                type: 'extract',
                payload: ['entry' => $uid],
                userId: $user->id,
                dedupeKey: 'extract:' . $uid,
                priority: 4,
            );

            $this->logger->info('Transcripción corregida a mano', [
                'user_id' => $user->id, 'entry' => $uid,
            ]);

            return Response::redirect('/entrada/' . $uid . '?guardado=1');
        });

        $r->get('/api/entries', function (Request $request, ?User $user): Response {
            return Response::json(['entries' => $this->entriesFor($user)->recent(50)]);
        });

        $r->post('/api/entries', function (Request $request, ?User $user): Response {
            return $this->capture($request, $user);
        });

        $r->get('/api/entries/{uid}', function (Request $request, ?User $user): Response {
            $entry = $this->entriesFor($user)->findByUid((string) $request->attribute('uid'));

            // Un registro de otra persona no da 403: da 404. Un 403 confirmaría
            // que ese identificador existe.
            return $entry === null
                ? Response::json(['error' => t('errors.not_found')], 404)
                : Response::json(['entry' => $entry]);
        });
    }

    /**
     * Recibe una grabación.
     *
     * Sin proceso pesado aquí: se valida, se guarda el fichero y se crea la fila.
     * Transcribir y extraer los hará el worker (fases 2 y 3), para que el usuario
     * pueda cerrar la aplicación en cuanto suelte el botón.
     */
    private function capture(Request $request, ?User $user): Response
    {
        assert($user !== null);

        $file = $request->file('audio');

        if ($file === null) {
            $error = $request->fileError('audio');

            // Se distingue "demasiado grande" de "no ha llegado nada": el
            // usuario acaba de hablar dos minutos y merece saber qué pasó.
            $demasiadoGrande = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

            return Response::json([
                'error' => t($demasiadoGrande ? 'errors.audio_too_big' : 'errors.audio_missing'),
            ], 422);
        }

        // Lo primero, antes de tocar el disco: ¿es esta grabación una que ya
        // llegó? La cola sin conexión del móvil reintenta, y un reintento cuya
        // respuesta anterior se perdió por el camino no puede crear una segunda
        // entrada. Devolver la que ya existe es la respuesta correcta, no un
        // error: para el cliente el resultado es idéntico.
        $entries      = $this->entriesFor($user);
        $clientToken  = $this->clientToken($request->input('client_token'));

        if ($clientToken !== null) {
            $anterior = $entries->findByClientToken($clientToken);

            if ($anterior !== null) {
                return $this->capturaAceptada($anterior['uid'], (string) $anterior['local_date'], true);
            }
        }

        $mime = (string) ($request->input('mime') ?? $file['type']);

        if (! AudioStore::isAccepted($mime)) {
            return Response::json(['error' => t('errors.audio_bad_type')], 415);
        }

        $maxBytes = (int) config('services.audio.max_bytes');

        if ($file['size'] > $maxBytes) {
            return Response::json(['error' => t('errors.audio_too_big')], 413);
        }

        if ($file['size'] === 0) {
            return Response::json(['error' => t('errors.audio_empty')], 422);
        }

        $clock = (new CaptureClock())->resolve(
            $request->input('captured_at'),
            $request->input('timezone') ?: $user->timezone,
            $request->input('utc_offset'),
        );

        if ($clock['clock_was_adjusted']) {
            // No se rechaza: el registro vale igual. Pero conviene saberlo,
            // porque un reloj torcido desplaza el día local de esa persona.
            $this->logger->warning('Reloj del cliente fuera de rango', ['user_id' => $user->id]);
        }

        $store = new AudioStore(Config::basePath((string) config('app.paths.storage')));

        $uid = Ulid::generate();

        try {
            $stored = $store->store($user->uid, $uid, (string) $file['tmp_name'], $mime);
        } catch (Throwable $e) {
            $this->logger->error('No se pudo guardar el audio', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return Response::json(['error' => t('errors.generic')], 500);
        }

        $moodHint = $this->moodHint($request->input('mood_hint'));

        try {
            $entryUid = $entries->createFromAudio(
                clock: $clock,
                audio: [
                    ...$stored,
                    'mime'        => AudioStore::normalizeMime($mime),
                    'duration_ms' => $this->durationMs($request->input('duration_ms')),
                ],
                moodHint: $moodHint,
                retentionDays: (int) config('services.audio.retention_days'),
                uid: $uid,
                clientToken: $clientToken,
            );
        } catch (PDOException $e) {
            // Dos reintentos a la vez: la comprobación de arriba la ganaron los
            // dos y la unicidad de la base decide. Quien pierde se lleva la
            // entrada del que ganó, que es lo que el cliente venía a buscar.
            $anterior = $clientToken !== null && $e->getCode() === '23000'
                ? $entries->findByClientToken($clientToken)
                : null;

            if ($anterior === null) {
                throw $e;
            }

            $store->delete($stored['path']);

            return $this->capturaAceptada($anterior['uid'], (string) $anterior['local_date'], true);
        }

        // A partir de aquí el trabajo es del worker. Encolar puede fallar sin
        // que la grabación se pierda: el audio y la fila ya están, y el
        // trabajo se puede volver a encolar. Devolver 500 haría que el cliente
        // reintentase la subida y duplicase la entrada.
        try {
            (new JobQueue($this->pdo))->push(
                type: 'transcribe',
                payload: ['entry' => $entryUid],
                userId: $user->id,
                // El uid de la entrada es único en todo el sistema, así que
                // basta él para que un doble envío no pague dos transcripciones.
                dedupeKey: 'transcribe:' . $entryUid,
                priority: 3,
            );
        } catch (Throwable $e) {
            $this->logger->error('No se pudo encolar la transcripción', [
                'entry' => $entryUid,
                'error' => $e->getMessage(),
            ]);
        }

        // Nunca la transcripción ni el contenido: solo identificadores.
        $this->logger->info('Captura recibida', [
            'user_id'  => $user->id,
            'entry'    => $entryUid,
            'bytes'    => $stored['bytes'],
            'has_mood' => $moodHint !== null,
        ]);

        return $this->capturaAceptada($entryUid, (string) $clock['local_date']);
    }

    /**
     * Respuesta de una captura aceptada.
     *
     * 202 y no 201: la grabación está guardada, pero el trabajo sobre ella
     * acaba de empezar. El usuario ya puede cerrar la aplicación.
     *
     * Una grabación repetida devuelve lo mismo, con `duplicate` a true: para la
     * cola del cliente el resultado es el mismo —ya está a salvo, bórrala— y
     * distinguirlas solo sirve para no mentir en los registros.
     */
    private function capturaAceptada(string $uid, string $localDate, bool $duplicada = false): Response
    {
        return Response::json([
            'uid'        => $uid,
            'local_date' => $localDate,
            'state'      => 'captured',
            'duplicate'  => $duplicada,
        ], 202);
    }

    /**
     * El testigo lo genera el cliente, así que se acota antes de tocar nada:
     * solo caracteres de identificador y una longitud razonable. Uno que no
     * cumpla no se rechaza —perder la grabación por eso sería absurdo—, se
     * ignora y esa subida deja de ser idempotente.
     */
    private function clientToken(?string $raw): ?string
    {
        $token = trim((string) $raw);

        return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token) === 1 ? $token : null;
    }

    private function moodHint(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $value = (int) $raw;

        // El toque es opcional: un valor imposible se descarta en silencio en
        // vez de tumbar una grabación que sí es válida.
        return $value >= 1 && $value <= 5 ? $value : null;
    }

    private function durationMs(?string $raw): ?int
    {
        if ($raw === null || ! is_numeric($raw)) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 && $value < 86_400_000 ? $value : null;
    }

    /**
     * Único sitio donde se construye un repositorio de datos de usuario, y solo
     * a partir del usuario que la sesión ha resuelto.
     */
    private function entriesFor(?User $user): EntryRepository
    {
        assert($user !== null, 'Una ruta privada siempre llega con usuario resuelto');

        return new EntryRepository($this->pdo, $user->id);
    }

    /**
     * A dónde lleva el enlace de «volver».
     *
     * Volver es volver **de donde vengo**, no ir siempre al mismo sitio. Si
     * entré desde la pantalla de grabar, ahí es donde quiero acabar; si entré
     * desde el listado, al listado.
     *
     * Se mira la cabecera Referer y solo se aceptan rutas propias y conocidas:
     * es un dato que manda el navegador y no se puede usar para mandar a nadie
     * a donde diga un tercero.
     */
    private function dondeVolver(Request $request): string
    {
        $referer = (string) ($request->header('referer') ?? '');

        if ($referer === '') {
            return '/';
        }

        // El host también, no solo la ruta: un Referer de otro sitio que
        // acabara en /grabaciones no dice nada sobre de dónde viene esta
        // persona. Lo cazó un test.
        //
        // Se compara contra el host de **esta petición** y no contra APP_URL:
        // son el mismo en producción, pero en desarrollo se entra por
        // 127.0.0.1 mientras APP_URL dice localhost, y entonces «volver»
        // dejaba de funcionar sin que nada lo dijera. Aquí no hay riesgo:
        // esto solo elige entre dos rutas propias.
        $host = parse_url($referer, PHP_URL_HOST);

        if ($host !== null && $host !== explode(':', (string) $request->header('host'))[0]) {
            return '/';
        }

        return parse_url($referer, PHP_URL_PATH) === '/grabaciones' ? '/grabaciones' : '/';
    }

    private function transcriptsFor(?User $user): TranscriptRepository
    {
        assert($user !== null, 'Una ruta privada siempre llega con usuario resuelto');

        return new TranscriptRepository($this->pdo, $user->id);
    }

    private function startSessionAndRedirect(User $user, Request $request): Response
    {
        $session = $this->sessions->start(
            $user->id,
            $request->ip,
            $request->header('user-agent'),
        );

        return Response::redirect('/')
            ->withCookie(
                SessionManager::COOKIE,
                $session['token'],
                $this->sessions->cookieOptions($session['expiresAt'], $this->secureCookies),
            )
            // La cookie CSRF anónima ya no sirve para nada.
            ->withCookie(Csrf::ANON_COOKIE, '', [
                'expires' => time() - 3600, 'path' => '/',
            ]);
    }
}
