<?php

declare(strict_types=1);

namespace MaiMind\Domain\Auth;

use MaiMind\Domain\User;
use MaiMind\Repository\UserRepository;

/**
 * Registro y acceso.
 *
 * Devuelve resultados, no excepciones, porque un error de credenciales es un
 * caso normal del flujo y no una condición excepcional.
 */
final class Authenticator
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly LoginThrottle $throttle,
    ) {
    }

    /**
     * @return array{ok:bool, user?:User, error?:string, field?:string}
     */
    public function register(
        string $email,
        string $password,
        ?string $displayName = null,
        string $timezone = 'Europe/Madrid',
        string $locale = 'es',
    ): array {
        $email = UserRepository::normalizeEmail($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'auth.invalid_email', 'field' => 'email'];
        }

        if (mb_strlen($password) < PasswordHasher::MIN_LENGTH) {
            return ['ok' => false, 'error' => 'auth.password_too_short', 'field' => 'password'];
        }

        if (mb_strtolower($password) === $email) {
            return ['ok' => false, 'error' => 'auth.password_is_email', 'field' => 'password'];
        }

        if ($this->users->emailExists($email)) {
            return ['ok' => false, 'error' => 'auth.email_taken', 'field' => 'email'];
        }

        $user = $this->users->create(
            email: $email,
            passwordHash: $this->hasher->hash($password),
            displayName: $displayName !== null && trim($displayName) !== '' ? trim($displayName) : null,
            timezone: $timezone,
            locale: $locale,
        );

        return ['ok' => true, 'user' => $user];
    }

    /**
     * @return array{ok:bool, user?:User, error?:string, retryAfter?:int}
     */
    public function attempt(string $email, string $password, string $ip): array
    {
        $email = UserRepository::normalizeEmail($email);

        if ($this->throttle->isBlocked($email, $ip)) {
            return [
                'ok'         => false,
                'error'      => 'auth.too_many_attempts',
                'retryAfter' => $this->throttle->secondsUntilRetry($email),
            ];
        }

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            // Mismo coste temporal que una verificación real: si no, el
            // formulario se convierte en un buscador de cuentas.
            $this->hasher->wasteTime();
            $this->throttle->recordFailure($email, $ip);

            return ['ok' => false, 'error' => 'auth.invalid_credentials'];
        }

        $hash = $this->users->passwordHashFor($user->id);

        if ($hash === null || ! $this->hasher->verify($password, $hash)) {
            $this->throttle->recordFailure($email, $ip);

            // Mismo mensaje que con correo desconocido: no se confirma si la
            // cuenta existe.
            return ['ok' => false, 'error' => 'auth.invalid_credentials'];
        }

        if (! $user->isActive()) {
            $this->throttle->recordFailure($email, $ip);

            return ['ok' => false, 'error' => 'auth.account_inactive'];
        }

        // Si han subido los parámetros de Argon2, se recalcula aprovechando que
        // la contraseña en claro está disponible justo aquí y en ningún otro sitio.
        if ($this->hasher->needsRehash($hash)) {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password));
        }

        $this->throttle->recordSuccess($email, $ip);

        return ['ok' => true, 'user' => $user];
    }
}
