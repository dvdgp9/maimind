<?php

declare(strict_types=1);

namespace MaiMind\Tests\Pipeline;

use InvalidArgumentException;
use MaiMind\Pipeline\Transcription\AudioRef;
use MaiMind\Pipeline\Transcription\FakeTranscriptionProvider;
use MaiMind\Pipeline\Transcription\TranscriptionFailed;
use MaiMind\Pipeline\Transcription\TranscriptionProvider;
use MaiMind\Pipeline\Transcription\TranscriptionResult;
use MaiMind\Pipeline\Transcription\TranscriptionSegment;
use PHPUnit\Framework\TestCase;

/**
 * La interfaz de transcripción y su implementación falsa (tarea 2.1).
 */
final class TranscripcionTest extends TestCase
{
    private const SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private function audio(?int $duracionMs = 30000, string $mime = 'audio/webm'): AudioRef
    {
        return new AudioRef(
            path: 'audio/01ABC/2026/08/01DEF.webm',
            mime: $mime,
            bytes: 4096,
            sha256: self::SHA,
            durationMs: $duracionMs,
        );
    }

    // ------------------------------------------------------------ AudioRef

    public function test_una_ruta_que_se_escapa_de_storage_se_rechaza(): void
    {
        // La ruta viene de la base de datos, pero una fila mal escrita no puede
        // acabar leyendo cualquier cosa de la máquina.
        $this->expectException(InvalidArgumentException::class);

        new AudioRef('audio/../../../etc/passwd', 'audio/webm', 10, self::SHA);
    }

    public function test_un_audio_vacio_no_es_un_audio(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AudioRef('audio/x.webm', 'audio/webm', 0, self::SHA);
    }

