<?php

declare(strict_types=1);

namespace MaiMind\Support;

use RuntimeException;

/**
 * Configuración de la aplicación.
 *
 * Carga todos los ficheros de config/ y los expone con notación de puntos:
 *   config('database.connection.host')  →  config/database.php ['connection']['host']
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    private static bool $loaded = false;

    private static string $basePath = '';

    public static function boot(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/');

        Env::load(self::$basePath . '/.env');

        $dir = self::$basePath . '/config';

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            self::$items[$key] = require $file;
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (! self::$loaded) {
            throw new RuntimeException('Config::boot() no se ha llamado todavía.');
        }

        $value = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public static function basePath(string $append = ''): string
    {
        return self::$basePath . ($append === '' ? '' : '/' . ltrim($append, '/'));
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return self::$items;
    }
}
