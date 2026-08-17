<?php

declare(strict_types=1);

namespace MaiMind\Domain\Auth;

use PDO;

/**
 * Freno de fuerza bruta.
 *
 * Cuenta por correo y por IP a la vez: por correo para que no machaquen una
 * cuenta concreta desde muchos sitios, por IP para que no barran muchas cuentas
 * desde un sitio.
 *
 * Solo se guardan hashes: esta tabla no debe convertirse en un registro de
 * quién intentó entrar y desde dónde.
 */
final class LoginThrottle
{
    private const MAX_ATTEMPTS = 5;

    private const WINDOW_MINUTES = 15;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isBlocked(string $email, string $ip): bool
    {
        return $this->recentFailures($email, 'email') >= self::MAX_ATTEMPTS
            || $this->recentFailures($ip, 'ip') >= self::MAX_ATTEMPTS * 4;
    }

    public function recordFailure(string $email, string $ip): void
    {
        $this->record($email, 'email', false);
        $this->record($ip, 'ip', false);
    }

    /** Un acceso correcto limpia el contador de ese correo. */
    public function recordSuccess(string $email, string $ip): void
    {
        $this->record($email, 'email', true);

        $this->pdo->prepare(
            "DELETE FROM login_attempts WHERE identifier_hash = ? AND kind = 'email' AND successful = 0"
        )->execute([$this->fingerprint($email)]);
    }

    public function secondsUntilRetry(string $email): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT attempted_at FROM login_attempts
              WHERE identifier_hash = ? AND kind = 'email' AND successful = 0
              ORDER BY attempted_at DESC LIMIT 1"
        );
        $stmt->execute([$this->fingerprint($email)]);

        $last = $stmt->fetchColumn();

        if ($last === false) {
            return 0;
        }

        $retryAt = strtotime((string) $last . ' UTC') + self::WINDOW_MINUTES * 60;

        return max(0, $retryAt - time());
    }

    public function purgeOld(int $days = 1): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(3), INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);

        return $stmt->rowCount();
    }

    private function recentFailures(string $identifier, string $kind): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE identifier_hash = ? AND kind = ? AND successful = 0
                AND attempted_at > DATE_SUB(NOW(3), INTERVAL ? MINUTE)'
        );

        $stmt->execute([$this->fingerprint($identifier), $kind, self::WINDOW_MINUTES]);

        return (int) $stmt->fetchColumn();
    }

    private function record(string $identifier, string $kind, bool $successful): void
    {
        $this->pdo->prepare(
            'INSERT INTO login_attempts (identifier_hash, kind, successful) VALUES (?, ?, ?)'
        )->execute([$this->fingerprint($identifier), $kind, (int) $successful]);
    }

    private function fingerprint(string $identifier): string
    {
        return hash('sha256', mb_strtolower(trim($identifier)));
    }
}
