<?php

declare(strict_types=1);

namespace MaiMind\Tests\Providers;

use MaiMind\Pipeline\Transcription\AudioRef;
use MaiMind\Pipeline\Transcription\TranscriptionFailed;
use MaiMind\Providers\OpenRouter\OpenRouterTranscriptionProvider;
use MaiMind\Support\Http\FakeHttpClient;
use MaiMind\Support\Http\HttpResponse;
use MaiMind\Support\Http\HttpTransportFailed;
use PHPUnit\Framework\TestCase;

/**
 * El transcriptor de OpenRouter (tarea 2.2).
 *
 * Contra un cliente HTTP de mentira: un test que dependa de que OpenRouter esté
 * levantado y que cueste dinero cada vez que se ejecuta no es un test.
 */
final class OpenRouterTranscripcionTest extends TestCase
{
    private string $directorio;

    private string $bytes = 'esto-hace-de-audio';

    protected function setUp(): void
    {
        $this->directorio = sys_get_temp_dir() . '/maimind-transcripcion-' . bin2hex(random_bytes(4));

        mkdir($this->directorio . '/audio', 0770, true);
        file_put_contents($this->directorio . '/audio/prueba.webm', $this->bytes);
    }

    protected function tearDown(): void
    {
        @unlink($this->directorio . '/audio/prueba.webm');
        @rmdir($this->directorio . '/audio');
        @rmdir($this->directorio);
    }

    private function audio(?string $sha = null): AudioRef
    {
        return new AudioRef(
            path: 'audio/prueba.webm',
            mime: 'audio/webm;codecs=opus',
            bytes: strlen($this->bytes),
            sha256: $sha ?? hash('sha256', $this->bytes),
            durationMs: 30000,
        );
    }

    private function proveedor(FakeHttpClient $http, string $clave = 'sk-de-prueba'): OpenRouterTranscriptionProvider
    {
        return new OpenRouterTranscriptionProvider(
            http: $http,
            apiKey: $clave,
            baseUrl: 'https://openrouter.ai/api/v1',
            model: 'openai/whisper-large-v3-turbo',
            timeoutSeconds: 120,
            storagePath: $this->directorio,
            appUrl: 'https://maimind.iaiapro.com',
        );
    }

    /** @return array<string,mixed> */
    private function respuestaTipica(): array
    {
        return [
            'text' => 'A las tres discutí con Marta. Luego me fui a dormir.',
            'language' => 'es',
            'duration' => 6.0,
            'segments' => [
                [
                    'id' => 0, 'start' => 0.0, 'end' => 3.2,
                    'text' => 'A las tres discutí con Marta.',
                    'avg_logprob' => -0.15, 'no_speech_prob' => 0.01,
                ],
                [
                    'id' => 1, 'start' => 3.2, 'end' => 6.0,
                    'text' => 'Luego me fui a dormir.',
                    'avg_logprob' => -0.35, 'no_speech_prob' => 0.02,
                ],
            ],
            'usage' => ['seconds' => 6.0, 'cost' => 0.000508],
        ];
    }

    // ------------------------------------------------------- la petición

    public function test_la_politica_de_datos_viaja_en_la_peticion(): void
    {
        // Es la comprobación que hace real la decisión D10: que exista la clase
        // DataPolicy no sirve de nada si el proveedor no la usa.
        $http = (new FakeHttpClient())->willReturnJson($this->respuestaTipica());

        $this->proveedor($http)->transcribe($this->audio(), 'es');

        $cuerpo = $http->lastBody();

        $this->assertSame('deny', $cuerpo['provider']['data_collection']);
        $this->assertTrue($cuerpo['provider']['zdr']);
    }

    public function test_el_audio_va_en_base64_crudo_y_no_como_data_uri(): void
    {
        // docs/api/openrouter.md §1. Mandarlo como data: URI devuelve un 400
        // que no dice por qué.
        $http = (new FakeHttpClient())->willReturnJson($this->respuestaTipica());

        $this->proveedor($http)->transcribe($this->audio());

        $datos = $http->lastBody()['input_audio']['data'];

        $this->assertStringNotContainsString('data:', $datos);
        $this->assertSame($this->bytes, base64_decode($datos, true));
    }

    public function test_manda_el_formato_y_no_el_mime(): void
    {
        $http = (new FakeHttpClient())->willReturnJson($this->respuestaTipica());

        $this->proveedor($http)->transcribe($this->audio());

        $this->assertSame('webm', $http->lastBody()['input_audio']['format']);
    }

