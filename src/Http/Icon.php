<?php

declare(strict_types=1);

namespace MaiMind\Http;

use MaiMind\Support\Config;
use RuntimeException;

/**
 * Iconos Phosphor, insertados en línea.
 *
 * Se insertan en el HTML en vez de servirse como fichero por tres razones:
 * heredan `currentColor` y por tanto siguen al tema claro/oscuro sin CSS extra,
 * no cuestan una petición cada uno, y no dependen de nada externo — la CSP es
 * `default-src 'self'` y no va a relajarse.
 *
 * **Nunca se usan emoji en su lugar.** Un emoji lo dibuja el sistema operativo:
 * cambia de forma y de color entre plataformas, mete color donde el diseño no
 * lo quiere, y en una app sobre estados de ánimo una carita amarilla propone
 * una emoción que el usuario no ha dicho.
 *
 * Fuente: https://github.com/phosphor-icons/core (MIT) · peso Light.
 */
final class Icon
{
    /** @var array<string,string> */
    private static array $cache = [];

    private const SIZE_DEFAULT = 20;

    /**
     * @param  array<string,string|int>  $attrs  atributos extra del <svg>
     */
    public static function render(string $name, int $size = self::SIZE_DEFAULT, array $attrs = []): string
    {
        $svg = self::load($name);

        $attributes = [
            'width'       => (string) $size,
            'height'      => (string) $size,
            'aria-hidden' => 'true',
            'focusable'   => 'false',
            ...array_map(strval(...), $attrs),
        ];

        // Un icono con etiqueta accesible deja de estar oculto para el lector.
        if (isset($attrs['aria-label'])) {
            unset($attributes['aria-hidden']);
            $attributes['role'] = 'img';
        }

        $rendered = '';

        foreach ($attributes as $key => $value) {
            $rendered .= sprintf(' %s="%s"', $key, htmlspecialchars($value, ENT_QUOTES));
        }

        return (string) preg_replace('/^<svg/', '<svg' . $rendered, $svg, 1);
    }

    private static function load(string $name): string
    {
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }

        if (preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
            throw new RuntimeException('Nombre de icono no válido: ' . $name);
        }

        $file = Config::basePath('resources/icons/' . $name . '.svg');

        if (! is_file($file)) {
            throw new RuntimeException(
                "No existe el icono «{$name}». Descárgalo de phosphor-icons/core "
                . '(peso light) a resources/icons/.'
            );
        }

        return self::$cache[$name] = trim((string) file_get_contents($file));
    }

    /** @return list<string> Iconos disponibles. */
    public static function available(): array
    {
        $files = glob(Config::basePath('resources/icons/*.svg')) ?: [];

        return array_map(static fn (string $f) => basename($f, '.svg'), $files);
    }
}
