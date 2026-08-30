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
use MaiMind\Repository\UserRepository;
use MaiMind\Support\Config;
use MaiMind\Support\Lang;
use MaiMind\Support\Ulid;
use PDO;
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

        $entries = $this->entriesFor($user);
        $store   = new AudioStore(Config::basePath((string) config('app.paths.storage')));

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
        );

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

        // 202 y no 201: la grabación está guardada, pero el trabajo sobre ella
        // acaba de empezar. El usuario ya puede cerrar la aplicación.
        return Response::json([
            'uid'        => $entryUid,
            'local_date' => $clock['local_date'],
            'state'      => 'captured',
        ], 202);
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
