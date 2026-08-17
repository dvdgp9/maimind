<?php

declare(strict_types=1);

namespace MaiMind\Domain\Auth;

use MaiMind\Domain\User;
use MaiMind\Repository\UserRepository;
use PDO;

/**
 * Sesiones en base de datos.
 *
 * El navegador recibe un testigo aleatorio; la base guarda solo su SHA-256.
 * Así, quien consiga una copia de la tabla `sessions` no puede suplantar a
 * nadie: tendría los hashes, no los testigos.
 */
final class SessionManager
{
    public const COOKIE = 'maimind_session';

    private const LIFETIME_DAYS = 30;

    /** Se refresca la caducidad como mucho una vez al día, para no escribir en cada petición. */
    private const REFRESH_AFTER_SECONDS = 86400;

    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return array{token:string,expiresAt:int}
     */
    public function start(int $userId, ?string $ip = null, ?string $userAgent = null): array
    {
        $token   = bin2hex(random_bytes(32));
        $expires = time() + self::LIFETIME_DAYS * 86400;

        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (id, user_id, ip_hash, user_agent, expires_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, NOW(3))'
        );

        $stmt->execute([
            $this->fingerprint($token),
            $userId,
            $ip !== null ? hash('sha256', $ip) : null,
            $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            gmdate('Y-m-d H:i:s', $expires),
        ]);

        return ['token' => $token, 'expiresAt' => $expires];
    }

    public function resolve(?string $token): ?User
    {
        if ($token === null || strlen($token) !== 64 || ctype_xdigit($token) === false) {
            return null;
        }

        $id = $this->fingerprint($token);

        $stmt = $this->pdo->prepare(
            'SELECT user_id, last_seen_at FROM sessions WHERE id = ? AND expires_at > NOW(3) LIMIT 1'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $user = $this->users->findById((int) $row['user_id']);

        if ($user === null || ! $user->isActive()) {
            // La cuenta ya no vale: la sesión tampoco.
            $this->destroy($token);

            return null;
        }

        $this->touch($id, $row['last_seen_at'] === null ? null : (string) $row['last_seen_at']);

        return $user;
    }

    public function destroy(?string $token): void
    {
        if ($token === null) {
            return;
        }

        $this->pdo->prepare('DELETE FROM sessions WHERE id = ?')
            ->execute([$this->fingerprint($token)]);
    }

    /** Cierra todas las sesiones del usuario. Se usa al cambiar la contraseña. */
    public function destroyAllFor(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);
    }

    public function purgeExpired(): int
    {
        $stmt = $this->pdo->query('DELETE FROM sessions WHERE expires_at <= NOW(3)');

        return $stmt === false ? 0 : $stmt->rowCount();
    }

    /** Identificador de sesión para derivar el testigo CSRF. */
    public function fingerprint(string $token): string
    {
        return hash('sha256', $token);
    }

    private function touch(string $id, ?string $lastSeen): void
    {
        if ($lastSeen !== null && (time() - strtotime($lastSeen . ' UTC')) < self::REFRESH_AFTER_SECONDS) {
            return;
        }

        $this->pdo->prepare(
            'UPDATE sessions SET last_seen_at = NOW(3), expires_at = ? WHERE id = ?'
        )->execute([
            gmdate('Y-m-d H:i:s', time() + self::LIFETIME_DAYS * 86400),
            $id,
        ]);
    }

    /** @return array<string,mixed> */
    public function cookieOptions(int $expiresAt, bool $secure): array
    {
        return [
            'expires'  => $expiresAt,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