    public function test_el_sha_tiene_que_parecerlo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AudioRef('audio/x.webm', 'audio/webm', 10, 'no-es-un-hash');
    }

    public function test_traduce_el_mime_al_formato_que_espera_la_api(): void
    {
        // OpenRouter quiere la extensión, no el MIME.
        $this->assertSame('webm', $this->audio(mime: 'audio/webm;codecs=opus')->format());
        $this->assertSame('m4a', $this->audio(mime: 'audio/mp4')->format());
        $this->assertSame('mp3', $this->audio(mime: 'audio/mpeg')->format());
    }

    public function test_un_formato_desconocido_no_llega_a_la_api(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->audio(mime: 'application/zip')->format();
    }

    // --------------------------------------------------- resultado

    public function test_las_cifras_derivadas_las_calcula_el_resultado(): void
    {
        // Si las pasara el proveedor, dos proveedores contarían distinto y
        // compararlos dejaría de significar nada.
        $resultado = new TranscriptionResult(
            text: 'Hoy he dormido fatal.',
            provider: 'fake',
            model: 'fake/whisper',
            segments: [
                new TranscriptionSegment(0, 'Hoy he dormido', 0, 1000, 0.9),
                new TranscriptionSegment(1, 'fatal.', 1000, 2000, 0.7),
            ],
        );

        $this->assertSame(4, $resultado->wordCount);
        $this->assertSame(0.8, $resultado->avgConfidence);
    }

    public function test_sin_confianza_del_proveedor_no_se_inventa_una(): void
    {
        $resultado = new TranscriptionResult(
            text: 'Hoy he dormido fatal.',
            provider: 'fake',
            model: 'm',
            segments: [new TranscriptionSegment(0, 'Hoy he dormido fatal.', 0, 1000)],
        );

        $this->assertNull($resultado->avgConfidence);
    }

    public function test_una_transcripcion_vacia_revienta(): void
    {
        // O el audio estaba en silencio o algo falló, y hay que decidir cuál.
        // Guardar una fila vacía no permite distinguirlo después.
        $this->expectException(InvalidArgumentException::class);

        new TranscriptionResult(text: '   ', provider: 'fake', model: 'm');
    }

    public function test_toda_transcripcion_dice_que_motor_la_produjo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TranscriptionResult(text: 'algo', provider: '', model: 'm');
    }

    // ------------------------------------------- anclaje de evidencia

    public function test_cada_tramo_se_situa_dentro_del_texto(): void
    {
        // Es lo que permite volver del dato al audio, y lo que hace auditable
        // al extractor: sin offsets, una cita no se puede comprobar.
        $texto = 'A las tres discutí con Marta. Luego me fui a dormir.';

        $resultado = new TranscriptionResult(
            text: $texto,
            provider: 'fake',
            model: 'm',
            segments: [
                new TranscriptionSegment(0, 'A las tres discutí con Marta.', 0, 3000),
                new TranscriptionSegment(1, 'Luego me fui a dormir.', 3000, 6000),
            ],
        );

        $this->assertTrue($resultado->isFullyAnchored());

        [$primero, $segundo] = $resultado->segments;

        $this->assertSame('A las tres discutí con Marta.', mb_substr($texto, $primero->charStart, $primero->charEnd - $primero->charStart));
        $this->assertSame('Luego me fui a dormir.', mb_substr($texto, $segundo->charStart, $segundo->charEnd - $segundo->charStart));
    }

    public function test_el_anclaje_aguanta_los_espacios_del_proveedor(): void
    {
        // Los proveedores meten y quitan espacios entre tramos. Sumando
        // longitudes, el desfase se acumula y las citas acaban señalando otra
        // palabra; por eso se busca en el texto de verdad.
        $texto = 'Uno.  Dos.   Tres.';

        $resultado = new TranscriptionResult(
            text: $texto,
            provider: 'fake',
            model: 'm',
            segments: [
                new TranscriptionSegment(0, ' Uno. ', 0, 1000),
                new TranscriptionSegment(1, 'Dos.', 1000, 2000),
                new TranscriptionSegment(2, ' Tres.', 2000, 3000),
            ],
        );

        $this->assertTrue($resultado->isFullyAnchored());

        $ultimo = $resultado->segments[2];

        $this->assertSame('Tres.', mb_substr($texto, $ultimo->charStart, $ultimo->charEnd - $ultimo->charStart));
    }

    public function test_una_palabra_repetida_no_confunde_al_anclaje(): void
    {
        $texto = 'No. No. No.';

        $resultado = new TranscriptionResult(
            text: $texto,
            provider: 'fake',
            model: 'm',
            segments: [
                new TranscriptionSegment(0, 'No.', 0, 1000),
                new TranscriptionSegment(1, 'No.', 1000, 2000),
                new TranscriptionSegment(2, 'No.', 2000, 3000),
            ],
        );

        // Cada uno en su sitio, no los tres en el primero.
        $this->assertSame([0, 4, 8], array_map(
            static fn (TranscriptionSegment $s): int => $s->charStart,
            $resultado->segments,
        ));
    }

    public function test_un_tramo_que_no_aparece_se_queda_sin_anclaje(): void
    {
        // Inventarle unos offsets sería peor que no tenerlos: la cita
        // apuntaría a palabras que esa persona no dijo.
        $resultado = new TranscriptionResult(
            text: 'Hoy he dormido fatal.',
            provider: 'fake',
            model: 'm',
            segments: [new TranscriptionSegment(0, 'esto no está en el texto', 0, 1000)],
        );

        $this->assertNull($resultado->segments[0]->charStart);
        $this->assertFalse($resultado->isFullyAnchored());
    }

    public function test_un_tramo_ya_anclado_a_otro_texto_pierde_sus_offsets(): void
    {
        // Salió al corregir una transcripción a mano: los tramos guardados
        // vienen anclados al texto anterior, y si el tramo ya no aparece en el
        // nuevo, esos offsets señalan palabras que ahí no están. Conservarlos
        // sería exactamente lo que el anclaje existe para impedir.
        $viejo = new TranscriptionSegment(1, 'Dos.', 15000, 30000, null, 5, 9);

        $resultado = new TranscriptionResult(
            text: 'Uno. Tres.',
            provider: 'user',
            model: 'manual',
            segments: [new TranscriptionSegment(0, 'Uno.', 0, 15000, null, 0, 4), $viejo],
        );

        $this->assertSame(0, $resultado->segments[0]->charStart, 'El que sigue estando sigue anclado');
        $this->assertNull($resultado->segments[1]->charStart);
        $this->assertNull($resultado->segments[1]->charEnd);
        // Los tiempos no se tocan: siguen describiendo el audio.
        $this->assertSame(15000, $resultado->segments[1]->startMs);
    }

    public function test_un_tramo_con_tiempos_imposibles_se_rechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TranscriptionSegment(0, 'algo', 5000, 1000);
    }

    // ------------------------------------------------- persistencia

    public function test_la_fila_encaja_con_la_tabla(): void
    {
        $fila = (new TranscriptionResult(
            text: 'Hoy he dormido fatal.',
            provider: 'openrouter',
            model: 'openai/whisper-1',
            segments: [new TranscriptionSegment(0, 'Hoy he dormido fatal.', 0, 2000, 0.95)],
            language: 'es',
            costMicros: 508,
            latencyMs: 1200,
        ))->toRow();

        $this->assertSame(
            ['provider', 'model', 'language', 'text', 'word_count', 'avg_confidence',
             'segments', 'gap_total_ms', 'coverage_gaps', 'cost_micros', 'latency_ms'],
            array_keys($fila),
        );

        $this->assertSame('openai/whisper-1', $fila['model']);
        $this->assertSame(4, $fila['word_count']);
        $this->assertIsString($fila['segments']);

        $guardados = json_decode((string) $fila['segments'], true);

        $this->assertSame(0, $guardados[0]['char_start']);
        $this->assertSame(21, $guardados[0]['char_end']);
    }


    // --------------------------------------- cobertura del audio

    public function test_detecta_el_hueco_que_dejo_whisper_en_produccion(): void
    {
        // Los números son los reales del 2026-08-30: whisper-large-v3-turbo
        // devolvió estos dos tramos para 40,4 s de audio y se comió la frase
        // que iba en medio, sin que el texto lo delatara.
        $resultado = new TranscriptionResult(
            text: 'Primera parte. Segunda parte.',
            provider: 'openrouter',
            model: 'openai/whisper-large-v3-turbo',
            segments: [
                new TranscriptionSegment(0, 'Primera parte.', 0, 25400),
                new TranscriptionSegment(1, 'Segunda parte.', 30000, 40300),
            ],
        );

        $huecos = $resultado->coverageGaps(40396);

        $this->assertCount(1, $huecos);
        $this->assertSame(['start_ms' => 25400, 'end_ms' => 30000], $huecos[0]);
        $this->assertSame(4600, $resultado->gapTotalMs(40396));
    }

    public function test_las_pausas_al_hablar_no_son_huecos(): void
    {
        // Entre frases se calla uno medio segundo. Marcar eso como pérdida
        // llenaría el sistema de avisos que no significan nada.
        $resultado = new TranscriptionResult(
            text: 'Una. Dos. Tres.',
            provider: 'p', model: 'm',
            segments: [
                new TranscriptionSegment(0, 'Una.', 0, 3000),
                new TranscriptionSegment(1, 'Dos.', 3600, 6000),
                new TranscriptionSegment(2, 'Tres.', 6900, 10000),
            ],
        );

        $this->assertSame([], $resultado->coverageGaps(10200));
        $this->assertSame(0, $resultado->gapTotalMs(10200));
    }

    public function test_sin_tramos_la_cobertura_es_desconocida_y_no_cero(): void
    {
        // Decir «no hay pérdida» cuando no se ha podido mirar sería peor que
        // no decir nada.
        $resultado = new TranscriptionResult(text: 'Algo.', provider: 'p', model: 'm');

        $this->assertNull($resultado->coverageGaps(30000));
        $this->assertNull($resultado->gapTotalMs(30000));
    }

    public function test_tambien_cuenta_lo_que_falta_al_principio_y_al_final(): void
    {
        $resultado = new TranscriptionResult(
            text: 'En medio.',
            provider: 'p', model: 'm',
            segments: [new TranscriptionSegment(0, 'En medio.', 5000, 8000)],
        );

        $huecos = $resultado->coverageGaps(20000);

        $this->assertSame(
            [['start_ms' => 0, 'end_ms' => 5000], ['start_ms' => 8000, 'end_ms' => 20000]],
            $huecos,
        );
        $this->assertSame(17000, $resultado->gapTotalMs(20000));
    }

    public function test_los_tramos_desordenados_no_inventan_huecos(): void
    {
        // Nada garantiza que el proveedor los dé en orden.
        $resultado = new TranscriptionResult(
            text: 'Dos. Una.',
            provider: 'p', model: 'm',
            segments: [
                new TranscriptionSegment(1, 'Dos.', 5000, 10000),
                new TranscriptionSegment(0, 'Una.', 0, 5000),
            ],
        );

        $this->assertSame([], $resultado->coverageGaps(10000));
    }

    public function test_la_cobertura_va_a_la_fila_para_poder_consultarla(): void
    {
        $resultado = new TranscriptionResult(
            text: 'Primera. Segunda.',
            provider: 'p', model: 'm',
            segments: [
                new TranscriptionSegment(0, 'Primera.', 0, 25400),
                new TranscriptionSegment(1, 'Segunda.', 30000, 40300),
            ],
        );

        $fila = $resultado->toRow(40396);

        $this->assertSame(4600, $fila['gap_total_ms']);
        $this->assertSame(
            [['start_ms' => 25400, 'end_ms' => 30000]],
            json_decode((string) $fila['coverage_gaps'], true),
        );

        // Sin duración no se puede comprobar, y eso se guarda como NULL: no es
        // lo mismo que no tener huecos.
        $sinDuracion = $resultado->toRow();

        $this->assertNull($sinDuracion['gap_total_ms']);
        $this->assertNull($sinDuracion['coverage_gaps']);
    }

    public function test_el_coste_va_en_micros_enteros(): void
    {
        // En coma flotante, sumar miles de costes de cinco decimales acumula
        // error. La API los da en dólares.
        $this->assertSame(508, TranscriptionResult::costToMicros(0.000508));
        $this->assertSame(0, TranscriptionResult::costToMicros(0.0));
        $this->assertNull(TranscriptionResult::costToMicros(null));
    }

    public function test_los_tramos_van_y_vuelven_del_json_igual(): void
    {
        $original = new TranscriptionSegment(3, 'algo', 1000, 2000, 0.8, 10, 14);

        $this->assertEquals($original, TranscriptionSegment::fromArray($original->toArray()));
    }

    // ------------------------------------------------------- el falso

    public function test_el_falso_cumple_la_interfaz(): void
    {
        $this->assertInstanceOf(TranscriptionProvider::class, new FakeTranscriptionProvider());
    }

    public function test_el_falso_devuelve_algo_creible_sin_configurar_nada(): void
    {
        $resultado = (new FakeTranscriptionProvider())->transcribe($this->audio());

        $this->assertNotSame('', $resultado->text);
        $this->assertSame('fake', $resultado->provider);
        $this->assertSame('es', $resultado->language);
        $this->assertTrue($resultado->isFullyAnchored());

        // Coste 0 y proveedor 'fake': una fila así no se puede confundir con
        // una real al sumar gastos.
        $this->assertSame(0, $resultado->costMicros);
    }

    public function test_los_tramos_del_falso_reparten_la_duracion_del_audio(): void
    {
        $resultado = (new FakeTranscriptionProvider('Una. Dos.'))
            ->transcribe($this->audio(duracionMs: 10000));

        $this->assertCount(2, $resultado->segments);
        $this->assertSame(0, $resultado->segments[0]->startMs);
        $this->assertSame(10000, $resultado->segments[1]->endMs);
    }

    public function test_el_falso_recuerda_con_que_se_le_llamo(): void
    {
        // Media parte de lo que puede salir mal en la fase 2 es mandar el audio
        // equivocado, no interpretar mal la respuesta.
        $falso = new FakeTranscriptionProvider();
        $audio = $this->audio();

        $falso->transcribe($audio, 'es');

        $this->assertSame(1, $falso->callCount());
        $this->assertSame($audio, $falso->lastCall()['audio']);
        $this->assertSame('es', $falso->lastCall()['language']);
    }

    public function test_el_falso_puede_fallar_a_proposito(): void
    {
        $falso = (new FakeTranscriptionProvider())->willFail('la API no responde');

        try {
            $falso->transcribe($this->audio());
            $this->fail('Debería haber fallado');
        } catch (TranscriptionFailed $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function test_el_falso_puede_fallar_dos_veces_y_luego_ir_bien(): void
    {
        // El caso que de verdad preocupa en la cola. Con un valor fijo no se
        // puede escribir.
        $falso = (new FakeTranscriptionProvider())
            ->willFail()
            ->willFail();

        $fallos = 0;

        for ($intento = 0; $intento < 3; $intento++) {
            try {
                $resultado = $falso->transcribe($this->audio());
            } catch (TranscriptionFailed $e) {
                $fallos++;
            }
        }

        $this->assertSame(2, $fallos);
        $this->assertNotSame('', $resultado->text);
        $this->assertSame(3, $falso->callCount());
    }

    // ------------------------------------------------- clasificar fallos

    public function test_un_fallo_se_sabe_a_si_mismo_reintentable_o_no(): void
    {
        // Reintentar cinco veces algo que nunca se va a aceptar son cinco
        // llamadas de pago tiradas; dar por muerta una que solo falló porque
        // la API estaba caída pierde la grabación.
        $this->assertTrue(TranscriptionFailed::fromStatus(500, 'x')->retryable);
        $this->assertTrue(TranscriptionFailed::fromStatus(503, 'x')->retryable);
        $this->assertTrue(TranscriptionFailed::fromStatus(429, 'x')->retryable);

        // Una clave mal puesta se arregla en el servidor sin tocar la cola.
        $this->assertTrue(TranscriptionFailed::fromStatus(401, 'x')->retryable);

        $this->assertFalse(TranscriptionFailed::fromStatus(400, 'x')->retryable);
        $this->assertFalse(TranscriptionFailed::fromStatus(415, 'x')->retryable);
        $this->assertFalse(TranscriptionFailed::fromStatus(413, 'x')->retryable);
    }
}
