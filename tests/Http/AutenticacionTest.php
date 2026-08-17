<?php

declare(strict_types=1);

namespace MaiMind\Tests\Http;

use MaiMind\Domain\Auth\PasswordHasher;
use MaiMind\Domain\Auth\SessionManager;
use MaiMind\Http\Csrf;
use MaiMind\Tests\AppTestCase;

final class AutenticacionTest extends AppTestCase
{
    // ------------------------------------------------------------- registro

    public function test_registra_y_deja_la_sesion_iniciada(): void
    {
        $csrf = Csrf::newAnonymous();

        $respuesta = $this->post('/registro', [
            'email'    => self::EMAIL_PREFIX . 'nuevo@ejemplo.test',
            'password' => 'una contraseña larga',
            '_csrf'    => $csrf,
        ], [Csrf::ANON_COOKIE => $csrf]);

        $this->assertSame(302, $respuesta->status);
        $this->assertSame('/', $respuesta->header('Location'));

        $cookie = array_values(array_filter(
            $respuesta->cookies(),
            static fn ($c) => $c['name'] === SessionManager::COOKIE,
        ))[0] ?? null;

        $this->assertNotNull($cookie);
        $this->assertSame(200, $this->getComo($cookie['value'], '/api/entries')->status);
    }

    public function test_la_cookie_de_sesion_es_httponly_y_samesite(): void
    {
        $csrf = Csrf::newAnonymous();

        $respuesta = $this->post('/registro', [
            'email'    => self::EMAIL_PREFIX . 'cookie@ejemplo.test',
            'password' => 'una contraseña larga',
            '_csrf'    => $csrf,
        ], [Csrf::ANON_COOKIE => $csrf]);

        $cookie = array_values(array_filter(
            $respuesta->cookies(),
            static fn ($c) => $c['name'] === SessionManager::COOKIE,
        ))[0];

        $this->assertTrue($cookie['options']['httponly']);
        $this->assertSame('Lax', $cookie['options']['samesite']);
        $this->assertSame('/', $cookie['options']['path']);
    }

    public function test_rechaza_contrasenas_cortas_y_correos_invalidos(): void
    {
        $casos = [
            ['correo-que-no-vale', 'una contraseña larga'],
            [self::EMAIL_PREFIX . 'x@ejemplo.test', 'corta'],
        ];

        foreach ($casos as [$email, $password]) {
            $csrf = Csrf::newAnonymous();

            $respuesta = $this->post('/registro', [
                'email' => $email, 'password' => $password, '_csrf' => $csrf,
            ], [Csrf::ANON_COOKIE => $csrf]);

            $this->assertSame(422, $respuesta->status, "Aceptado: {$email} / {$password}");
        }
    }

    public function test_no_permite_dos_cuentas_con_el_mismo_correo(): void
    {
        $this->crearUsuario('duplicado');

        $resultado = $this->auth->register(
            email: strtoupper(self::EMAIL_PREFIX . 'duplicado@ejemplo.test'),
            password: 'otra contraseña larga',
        );

        // Y el correo se normaliza: mayúsculas y espacios no crean una cuenta nueva.
        $this->assertFalse($resultado['ok']);
        $this->assertSame('auth.email_taken', $resultado['error']);
    }

    // --------------------------------------------------------------- acceso

    public function test_entra_con_las_credenciales_correctas(): void
    {
        $this->crearUsuario('acceso', 'contraseña correcta 1');

        $csrf = Csrf::newAnonymous();

        $respuesta = $this->post('/acceder', [
            'email'    => self::EMAIL_PREFIX . 'acceso@ejemplo.test',
            'password' => 'contraseña correcta 1',
            '_csrf'    => $csrf,
        ], [Csrf::ANON_COOKIE => $csrf]);

        $this->assertSame(302, $respuesta->status);
        $this->assertSame('/', $respuesta->header('Location'));
    }

    public function test_el_mensaje_de_error_no_revela_si_la_cuenta_existe(): void
    {
        $this->crearUsuario('existe', 'contraseña correcta 1');

        $existeMalaClave = $this->auth->attempt(
            self::EMAIL_PREFIX . 'existe@ejemplo.test', 'clave equivocada', '10.0.0.1'
        );

        $noExiste = $this->auth->attempt(
            self::EMAIL_PREFIX . 'no-existe@ejemplo.test', 'clave equivocada', '10.0.0.2'
        );

        $this->assertFalse($existeMalaClave['ok']);
        $this->assertFalse($noExiste['ok']);
        $this->assertSame($existeMalaClave['error'], $noExiste['error']);
    }

    public function test_frena_la_fuerza_bruta(): void
    {
        $this->crearUsuario('fuerza', 'contraseña correcta 1');

        $email = self::EMAIL_PREFIX . 'fuerza@ejemplo.test';

        for ($i = 0; $i < 5; $i++) {
            $this->auth->attempt($email, 'clave equivocada', '10.0.0.9');
        }

        // Ahora ni siquiera la contraseña buena entra.
        $bloqueado = $this->auth->attempt($email, 'contraseña correcta 1', '10.0.0.9');

        $this->assertFalse($bloqueado['ok']);
        $this->assertSame('auth.too_many_attempts', $bloqueado['error']);
        $this->assertGreaterThan(0, $bloqueado['retryAfter']);
    }

