<?php

declare(strict_types=1);

namespace MaiMind\Tests;

use MaiMind\Domain\Auth\Authenticator;
use MaiMind\Domain\Auth\LoginThrottle;
use MaiMind\Domain\Auth\PasswordHasher;
use MaiMind\Domain\Auth\SessionManager;
use MaiMind\Domain\User;
use MaiMind\Http\Csrf;
use MaiMind\Http\Kernel;
use MaiMind\Http\Request;
use MaiMind\Http\Response;
use MaiMind\Repository\UserRepository;
use MaiMind\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Base para los tests que necesitan la aplicación entera en pie.
 *
 * Despacha peticiones por el Kernel real, con el enrutado, la resolución de
 * sesión y la comprobación CSRF de verdad. Probar el aislamiento contra
 * repositorios sueltos no demostraría nada: el criterio es que no se pueda
 * llegar a los datos de otro **por ninguna ruta**.
 */
abstract class AppTestCase extends TestCase
{
    protected PDO $pdo;

    protected Kernel $kernel;

    protected SessionManager $sessions;

    protected Authenticator $auth;

    protected UserRepository $users;

    protected const APP_KEY = 'clave-de-pruebas-no-usar-en-produccion';

    /** Los correos de prueba llevan este prefijo para poder limpiarlos. */
    protected const EMAIL_PREFIX = 'test-aislamiento-';

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::connection();
        } catch (Throwable $e) {
            $this->markTestSkipped('Sin base de datos: ' . $e->getMessage());
        }

        $existe = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts'"
        )->fetchColumn();

        if ($existe === 0) {
            $this->markTestSkipped('Esquema incompleto. Ejecuta: php bin/migrate');
        }

        $this->users    = new UserRepository($this->pdo);
        $this->sessions = new SessionManager($this->pdo, $this->users);

        $this->auth = new Authenticator(
            $this->users,
            new PasswordHasher(),
            $this->sessions,
            new LoginThrottle($this->pdo),
        );

        $this->kernel = new Kernel(
            pdo: $this->pdo,
            logger: new NullLogger(),
            appKey: self::APP_KEY,
        );

        $this->limpiar();
    }

    protected function tearDown(): void
    {
        $this->limpiar();
    }

    private function limpiar(): void
    {
        $ids = $this->pdo
            ->query("SELECT id FROM users WHERE email LIKE '" . self::EMAIL_PREFIX . "%'")
            ->fetchAll(PDO::FETCH_COLUMN);

        if ($ids === []) {
            return;
        }

        $marcas = implode(',', array_fill(0, count($ids), '?'));

        // Orden importante: las claves foráneas a users son RESTRICT a propósito,
        // para que un borrado accidental no se lleve por delante años de datos.
        foreach (['entries', 'sessions'] as $tabla) {
            $this->pdo
                ->prepare("DELETE FROM {$tabla} WHERE user_id IN ({$marcas})")
                ->execute($ids);
        }

        $this->pdo->prepare("DELETE FROM users WHERE id IN ({$marcas})")->execute($ids);
        $this->pdo->exec('DELETE FROM login_attempts');
    }

    protected function crearUsuario(string $sufijo, string $password = 'contraseña-larga-123'): User
    {
        $resultado = $this->auth->register(
            email: self::EMAIL_PREFIX . $sufijo . '@ejemplo.test',
            password: $password,
        );

        $this->assertTrue($resultado['ok'], 'No se pudo crear el usuario de prueba');

        return $resultado['user'];
    }

    /** @return string testigo de sesión para usar como cookie */
    protected function iniciarSesion(User $user): string
    {
        return $this->sessions->start($user->id)['token'];
    }

    protected function csrfDeSesion(string $token): string
    {
        return Csrf::forSession($this->sessions->fingerprint($token), self::APP_KEY);
    }

    /** Crea un registro de captura directamente, sin pasar por la API. */
    protected function crearEntrada(User $user, string $fecha = '2026-08-16'): string
    {
        $repo = new \MaiMind\Repository\EntryRepository($this->pdo, $user->id);

        return $repo->createDraft(
            capturedAt: $fecha . ' 12:00:00',
            localDate: $fecha,
            timezone: 'Europe/Madrid',
            utcOffsetMinutes: 120,
        );
    }

    /** @param array<string,string> $cookies */
    protected function get(string $path, array $cookies = [], array $headers = []): Response
    {
        return $this->kernel->handle(new Request(
            method: 'GET',
            path: $path,
            cookies: $cookies,
            headers: $headers,
        ));
    }

    /**
     * @param  array<string,mixed>   $body
     * @param  array<string,string>  $cookies
     */
    protected function post(
        string $path,
        array $body = [],
        array $cookies = [],
        string $ip = '127.0.0.1',
    ): Response {
        return $this->kernel->handle(new Request(
            method: 'POST',
            path: $path,
            body: $body,
            cookies: $cookies,
            ip: $ip,
        ));
    }

    /** GET autenticado con el testigo de sesión ya puesto en la cookie. */
    protected function getComo(string $sessionToken, string $path): Response
    {
        return $this->get($path, [SessionManager::COOKIE => $sessionToken]);
    }
}
