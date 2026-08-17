<?php

declare(strict_types=1);

namespace MaiMind\Domain\Auth;

use RuntimeException;

/**
 * Hash de contraseñas con Argon2id.
 *
 * Verificado disponible en local y en producción (PHP 8.3/8.4 con libargon2).
 * Si algún día no lo estuviera, es mejor que la aplicación no arranque a que
 * caiga en silencio a bcrypt.
 */
final class PasswordHasher
{
    /** Coste de memoria en KiB. 64 MiB por hash. */
    private const MEMORY_COST = 65536;

    private const TIME_COST = 4;

    private const THREADS = 1;

    public const MIN_LENGTH = 10;

    public function __construct()
    {
        if (! defined('PASSWORD_ARGON2ID')) {
            throw new RuntimeException(
                'Este PHP no tiene Argon2id. No se va a degradar a otro algoritmo en silencio.'
            );
        }
    }

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => self::MEMORY_COST,
            'time_cost'   => self::TIME_COST,
            'threads'     => self::THREADS,
        ]);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /** ¿Hay que recalcular el hash porque han subido los parámetros? */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
            'memory_cost' => self::MEMORY_COST,
            'time_cost'   => self::TIME_COST,
            'threads'     => self::THREADS,
        ]);
    }

    /**
     * Consume el mismo tiempo que una verificación real.
     *
     * Se usa cuando el correo no existe: sin esto, un atacante distingue
     * "correo desconocido" de "contraseña incorrecta" cronometrando la
     * respuesta, y eso convierte el formulario en un buscador de usuarios.
     */
    public function wasteTime(): void
    {
        static $dummy = null;

        $dummy ??= $this->hash('contraseña que no es de nadie');

        password_verify('da igual lo que se ponga aquí', $dummy);
    }
}