    public function test_un_acceso_correcto_limpia_el_contador(): void
    {
        $this->crearUsuario('limpia', 'contraseña correcta 1');

        $email = self::EMAIL_PREFIX . 'limpia@ejemplo.test';

        for ($i = 0; $i < 3; $i++) {
            $this->auth->attempt($email, 'clave equivocada', '10.0.0.7');
        }

        $this->assertTrue($this->auth->attempt($email, 'contraseña correcta 1', '10.0.0.7')['ok']);

        for ($i = 0; $i < 4; $i++) {
            $this->auth->attempt($email, 'clave equivocada', '10.0.0.7');
        }

        // Si el contador no se hubiera limpiado, 3+4 ya pasarían del límite.
        $this->assertTrue($this->auth->attempt($email, 'contraseña correcta 1', '10.0.0.7')['ok']);
    }

    // ----------------------------------------------------------------- CSRF

    public function test_un_post_sin_testigo_csrf_se_rechaza(): void
    {
        $a     = $this->crearUsuario('csrf');
        $token = $this->iniciarSesion($a);

        $respuesta = $this->post('/salir', [], [SessionManager::COOKIE => $token]);

        $this->assertSame(419, $respuesta->status);
        $this->assertSame(200, $this->getComo($token, '/api/entries')->status, 'La sesión sigue viva');
    }

    public function test_un_testigo_csrf_de_otra_sesion_no_vale(): void
    {
        $a = $this->crearUsuario('a');
        $b = $this->crearUsuario('b');

        $tokenA = $this->iniciarSesion($a);
        $tokenB = $this->iniciarSesion($b);

        $respuesta = $this->post('/salir', ['_csrf' => $this->csrfDeSesion($tokenB)], [
            SessionManager::COOKIE => $tokenA,
        ]);

        $this->assertSame(419, $respuesta->status);
        $this->assertSame(200, $this->getComo($tokenA, '/api/entries')->status);
    }

    public function test_el_registro_sin_cookie_csrf_se_rechaza(): void
    {
        $respuesta = $this->post('/registro', [
            'email'    => self::EMAIL_PREFIX . 'sincsrf@ejemplo.test',
            'password' => 'una contraseña larga',
            '_csrf'    => Csrf::newAnonymous(),
        ]);

        $this->assertSame(419, $respuesta->status);
        $this->assertFalse($this->users->emailExists(self::EMAIL_PREFIX . 'sincsrf@ejemplo.test'));
    }

    // ------------------------------------------------------------ contraseñas

    public function test_las_contrasenas_se_guardan_con_argon2id(): void
    {
        $user = $this->crearUsuario('argon');

        $hash = $this->users->passwordHashFor($user->id);

        $this->assertStringStartsWith('$argon2id$', (string) $hash);
        $this->assertStringNotContainsString('contraseña-larga-123', (string) $hash);
    }

    public function test_dos_usuarios_con_la_misma_clave_tienen_hashes_distintos(): void
    {
        $a = $this->crearUsuario('sal-a', 'la misma contraseña');
        $b = $this->crearUsuario('sal-b', 'la misma contraseña');

        $this->assertNotSame(
            $this->users->passwordHashFor($a->id),
            $this->users->passwordHashFor($b->id),
        );
    }

    public function test_el_verificador_acepta_la_buena_y_rechaza_las_demas(): void
    {
        $hasher = new PasswordHasher();
        $hash   = $hasher->hash('la contraseña buena');

        $this->assertTrue($hasher->verify('la contraseña buena', $hash));
        $this->assertFalse($hasher->verify('la contraseña mala', $hash));
        $this->assertFalse($hasher->verify('', $hash));
    }

    // --------------------------------------------------------------- rutas

    public function test_una_ruta_inexistente_da_404_y_un_metodo_erroneo_da_405(): void
    {
        $this->assertSame(404, $this->get('/api/no-existe')->status);
        $this->assertSame(405, $this->get('/salir')->status);
    }

    public function test_las_respuestas_llevan_cabeceras_de_seguridad(): void
    {
        $cabeceras = \MaiMind\Http\Response::securityHeaders();

        $this->assertSame('nosniff', $cabeceras['X-Content-Type-Options']);
        $this->assertSame('DENY', $cabeceras['X-Frame-Options']);
        $this->assertSame('same-origin', $cabeceras['Referrer-Policy']);

        $csp = $cabeceras['Content-Security-Policy'];

        // La aplicación no carga nada de terceros y no debe empezar por accidente.
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        // Pero la grabación del navegador necesita blob: en media.
        $this->assertStringContainsString('media-src \'self\' blob:', $csp);
    }
}
