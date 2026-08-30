<?php

declare(strict_types=1);

namespace MaiMind\Tests\Providers;

use MaiMind\Providers\OpenRouter\DataPolicy;
use MaiMind\Support\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Decisión D10: por dónde puede enrutar OpenRouter.
 *
 * Estos tests existen porque el fallo que evitan es irreversible y silencioso.
 * Una grabación que acaba en el conjunto de entrenamiento de un proveedor no se
 * puede retirar, y nadie se entera de que ha pasado: la transcripción vuelve
 * correcta y todo sigue funcionando igual.
 */
final class PoliticaDeDatosTest extends TestCase
{
    public function test_toda_peticion_prohibe_entrenar_y_conservar(): void
    {
        $politica = DataPolicy::forRequest();

        // Los nombres de las claves son de OpenRouter, verificados contra su
        // documentación el 2026-08-30. Ver docs/api/openrouter.md §4.
        $this->assertSame('deny', $politica['data_collection']);
        $this->assertTrue($politica['zdr']);
    }

    public function test_son_dos_controles_distintos_y_estan_los_dos(): void
    {
        // Un proveedor puede no entrenar con los datos y aun así guardarlos
        // treinta días. La nota original de D10 solo pedía lo primero.
        $politica = DataPolicy::forRequest();

        $this->assertArrayHasKey('data_collection', $politica);
        $this->assertArrayHasKey('zdr', $politica);
    }

    public function test_la_politica_se_pega_a_cualquier_cuerpo(): void
    {
        $cuerpo = DataPolicy::applyTo([
            'model'       => 'openai/whisper-1',
            'input_audio' => ['data' => '...'],
        ]);

        $this->assertSame('openai/whisper-1', $cuerpo['model']);
        $this->assertSame('deny', $cuerpo['provider']['data_collection']);
        $this->assertTrue($cuerpo['provider']['zdr']);
    }

    public function test_quien_llama_no_puede_aflojarla(): void
    {
        // Aunque la pida floja explícitamente: no es un valor por defecto.
        $cuerpo = DataPolicy::applyTo([
            'provider' => ['data_collection' => 'allow', 'zdr' => false, 'order' => ['groq']],
        ]);

        $this->assertSame('deny', $cuerpo['provider']['data_collection']);
        $this->assertTrue($cuerpo['provider']['zdr']);

        // Lo que no sea la política sí se respeta.
        $this->assertSame(['groq'], $cuerpo['provider']['order']);
    }

    /**
     * @return list<array{0:array<string,mixed>,1:string}>
     */
    public static function politicasAflojadas(): array
    {
        return [
            'permite entrenar'        => [['data_collection' => 'allow', 'zdr' => true]],
            'permite conservar'       => [['data_collection' => 'deny', 'zdr' => false]],
            'sin política ninguna'    => [[]],
            'con valores plausibles'  => [['data_collection' => true, 'zdr' => 'true']],
        ];
    }

    /**
     * @param  array<string,mixed>  $privacidad
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('politicasAflojadas')]
    public function test_si_alguien_afloja_la_configuracion_no_sale_nada(array $privacidad): void
    {
        // Cierra hacia el lado seguro: mejor que no salga la transcripción a
        // que salga hacia donde no debe. Un fallo se ve; una grabación en el
        // conjunto de entrenamiento de alguien, no.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/D10/');

        DataPolicy::assertNotLoosened($privacidad);
    }

    public function test_la_configuracion_del_repositorio_es_la_estricta(): void
    {
        $privacidad = (array) config('services.openrouter.privacy');

        $this->assertSame('deny', $privacidad['data_collection']);
        $this->assertTrue($privacidad['zdr']);
    }

    public function test_la_politica_no_sale_de_una_variable_de_entorno(): void
    {
        // Un .env mal copiado no puede ser la razón de que una grabación acabe
        // en el conjunto de entrenamiento de nadie.
        $config = (string) file_get_contents(Config::basePath('config/services.php'));

        preg_match("/'privacy' => \[(.*?)\n        \],/s", $config, $bloque);

        $this->assertNotEmpty($bloque, 'No se encuentra el bloque privacy en config/services.php');
        $this->assertStringNotContainsString('env(', $bloque[1]);
    }
}