    public function test_pide_tramos_con_tiempos_y_temperatura_cero(): void
    {
        // Temperatura 0 no es una preferencia: un transcriptor que improvisa
        // rompe el anclaje de evidencia, que es lo que sostiene el producto.
        $http = (new FakeHttpClient())->willReturnJson($this->respuestaTipica());

        $this->proveedor($http)->transcribe($this->audio(), 'es');

        $cuerpo = $http->lastBody();

        $this->assertSame(0, $cuerpo['temperature']);
        $this->assertSame('verbose_json', $cuerpo['response_format']);
        $this->assertSame(['segment'], $cuerpo['timestamp_granularities']);
        $this->assertSame('es', $cuerpo['language']);
    }

    public function test_el_modelo_va_fijado_y_no_se_deja_al_enrutado(): void
    {
        // Un cambio silencioso de modelo altera la transcripción y rompe la
        // comparabilidad longitudinal, que es de lo que va todo esto.
        $http = (new FakeHttpClient())->willReturnJson($this->respuestaTipica());

        $this->proveedor($http)->transcribe($this->audio());

        $this->assertSame('openai/whisper-large-v3-turbo', $http->lastBody()['model']);
    }

    public function test_va_al_endpoint_de_audio_con_la_clave(): void
    {
        $http = (new FakeHttpClient())->willReturnJson($this->respuestaTipica());

        $this->proveedor($http)->transcribe($this->audio());

        $peticion = $http->lastRequest();

        $this->assertSame('https://openrouter.ai/api/v1/audio/transcriptions', $peticion['url']);
        $this->assertSame('Bearer sk-de-prueba', $peticion['headers']['Authorization']);
    }

    // ------------------------------------------------------ la respuesta

    public function test_interpreta_una_respuesta_normal(): void
    {
        $http = (new FakeHttpClient())->willReturnJson($this->respuestaTipica(), latencyMs: 900);

        $resultado = $this->proveedor($http)->transcribe($this->audio(), 'es');

        $this->assertSame('A las tres discutí con Marta. Luego me fui a dormir.', $resultado->text);
        $this->assertSame('openrouter', $resultado->provider);
        $this->assertSame('es', $resultado->language);
        $this->assertSame(900, $resultado->latencyMs);
        $this->assertCount(2, $resultado->segments);
        $this->assertTrue($resultado->isFullyAnchored());
    }

    public function test_el_coste_sale_de_la_respuesta_y_no_se_estima(): void
    {
        $http = (new FakeHttpClient())->willReturnJson($this->respuestaTipica());

        $resultado = $this->proveedor($http)->transcribe($this->audio());

        // 0.000508 dólares = 508 micros.
        $this->assertSame(508, $resultado->costMicros);
    }

    public function test_los_tiempos_pasan_de_segundos_a_milisegundos(): void
    {
        $http = (new FakeHttpClient())->willReturnJson($this->respuestaTipica());

        $segmentos = $this->proveedor($http)->transcribe($this->audio())->segments;

        $this->assertSame(0, $segmentos[0]->startMs);
        $this->assertSame(3200, $segmentos[0]->endMs);
        $this->assertSame(6000, $segmentos[1]->endMs);
    }

    public function test_guarda_los_numeros_crudos_del_proveedor(): void
    {
        // `confidence` es una transformación con pérdida de `avg_logprob`.
        // El número de verdad se conserva por si algún día hace falta.
        $http = (new FakeHttpClient())->willReturnJson($this->respuestaTipica());

        $segmento = $this->proveedor($http)->transcribe($this->audio())->segments[0];

        $this->assertSame(-0.15, $segmento->providerMetrics['avg_logprob']);
        $this->assertSame(0.01, $segmento->providerMetrics['no_speech_prob']);
    }

    public function test_convierte_el_logprob_en_algo_que_cabe_en_la_columna(): void
    {
        $http = (new FakeHttpClient())->willReturnJson($this->respuestaTipica());

        $segmento = $this->proveedor($http)->transcribe($this->audio())->segments[0];

        // exp(-0.15) ≈ 0.861
        $this->assertEqualsWithDelta(0.861, $segmento->confidence, 0.001);
    }

    public function test_un_logprob_que_la_documentacion_da_por_fallido_no_da_confianza(): void
    {
        // Por debajo de -1, Whisper dice que los logprobs han fallado. Un
        // número bajo ahí parecería medir algo y no mide nada.
        $respuesta = $this->respuestaTipica();
        $respuesta['segments'][0]['avg_logprob'] = -2.5;

        $http = (new FakeHttpClient())->willReturnJson($respuesta);

        $segmento = $this->proveedor($http)->transcribe($this->audio())->segments[0];

        $this->assertNull($segmento->confidence);
        // Pero el crudo se guarda igual.
        $this->assertSame(-2.5, $segmento->providerMetrics['avg_logprob']);
    }

