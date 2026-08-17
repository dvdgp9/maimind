<?php

declare(strict_types=1);

namespace MaiMind\Tests\Support;

use MaiMind\Support\Config;
use MaiMind\Support\Lang;
use PHPUnit\Framework\TestCase;

final class LangTest extends TestCase
{
    protected function setUp(): void
    {
        Lang::boot(Config::basePath('resources/lang'), 'es', 'es');
    }

    public function test_traduce_en_el_locale_activo(): void
    {
        $this->assertSame('¿Cómo estás?', t('capture.greeting'));

        Lang::setLocale('en');
        $this->assertSame('How are you?', t('capture.greeting'));
    }

    public function test_devuelve_la_clave_cuando_no_hay_traduccion(): void
    {
        // Visible en pantalla a propósito: una traducción que falta debe notarse.
        $this->assertSame('esta.clave.no.existe', t('esta.clave.no.existe'));
    }

    public function test_cae_al_idioma_de_respaldo(): void
    {
        Lang::boot(Config::basePath('resources/lang'), 'pt', 'es');

        $this->assertSame('¿Cómo estás?', t('capture.greeting'));
    }

    public function test_sustituye_parametros(): void
    {
        $texto = t('review.new_variable', ['name' => 'sensación de avance', 'count' => 3]);

        $this->assertStringContainsString('sensación de avance', $texto);
        $this->assertStringContainsString('3', $texto);
        $this->assertStringNotContainsString(':name', $texto);
        $this->assertStringNotContainsString(':count', $texto);
    }

    public function test_la_preferencia_del_usuario_gana_al_navegador(): void
    {
        $this->assertSame('es', Lang::resolve('es', 'en-GB,en;q=0.9', ['es', 'en']));
    }

    public function test_usa_accept_language_si_no_hay_preferencia(): void
    {
        $this->assertSame('en', Lang::resolve(null, 'en-GB,en;q=0.9,es;q=0.8', ['es', 'en']));
        $this->assertSame('es', Lang::resolve(null, 'es-ES,es;q=0.9', ['es', 'en']));
    }

    public function test_ignora_locales_no_soportados(): void
    {
        $this->assertSame('es', Lang::resolve('de', 'de-DE,de;q=0.9', ['es', 'en']));
    }

    public function test_ambos_idiomas_tienen_las_mismas_claves(): void
    {
        // Que no se quede una traducción a medias sin que nadie se entere.
        $es = require Config::basePath('resources/lang/es.php');
        $en = require Config::basePath('resources/lang/en.php');

        $this->assertSame(
            $this->flatten($es),
            $this->flatten($en),
            'Las claves de es.php y en.php han divergido',
        );
    }

    /**
     * @param  array<string,mixed>  $items
     * @return list<string>
     */
    private function flatten(array $items, string $prefix = ''): array
    {
        $keys = [];

        foreach ($items as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $keys = [...$keys, ...$this->flatten($value, $full)];
            } else {
                $keys[] = $full;
            }
        }

        sort($keys);

        return $keys;
    }
}
