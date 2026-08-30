<?php

declare(strict_types=1);

namespace MaiMind\Support;

use RuntimeException;

/**
 * Huella de todo lo que el service worker sirve desde su propia caché.
 *
 * Es la versión de la caché del cliente. Cambia cuando cambia el contenido, y
 * solo entonces: dos despliegues del mismo código dan la misma huella y no
 * tiran la caché de nadie sin motivo.
 *
 * **Por qué se calcula y no se escribe.** Antes era un `'v1'` a mano en sw.js.
 * Olvidarse de subirlo dejaba a los teléfonos ya instalados con el CSS y el
 * JavaScript viejos para siempre, sin que en el servidor se viera nada
 * anormal: el despliegue habría ido bien. Eso no es un error que se pueda
 * evitar acordándose.
 */
final class AssetVersion
{
    /**
     * Todo lo que el service worker precarga o guarda, y nada más.
     *
     * Las vistas y los idiomas entran porque /sin-conexion se precachea ya
     * renderizada: si cambia su texto, la copia guardada queda mintiendo.
     *
     * @var list<string>
     */
    private const RASTREADOS = [
        'resources/sw.js',
        'public/manifest.webmanifest',
        'public/assets',
        'public/icons',
        'resources/views/sin-conexion.php',
        'resources/views/layout.php',
        'resources/lang',
    ];

    private static ?string $cache = null;

    /** @return string  12 caracteres hexadecimales, suficientes para esto */
    public static function current(): string
    {
        // Una vez por petición: recorrer una docena de ficheros pequeños es
        // barato, pero /sw.js no es el único sitio que puede pedirla.
        return self::$cache ??= substr(self::calcular(), 0, 12);
    }

    /** Solo para los tests: obliga a recalcular. */
    public static function forget(): void
    {
        self::$cache = null;
    }

    private static function calcular(): string
    {
        $huellas = [];

        foreach (self::RASTREADOS as $relativa) {
            $ruta = Config::basePath($relativa);

            foreach (self::ficheros($ruta) as $fichero) {
                // El nombre entra en la huella además del contenido: renombrar
                // un fichero cambia lo que el service worker precarga, aunque
                // el contenido de todos siga siendo el mismo.
                $huellas[] = str_replace(Config::basePath(''), '', $fichero)
                    . ':' . hash_file('sha256', $fichero);
            }
        }

        if ($huellas === []) {
            throw new RuntimeException(
                'No se encontró ninguno de los ficheros que versiona el service worker.'
            );
        }

        // Ordenadas: glob no garantiza el mismo orden en todos los sistemas de
        // ficheros, y un orden distinto daría una huella distinta para el mismo
        // contenido. Costaría una invalidación de caché por despliegue.
        sort($huellas);

        return hash('sha256', implode("\n", $huellas));
    }

    /** @return list<string> */
    private static function ficheros(string $ruta): array
    {
        if (is_file($ruta)) {
            return [$ruta];
        }

        if (! is_dir($ruta)) {
            return [];
        }

        $encontrados = [];

        foreach ((array) glob($ruta . '/*') as $hijo) {
            if (! is_string($hijo)) {
                continue;
            }

            // Los .gitkeep y demás no cuentan: no se sirven.
            if (is_file($hijo) && ! str_starts_with(basename($hijo), '.')) {
                $encontrados[] = $hijo;

                continue;
            }

            if (is_dir($hijo)) {
                $encontrados = [...$encontrados, ...self::ficheros($hijo)];
            }
        }

        return $encontrados;
    }
}
