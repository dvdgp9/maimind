<?php

declare(strict_types=1);

namespace MaiMind\Tests\Domain;

use MaiMind\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * Comprueba la coherencia interna del catálogo core como DATO, sin base de datos.
 *
 * Estas reglas son las que impiden que el catálogo se degrade con el tiempo:
 * variables casi sinónimas, escalas incoherentes, polaridades presupuestas.
 */
final class CatalogoCoreTest extends TestCase
{
    /** @var list<array<string,mixed>> */
    private array $variables;

    protected function setUp(): void
    {
        $this->variables = require Config::basePath('resources/seeds/variables_core.php');
    }

    public function test_el_core_es_pequeno(): void
    {
        // Pedirle a un LLM que rellene doscientos huecos da peores resultados que
        // pedirle cuarenta. El resto del vocabulario emerge del uso.
        $this->assertGreaterThanOrEqual(30, count($this->variables));
        $this->assertLessThanOrEqual(50, count($this->variables));
    }

    public function test_los_slugs_son_identificadores_en_ingles(): void
    {
        foreach ($this->variables as $v) {
            $this->assertMatchesRegularExpression(
                '/^[a-z_]+\.[a-z_]+$/',
                (string) $v['slug'],
                'Los slugs son identificadores: sin acentos, sin mayúsculas, categoria.nombre',
            );
        }
    }

    public function test_toda_variable_tiene_etiqueta_en_espanol_y_definicion(): void
    {
        foreach ($this->variables as $v) {
            $slug = (string) $v['slug'];

            $this->assertArrayHasKey('es', $v['name_i18n'], $slug);
            $this->assertArrayHasKey('en', $v['name_i18n'], $slug);
            $this->assertNotEmpty($v['definition'], $slug);
            $this->assertNotEmpty($v['extraction_hint'], $slug);
        }
    }

    public function test_toda_variable_tiene_alias_suficientes(): void
    {
        // Los alias son el puente entre lo que la gente dice y la variable.
        // Con dos no se cubre ni la mitad de las formas de decirlo.
        foreach ($this->variables as $v) {
            $this->assertGreaterThanOrEqual(
                4,
                count($v['aliases'] ?? []),
                (string) $v['slug'],
            );
        }
    }

    public function test_ningun_alias_pertenece_a_dos_variables(): void
    {
        $duenos = [];

        foreach ($this->variables as $v) {
            foreach ($v['aliases'] ?? [] as $alias) {
                $duenos[mb_strtolower($alias)][] = (string) $v['slug'];
            }
        }

        foreach ($duenos as $alias => $slugs) {
            $this->assertCount(
                1,
                array_unique($slugs),
                "El alias \"{$alias}\" lo reclaman: " . implode(', ', array_unique($slugs)),
            );
        }
    }

    public function test_las_escalas_ordinales_son_todas_de_1_a_5(): void
    {
        // Una única escala en todo el sistema, y la misma que el toque previo a
        // grabar (mood_hint). Así se puede contrastar lo que extrae la IA con lo
        // que el usuario marca con el dedo.
        foreach ($this->variables as $v) {
            if ($v['value_type'] !== 'ordinal') {
                continue;
            }

            $this->assertSame(1, $v['scale_min'], (string) $v['slug']);
            $this->assertSame(5, $v['scale_max'], (string) $v['slug']);
        }
    }

    public function test_las_emociones_se_guardan_en_bandas_de_intensidad(): void
    {
        // Convertir "bastante nervioso" en 8.0 fabrica una precisión que nadie
        // ha medido, y a los tres meses es una serie temporal con tendencia.
        foreach ($this->variables as $v) {
            if ($v['category'] !== 'emotion') {
                continue;
            }

            $this->assertSame('category_intensity', $v['value_type'], (string) $v['slug']);
            $this->assertSame('episodic', $v['temporal_kind'], (string) $v['slug']);
            $this->assertArrayNotHasKey('scale_min', $v, (string) $v['slug']);
        }
    }

