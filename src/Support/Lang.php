<?php

declare(strict_types=1);

namespace MaiMind\Support;

/**
 * Traducción de textos de interfaz.
 *
 * Reglas del proyecto (ver docs/design/04-arquitectura.md §4.bis):
 *  - Los slugs y enums NUNCA pasan por aquí: son identificadores, no texto.
 *  - Los nombres de catálogo (variables, tags) viven en la base de datos, en
 *    columnas *_i18n, no en estos ficheros.
 *  - Aquí solo va la interfaz.
 */
final class Lang
{
    /** @var array<string,array<string,mixed>> */
    private static array $loaded = [];

    private static string $locale = 'es';

    private static string $fallback = 'es';

    private static string $path = '';

    public static function boot(string $path, string $locale, string $fallback = 'es'): void
    {
        self::$path = rtrim($path, '/');
        self::$locale = $locale;
        self::$fallback = $fallback;
        self::$loaded = [];
    }

    public static function setLocale(string $locale): void
    {
        self::$locale = $locale;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /**
     * Resuelve el locale activo: preferencia explícita del usuario, luego
     * Accept-Language, luego el de por defecto.
     *
     * @param  list<string>  $supported
     */
    public static function resolve(?string $userLocale, ?string $acceptLanguage, array $supported): string
    {
        if ($userLocale !== null && in_array($userLocale, $supported, true)) {
            return $userLocale;
        }

        foreach (self::parseAcceptLanguage($acceptLanguage ?? '') as $candidate) {
            if (in_array($candidate, $supported, true)) {
                return $candidate;
            }

            $short = substr($candidate, 0, 2);

            if (in_array($short, $supported, true)) {
                return $short;
            }
        }

        return self::$fallback;
    }

    /**
     * @param  array<string,string|int|float>  $params
     */
    public static function get(string $key, array $params = [], ?string $locale = null): string
    {
        $locale ??= self::$locale;

        $line = self::lookup($key, $locale);

        if ($line === null && $locale !== self::$fallback) {
            $line = self::lookup($key, self::$fallback);
        }

        // Si no hay traducción devolvemos la clave: es visible en pantalla y por
        // tanto fácil de detectar, que es justo lo que queremos.
        $line ??= $key;

        foreach ($params as $name => $value) {
            $line = str_replace(':' . $name, (string) $value, $line);
        }

        return $line;
    }

    private static function lookup(string $key, string $locale): ?string
    {
        $lines = self::loadLocale($locale);

        $value = $lines;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return is_string($value) ? $value : null;
    }

    /** @return array<string,mixed> */
    private static function loadLocale(string $locale): array
    {
        if (isset(self::$loaded[$locale])) {
            return self::$loaded[$locale];
        }

        $file = self::$path . '/' . $locale . '.php';

        self::$loaded[$locale] = is_file($file) ? (array) require $file : [];

        return self::$loaded[$locale];
    }

    /** @return list<string> */
    private static function parseAcceptLanguage(string $header): array
    {
        if (trim($header) === '') {
            return [];
        }

        $entries = [];

        foreach (explode(',', $header) as $part) {
            $bits = explode(';q=', trim($part));
            $tag = strtolower(trim($bits[0]));

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $entries[$tag] = isset($bits[1]) ? (float) $bits[1] : 1.0;
        }

        arsort($entries);

        return array_keys($entries);
    }
}
