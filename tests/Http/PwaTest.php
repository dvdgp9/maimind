<?php

declare(strict_types=1);

namespace MaiMind\Tests\Http;

use MaiMind\Support\AssetVersion;
use MaiMind\Support\Config;
use MaiMind\Tests\AppTestCase;

/**
 * Que la aplicación se pueda instalar.
 *
 * Casi todo lo que rompe una PWA es una ruta mal escrita: un icono que no
 * existe, un fichero en la lista de precarga que se renombró. Nada de eso da
 * error en el servidor —el manifest se sirve igual, el service worker se
 * registra igual—, simplemente deja de poder instalarse, y nadie se entera
 * hasta que alguien lo intenta desde un móvil. Estos tests siguen las rutas
 * hasta el disco.
 *
 * Lo que **decide** el service worker —qué cachea, qué no y qué borra al cerrar
 * sesión— se prueba ejecutándolo, en `tests/js/sw.test.mjs`. Aquí se comprobaba
 * a base de buscar cadenas en el fichero, que da falsa tranquilidad: la cadena
 * puede seguir estando y la lógica estar mal.
 */
final class PwaTest extends AppTestCase
{
    /** @return array<string,mixed> */
    private function manifest(): array
    {
        $ruta = Config::basePath('public/manifest.webmanifest');

        $this->assertFileExists($ruta);

        return json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_el_manifest_tiene_lo_que_exige_la_instalacion(): void
    {
        $manifest = $this->manifest();

        $this->assertSame('MaiMind', $manifest['name']);
        $this->assertArrayHasKey('short_name', $manifest);
        $this->assertSame('/', $manifest['start_url']);

        // Sin 'standalone' el icono abre una pestaña del navegador, que es
        // exactamente la fricción que el icono venía a quitar.
        $this->assertSame('standalone', $manifest['display']);
    }

    public function test_los_iconos_del_manifest_existen_de_verdad(): void
    {
        $tamanos = [];

        foreach ($this->manifest()['icons'] as $icono) {
            $ruta = Config::basePath('public' . $icono['src']);

            $this->assertFileExists($ruta, "El manifest declara {$icono['src']} y no está");

            [$ancho, $alto] = getimagesize($ruta);

            $this->assertSame(
                $icono['sizes'],
                "{$ancho}x{$alto}",
                "{$icono['src']} dice ser {$icono['sizes']} y mide {$ancho}x{$alto}",
            );

            $tamanos[] = $icono['sizes'];
        }

        // Los dos que piden Android y Chrome para considerarla instalable.
        $this->assertContains('192x192', $tamanos);
        $this->assertContains('512x512', $tamanos);
    }

    public function test_hay_un_icono_enmascarable(): void
    {
        // Sin él, Android recorta el icono cuadrado en un círculo y se lleva
        // por delante el micrófono.
        $propositos = array_column($this->manifest()['icons'], 'purpose');

        $this->assertContains('maskable', $propositos);
    }

    public function test_todo_lo_que_el_service_worker_precarga_existe(): void
    {
        $sw = (string) file_get_contents(Config::basePath('resources/sw.js'));

        preg_match('/const CONCHA = \[(.*?)\];/s', $sw, $bloque);

        $this->assertNotEmpty($bloque, 'No se encuentra la lista de precarga del service worker');

        preg_match_all("/'([^']+)'/", $bloque[1], $rutas);

        $this->assertNotEmpty($rutas[1]);

        foreach ($rutas[1] as $ruta) {
            // Las rutas de PHP no son ficheros: se comprueban pidiéndolas.
            if (! str_contains($ruta, '.')) {
                $this->assertSame(
                    200,
                    $this->get($ruta)->status,
                    "El service worker precarga {$ruta} y esa ruta no responde 200",
                );

                continue;
            }

            $this->assertFileExists(
                Config::basePath('public' . $ruta),
                "El service worker precarga {$ruta} y ese fichero no existe",
            );
        }
    }

    public function test_la_pagina_sin_conexion_no_necesita_sesion(): void
    {
        // La sirve el service worker desde su caché, sin servidor delante. Si
        // exigiera sesión, redirigiría a una pantalla que tampoco se puede
        // cargar sin red.
        $respuesta = $this->get('/sin-conexion');

        $this->assertSame(200, $respuesta->status);
        $this->assertStringContainsString(t('offline.title'), $respuesta->body);

        // Y dice lo único que de verdad preocupa: que no se ha perdido nada.
        $this->assertStringContainsString(t('offline.queue_safe'), $respuesta->body);
    }

    public function test_la_pagina_enlaza_el_manifest_y_el_icono_de_ios(): void
    {
        $a    = $this->crearUsuario('a');
        $html = $this->getComo($this->iniciarSesion($a), '/')->body;

        $this->assertStringContainsString('rel="manifest" href="/manifest.webmanifest"', $html);

        // iOS ignora el manifest para el icono: necesita su propia etiqueta.
        $this->assertStringContainsString('rel="apple-touch-icon"', $html);
        $this->assertFileExists(Config::basePath('public/icons/apple-touch-icon.png'));

        // Y un color de barra por tema, o en oscuro la barra se queda clara.
        $this->assertStringContainsString('prefers-color-scheme: light', $html);
        $this->assertStringContainsString('prefers-color-scheme: dark', $html);
    }




    public function test_no_se_piden_notificaciones(): void
    {
        // Descartadas hasta tener datos reales delante: un recordatorio diario
        // roza la gamificación que prohíbe 06-diseno-y-tono.md §3, y cambia la
        // naturaleza del registro. Ver §6 del mismo documento.
        foreach (['resources/sw.js', 'public/assets/pwa.js', 'public/assets/capture.js'] as $fichero) {
            $codigo = (string) file_get_contents(Config::basePath($fichero));

            $this->assertStringNotContainsString('Notification', $codigo, $fichero);
            $this->assertStringNotContainsString('pushManager', $codigo, $fichero);
        }
    }

    // ------------------------------------------- versión de la caché

    public function test_el_service_worker_se_sirve_con_su_version_puesta(): void
    {
        $respuesta = $this->get('/sw.js');

        $this->assertSame(200, $respuesta->status);
        $this->assertStringContainsString('javascript', (string) $respuesta->header('Content-Type'));

        // Si se cacheara, una versión nueva podría tardar un día en llegar.
        $this->assertStringContainsString('no-cache', (string) $respuesta->header('Cache-Control'));

        $this->assertStringNotContainsString(
            '__VERSION__',
            $respuesta->body,
            'El marcador se sirvió sin sustituir: la caché no se invalidaría nunca',
        );

        $this->assertStringContainsString(
            "const VERSION   = '" . AssetVersion::current() . "'",
            $respuesta->body,
        );
    }

    public function test_la_version_cambia_al_tocar_un_asset(): void
    {
        // Es el mecanismo entero. Antes esto era un número escrito a mano, y
        // olvidarse de subirlo dejaba a los móviles ya instalados con el CSS
        // viejo para siempre sin que en el servidor se viera nada raro.
        $antes = AssetVersion::current();

        $intruso = Config::basePath('public/assets/_prueba-de-version.js');

        file_put_contents($intruso, '// solo para este test');

        try {
            AssetVersion::forget();

            $this->assertNotSame($antes, AssetVersion::current(), 'La huella no se enteró del cambio');
        } finally {
            unlink($intruso);
            AssetVersion::forget();
        }

        $this->assertSame($antes, AssetVersion::current(), 'La huella no volvió a ser la misma');
    }

    public function test_la_version_no_cambia_sin_motivo(): void
    {
        // Dos despliegues del mismo código tienen que dar la misma huella: si
        // no, cada despliegue tiraría la caché de todo el mundo.
        $primera = AssetVersion::current();

        AssetVersion::forget();

        $this->assertSame($primera, AssetVersion::current());
    }

}