    public function test_ninguna_conducta_lleva_polaridad_presupuesta(): void
    {
        // Si salir de casa, mirar el móvil o estar solo le sienta bien o mal a
        // ESTA persona es lo que la aplicación tiene que descubrir. Marcarlo de
        // antemano sería contestar la pregunta antes de mirar los datos.
        foreach ($this->variables as $v) {
            if ($v['category'] !== 'behavior') {
                continue;
            }

            $this->assertSame(
                'neutral',
                $v['polarity'],
                "La conducta {$v['slug']} no puede llevar polaridad presupuesta",
            );
        }
    }

    public function test_las_variables_interpretativas_piden_confirmacion(): void
    {
        // Etiquetar la soledad de alguien como "retraimiento", o deducir culpa o
        // vergüenza, es interpretar. Eso siempre lo confirma el usuario.
        $interpretativas = [
            'behavior.withdrawal',
            'cognition.perceived_control',
            'cognition.self_criticism',
            'emotion.guilt',
            'emotion.shame',
        ];

        $porSlug = array_column($this->variables, null, 'slug');

        foreach ($interpretativas as $slug) {
            $this->assertSame(1, $porSlug[$slug]['requires_confirm'] ?? 0, $slug);
        }
    }

    public function test_no_hay_variables_inversas_entre_si(): void
    {
        // El cansancio es energía baja, y la calma es estrés bajo. Tenerlas como
        // variables aparte produciría filas que se contradicen entre sí. Van como
        // alias del extremo bajo de su variable.
        $slugs = array_column($this->variables, 'slug');

        foreach (['state.fatigue', 'physical.fatigue', 'emotion.calm', 'state.calm'] as $prohibida) {
            $this->assertNotContains($prohibida, $slugs);
        }

        $porSlug = array_column($this->variables, null, 'slug');

        $this->assertContains('cansado', $porSlug['state.energy']['aliases']);
        $this->assertContains('tranquilo', $porSlug['state.stress']['aliases']);
    }

    public function test_las_variables_de_conceptos_sin_equivalente_lo_declaran(): void
    {
        // Agobio e ilusión no tienen traducción limpia al inglés. La etiqueta
        // inglesa es una aproximación declarada, no una traducción, y el catálogo
        // tiene que decirlo para que nadie la dé por buena más adelante.
        $porSlug = array_column($this->variables, null, 'slug');

        foreach (['emotion.overwhelm', 'emotion.anticipation'] as $slug) {
            $this->assertStringContainsString(
                'aprox',
                (string) $porSlug[$slug]['name_i18n']['en'],
                $slug,
            );
        }
    }

    public function test_las_cantidades_solo_existen_si_la_gente_las_dice(): void
    {
        // Nadie dice "cuatro horas y doce minutos de móvil", así que el tiempo de
        // pantallas es ordinal. Nadie cuenta sus despertares, así que la
        // fragmentación del sueño también. Las horas de sueño sí se dicen.
        $porSlug = array_column($this->variables, null, 'slug');

        $this->assertSame('ordinal', $porSlug['behavior.screen_time']['value_type']);
        $this->assertSame('ordinal', $porSlug['sleep.fragmentation']['value_type']);
        $this->assertSame('numeric', $porSlug['sleep.duration']['value_type']);
    }

    public function test_el_sueno_separa_cantidad_de_calidad(): void
    {
        // Dormir ocho horas mal y cinco bien son datos distintos, y esa
        // disociación es de lo más útil que se puede analizar.
        $porSlug = array_column($this->variables, null, 'slug');

        $this->assertSame('numeric', $porSlug['sleep.duration']['value_type']);
        $this->assertSame('ordinal', $porSlug['sleep.quality']['value_type']);
        // Ni "más horas mejor" ni "menos mejor": dormir de más también es señal.
        $this->assertSame('neutral', $porSlug['sleep.duration']['polarity']);
    }
}