    public function test_sin_tramos_la_transcripcion_sigue_valiendo(): void
    {
        // Algunos proveedores enrutados devuelven 400 si se piden timestamps;
        // otros los ignoran. El texto es lo que no puede faltar.
        $http = (new FakeHttpClient())->willReturnJson([
            'text' => 'Algo he dicho.',
            'usage' => ['cost' => 0.0001],
        ]);

        $resultado = $this->proveedor($http)->transcribe($this->audio());

        $this->assertSame('Algo he dicho.', $resultado->text);
        $this->assertSame([], $resultado->segments);
        $this->assertFalse($resultado->isFullyAnchored());
    }

    // ----------------------------------------------------------- fallos

    public function test_sin_clave_no_se_reintenta(): void
    {
        $http = new FakeHttpClient();

        try {
            $this->proveedor($http, clave: '')->transcribe($this->audio());
            $this->fail('Debería haber fallado');
        } catch (TranscriptionFailed $e) {
            $this->assertFalse($e->retryable, 'Reintentar no hace aparecer una clave');
        }

        $this->assertSame(0, $http->requestCount(), 'Ni siquiera debería haber llamado');
    }

    public function test_si_el_audio_no_coincide_con_su_sha_no_se_paga_la_inferencia(): void
    {
        $http = new FakeHttpClient();
        $otro = str_repeat('0', 64);

        try {
            $this->proveedor($http)->transcribe($this->audio(sha: $otro));
            $this->fail('Debería haber fallado');
        } catch (TranscriptionFailed $e) {
            $this->assertFalse($e->retryable);
        }

        $this->assertSame(0, $http->requestCount(), 'Se llamó a la API con un audio corrupto');
    }

    public function test_un_audio_purgado_no_se_reintenta(): void
    {
        unlink($this->directorio . '/audio/prueba.webm');

        $http = new FakeHttpClient();

        try {
            $this->proveedor($http)->transcribe($this->audio());
            $this->fail('Debería haber fallado');
        } catch (TranscriptionFailed $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('ya no está', $e->getMessage());
        }

        // Se recrea para el tearDown.
        file_put_contents($this->directorio . '/audio/prueba.webm', $this->bytes);
    }

    public function test_un_5xx_se_reintenta_y_un_400_no(): void
    {
        foreach ([[500, true], [503, true], [429, true], [400, false], [413, false]] as [$estado, $reintentable]) {
            $http = (new FakeHttpClient())->willReturnJson(
                ['error' => ['message' => 'lo que sea']],
                estado: $estado,
            );

            try {
                $this->proveedor($http)->transcribe($this->audio());
                $this->fail("El {$estado} debería haber fallado");
            } catch (TranscriptionFailed $e) {
                $this->assertSame($reintentable, $e->retryable, "Estado {$estado}");
                $this->assertStringContainsString((string) $estado, $e->getMessage());
            }
        }
    }

    public function test_un_corte_de_red_se_reintenta(): void
    {
        $http = (new FakeHttpClient())->willRespond(
            new HttpTransportFailed('Could not resolve host')
        );

        try {
            $this->proveedor($http)->transcribe($this->audio());
            $this->fail('Debería haber fallado');
        } catch (TranscriptionFailed $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function test_una_transcripcion_vacia_se_reintenta(): void
    {
        // Puede ser silencio o puede ser un fallo del proveedor, y desde aquí
        // no se distingue. Perder la grabación es peor que un reintento.
        $http = (new FakeHttpClient())->willReturnJson(['text' => '', 'usage' => []]);

        try {
            $this->proveedor($http)->transcribe($this->audio());
            $this->fail('Debería haber fallado');
        } catch (TranscriptionFailed $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function test_el_mensaje_de_error_no_se_lleva_medio_html_a_la_cola(): void
    {
        // last_error se lee en una terminal.
        $http = (new FakeHttpClient())->willRespond(
            new HttpResponse(502, str_repeat('<html>vaya</html>', 500))
        );

        try {
            $this->proveedor($http)->transcribe($this->audio());
            $this->fail('Debería haber fallado');
        } catch (TranscriptionFailed $e) {
            $this->assertLessThan(400, strlen($e->getMessage()));
        }
    }
}
