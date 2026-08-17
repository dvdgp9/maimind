<?php

declare(strict_types=1);

namespace MaiMind\Http;

/**
 * Protección contra peticiones falsificadas desde otro sitio.
 *
 * Dos modos, porque los formularios de acceso todavía no tienen sesión:
 *
 *  - **Con sesión**: el testigo se deriva de la sesión con HMAC y la clave de
 *    la aplicación. No hace falta guardarlo en ningún sitio y un tercero no
 *    puede calcularlo.
 *  - **Sin sesión** (acceso y registro): doble envío — el mismo valor aleatorio
 *    en una cookie y en el formulario, y se comparan.
 */
final class Csrf
{
    public const FIELD = '_csrf';

    public const ANON_COOKIE = 'maimind_csrf';

    public static function forSession(string $sessionFingerprint, string $appKey): string
    {
        return hash_hmac('sha256', 'csrf:' . $sessionFingerprint, $appKey);
    }

    public static function newAnonymous(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function matches(?string $submitted, ?string $expected): bool
    {
        if ($submitted === null || $expected === null || $submitted === '' || $expected === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }

    /** @return array<string,mixed> */
    public static function anonymousCookieOptions(bool $secure): array
    {
        return [
            'expires'  => time() + 7200,
            'path'     => '/',
            'secure'   => $secure,
            // Legible por JavaScript a propósito: el envío doble necesita que el
            // cliente pueda reenviarlo en peticiones fetch.
            'httponly' => false,
            'samesite' => 'Lax',
        ];
    }
}
