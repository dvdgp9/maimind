<?php

declare(strict_types=1);

namespace MaiMind\Repository;

use MaiMind\Domain\User;
use MaiMind\Support\Ulid;
use PDO;

/**
 * Acceso a la tabla de identidad. No extiende UserScopedRepository porque es
 * precisamente la tabla que define el ámbito.
 */
final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([self::normalizeEmail($email)]);

        $row = $stmt->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    public function passwordHashFor(int $userId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        $hash = $stmt->fetchColumn();

        return $hash === false ? null : (string) $hash;
    }

    public function create(
        string $email,
        string $passwordHash,
        ?string $displayName = null,
        string $timezone = 'Europe/Madrid',
        string $locale = 'es',
    ): User {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (uid, email, password_hash, display_name, timezone, locale)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            Ulid::generate(),
            self::normalizeEmail($email),
            $passwordHash,
            $displayName,
            $timezone,
            $locale,
        ]);

        $user = $this->findById((int) $this->pdo->lastInsertId());

        assert($user !== null);

        return $user;
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$hash, $userId]);
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([self::normalizeEmail($email)]);

        return $stmt->fetchColumn() !== false;
    }
}
