<?php

declare(strict_types=1);

namespace MaiMind\Support;

/**
 * Lector de ficheros .env deliberadamente simple.
 *
 * Soporta: CLAVE=valor, comillas simples y dobles, comentarios con # al principio
 * de línea o tras el valor, y líneas en blanco.
 *
 * NO soporta: valores multilínea, interpolación de variables (${OTRA}), ni escapes
 * dentro de comillas. Si algún día hace falta algo de eso, es el momento de traer
 * vlucas/phpdotenv en lugar de ampliar esto.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];

    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (! is_file($path)) {
            self::$loaded = true;

            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key = trim($key);
            $value = trim($value);

            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $end = strpos($value, $quote, 1);
                $value = $end === false
                    ? substr($value, 1)
                    : substr($value, 1, $end - 1);
            } elseif (($hash = strpos($value, ' #')) !== false) {
                $value = rtrim(substr($value, 0, $hash));
            }

            self::$vars[$key] = $value;
        }

        self::$loaded = true;
    }

    /**
     * Devuelve la variable convertida a su tipo natural.
     * "true"/"false" → bool, "null"/"" → null, numérico → int|float.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$vars[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true'           => true,
            'false'          => false,
            'null', 'empty', '' => $default,
            default          => self::cast((string) $value),
        };
    }

    private static function cast(string $value): int|float|string
    {
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (preg_match('/^-?\d*\.\d+$/', $value) === 1) {
            return (float) $value;
        }

        return $value;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }
}
