<?php

declare(strict_types=1);

namespace MaiMind\Tests\Http;

use MaiMind\Http\Icon;
use MaiMind\Support\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Hace cumplir las reglas de docs/design/06-diseno-y-tono.md.
 *
 * Son reglas que se erosionan solas: alguien añade un emoji en un botón, otro
 * mete un rojo de error, y a los seis meses la app opina sobre el usuario.
 */
final class DisenoTest extends TestCase
{
    /** @return list<string> */
    private function ficherosDeInterfaz(): array
    {
        $rutas = [];

        foreach (['resources/views', 'resources/lang', 'public/assets'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(Config::basePath($dir))
            );

            foreach ($it as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'js', 'css'], true)) {
                    $rutas[] = $file->getPathname();
                }
            }
        }

        return $rutas;
    }

    public function test_no_hay_ni_un_emoji_en_toda_la_interfaz(): void
    {
        // Un emoji propone una emoción. Junto a un registro, le está sugiriendo
        // al usuario cómo debería sentirse con lo que acaba de contar, que es
        // justo lo que este producto no puede hacer.
        $rango = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{1F000}-\x{1F02F}]/u';

        foreach ($this->ficherosDeInterfaz() as $ruta) {
            $contenido = (string) file_get_contents($ruta);

            $this->assertSame(
                0,
                preg_match($rango, $contenido),
                'Hay un emoji en ' . basename($ruta) . '. Usa icon() con un icono Phosphor.',
            );
        }
    }

    public function test_la_paleta_no_tiene_color_de_alarma(): void
    {
        // No hay rojo de "mal" ni verde de "bien". Si algo te sienta bien o mal
        // es lo que la app debe descubrir, no lo que debe presuponer.
        $css = (string) file_get_contents(Config::basePath('public/assets/styles.css'));

        preg_match_all('/#[0-9a-fA-F]{6}\b/', $css, $m);

        $permitidos = [
            '#faf3ea', '#eed3ba', '#fffdfa', '#e8dacb', '#151311', '#64534a', '#4b262f',
            '#211b18', '#2a2320', '#332b27', '#efe2d4', '#a08d80', '#b06b77',
        ];

        foreach (array_unique(array_map(strtolower(...), $m[0])) as $color) {
            $this->assertContains(
                $color,
                $permitidos,
                "El color {$color} no está en la paleta. Si es un semáforo, no puede entrar.",
            );
        }
    }

    public function test_el_css_define_el_tema_oscuro(): void
    {
        // Mucha gente va a grabar en la cama a las once. Un fondo crema a
        // pantalla completa a esa hora es una linterna.
        $css = (string) file_get_contents(Config::basePath('public/assets/styles.css'));

        $this->assertStringContainsString('prefers-color-scheme: dark', $css);
        $this->assertStringContainsString('#B06B77', $css, 'Falta el acento levantado para oscuro');
    }

    public function test_no_hay_css_en_linea_en_las_vistas(): void
    {
        foreach ($this->ficherosDeInterfaz() as $ruta) {
            if (! str_ends_with($ruta, '.php')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/\sstyle\s*=\s*["\']/',
                (string) file_get_contents($ruta),
                'CSS en línea en ' . basename($ruta) . '. Todo va en styles.css.',
            );
        }
    }

    public function test_los_iconos_estan_disponibles_y_heredan_el_color(): void
    {
        $disponibles = Icon::available();

        foreach (['microphone', 'check', 'pencil', 'x', 'clock', 'calendar', 'sign-out'] as $nombre) {
            $this->assertContains($nombre, $disponibles);
        }

        // currentColor es lo que hace que el icono siga al tema sin CSS extra.
        $svg = Icon::render('microphone');

        $this->assertStringContainsString('currentColor', $svg);
        $this->assertStringContainsString('width="20"', $svg);
        $this->assertStringContainsString('aria-hidden="true"', $svg);
    }

    public function test_un_icono_con_etiqueta_deja_de_estar_oculto(): void
    {
        $svg = Icon::render('microphone', 24, ['aria-label' => 'Grabar']);

        $this->assertStringNotContainsString('aria-hidden', $svg);
        $this->assertStringContainsString('role="img"', $svg);
        $this->assertStringContainsString('aria-label="Grabar"', $svg);
    }

    public function test_un_icono_inexistente_da_un_error_util(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/phosphor-icons/');

        Icon::render('este-icono-no-existe');
    }

    public function test_el_nombre_del_icono_no_puede_salirse_del_directorio(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no válido/');

        Icon::render('../../.env');
    }


    public function test_cada_texto_que_pide_el_javascript_existe_en_la_vista(): void
    {
        // El JS no lleva textos dentro: los lee de atributos data-msg-*, para
        // que sigan viviendo en los ficheros de idioma. El precio de esa regla
        // es que una errata no da error: pinta "undefined" en pantalla. Esto lo
        // convierte en un test que falla.
        $js    = (string) file_get_contents(Config::basePath('public/assets/capture.js'));
        $vista = (string) file_get_contents(Config::basePath('resources/views/inicio.php'));

        preg_match_all('/dataset\.msg([A-Za-z0-9]+)/', $js, $coincidencias);

        $pedidos = array_unique($coincidencias[1]);

        $this->assertNotEmpty($pedidos, 'El JS ya no lee textos de la vista: ¿se han metido dentro?');

        $pedidos = array_map(
            // msgPendingOne → data-msg-pending-one
            static fn (string $n): string => 'data-msg-' . strtolower(
                (string) preg_replace('/(?<!^)[A-Z]/', '-$0', $n)
            ),
            $pedidos,
        );

        preg_match_all('/data-msg-[a-z-]+/', $vista, $puestos);

        $puestos = array_unique($puestos[0]);

        foreach ($pedidos as $atributo) {
            $this->assertContains($atributo, $puestos, "El JS pide {$atributo} y la vista no lo pone");
        }

        // Y al revés: un atributo que ya nadie lee arrastra consigo una clave de
        // idioma muerta en los dos ficheros. Así no se acumulan.
        foreach ($puestos as $atributo) {
            $this->assertContains($atributo, $pedidos, "La vista pone {$atributo} y nadie lo lee");
        }
    }

    public function test_el_javascript_no_lleva_textos_dentro(): void
    {
        foreach (['capture.js', 'offline.js', 'pwa.js', 'app.js'] as $fichero) {
            $codigo = (string) file_get_contents(Config::basePath('public/assets/' . $fichero));

            // Se quitan los comentarios: ahí sí se escribe en español.
            $codigo = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $codigo);

            $this->assertSame(
                0,
                preg_match('/[áéíóúñ¿¡]/iu', $codigo, $m),
                "{$fichero} tiene texto en español fuera de un comentario: los textos van en resources/lang",
            );
        }
    }

    public function test_no_se_prometen_rachas_ni_celebraciones(): void
    {
        // Una racha castiga los huecos, que son el dato más informativo, y hace
        // que se grabe para mantenerla en vez de porque haya algo que contar.
        $prohibidas = ['racha', 'rachas', 'streak', 'insignia', 'enhorabuena', '¡bien hecho'];

        foreach (['resources/lang/es.php', 'resources/lang/en.php'] as $f) {
            $contenido = mb_strtolower((string) file_get_contents(Config::basePath($f)));

            foreach ($prohibidas as $palabra) {
                $this->assertStringNotContainsString($palabra, $contenido, $f);
            }
        }
    }

    public function test_la_analitica_no_usa_lenguaje_causal(): void
    {
        $es = require Config::basePath('resources/lang/es.php');

        // El vocabulario de análisis nunca afirma causalidad.
        $this->assertStringContainsString('asociado', $es['analysis']['associated_with']);
        $this->assertStringContainsString('precede', $es['analysis']['precedes']);

        foreach ($es['analysis'] as $clave => $texto) {
            foreach ([' provoca', ' causa ', 'deberías'] as $prohibido) {
                $this->assertStringNotContainsString(
                    $prohibido,
                    mb_strtolower($texto),
                    "analysis.{$clave} usa lenguaje causal",
                );
            }
        }
    }
}
