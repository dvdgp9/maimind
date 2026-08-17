<?php

declare(strict_types=1);

use MaiMind\Support\Config;
use MaiMind\Support\Env;
use MaiMind\Support\Lang;

if (! function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (! function_exists('env')) {
    /**
     * Solo debe usarse dentro de config/*.php. En el resto del código se lee
     * la configuración con config(), para que exista un único punto de verdad.
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (! function_exists('base_path')) {
    function base_path(string $append = ''): string
    {
        return Config::basePath($append);
    }
}

if (! function_exists('t')) {
    /**
     * Traduce una clave de interfaz.
     *
     * NO usar para slugs, enums ni nombres de catálogo: los primeros son
     * identificadores y los segundos viven en columnas *_i18n de la base de datos.
     *
     * @param  array<string,string|int|float>  $params
     */
    function t(string $key, array $params = [], ?string $locale = null): string
    {
        return Lang::get($key, $params, $locale);
    }
}

if (! function_exists('icon')) {
    /**
     * Inserta un icono Phosphor en línea. Hereda currentColor.
     *
     * Nunca usar un emoji en su lugar: lo dibuja el sistema operativo, cambia
     * de forma entre plataformas y mete color donde el diseño no lo quiere.
     *
     * @param  array<string,string|int>  $attrs
     */
    function icon(string $name, int $size = 20, array $attrs = []): string
    {
        return MaiMind\Http\Icon::render($name, $size, $attrs);
    }
}

if (! function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
