<?php

declare(strict_types=1);

namespace MaiMind\Http;

use MaiMind\Support\Config;
use RuntimeException;

final class View
{
    /**
     * @param  array<string,mixed>  $data
     */
    public static function render(string $name, array $data = [], string $layout = 'layout'): string
    {
        $content = self::capture($name, $data);

        return self::capture($layout, [...$data, 'content' => $content]);
    }

    /** @param array<string,mixed> $data */
    private static function capture(string $name, array $data): string
    {
        $file = Config::basePath('resources/views/' . $name . '.php');

        if (! is_file($file)) {
            throw new RuntimeException('No existe la vista: ' . $name);
        }

        extract($data, EXTR_SKIP);

        ob_start();

        require $file;

        return (string) ob_get_clean();
    }
}
