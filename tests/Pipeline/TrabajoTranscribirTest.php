<?php

declare(strict_types=1);

namespace MaiMind\Tests\Pipeline;

use MaiMind\Domain\Jobs\Handlers\TranscribeHandler;
use MaiMind\Domain\Jobs\JobQueue;
use MaiMind\Domain\Jobs\Worker;
use MaiMind\Domain\User;
use MaiMind\Pipeline\Transcription\FakeTranscriptionProvider;
use MaiMind\Pipeline\Transcription\TranscriptionFailed;
use MaiMind\Pipeline\Transcription\TranscriptionResult;
use MaiMind\Pipeline\Transcription\TranscriptionSegment;
use MaiMind\Repository\EntryRepository;
use MaiMind\Repository\TranscriptRepository;
use MaiMind\Support\Config;
use MaiMind\Support\Ulid;
use MaiMind\Tests\AppTestCase;
use PDO;
use Psr\Log\NullLogger;

/**
 * El trabajo `transcribe` (tarea 2.2).
 *
 * Estos trabajos llevan encolándose desde la tarea 1.2 y esperando a que
 * existiera un manejador. Lo que se prueba aquí es el ciclo entero: la entrada
 * cambia de estado, la transcripción se guarda, y el paso siguiente queda
 * encolado.
 */
final class TrabajoTranscribirTest extends AppTestCase
{
    private FakeTranscriptionProvider $transcriptor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transcriptor = new FakeTranscriptionProvider('Hoy he dormido fatal. Estoy agotado.');
    }

    protected function tearDown(): void
    {
        foreach ($this->pdo->query(
            "SELECT id FROM users WHERE email LIKE '" . self::EMAIL_PREFIX . "%'"
        )->fetchAll(PDO::FETCH_COLUMN) as $id) {
            // transcripts cuelga de entries con ON DELETE CASCADE, pero
            // AppTestCase borra entries a mano.
            $this->pdo->prepare('DELETE FROM transcripts WHERE user_id = ?')->execute([$id]);
        }

        parent::tearDown();
    }

    private function manejador(): TranscribeHandler
    {
        return new TranscribeHandler($this->pdo, $this->transcriptor, new NullLogger());
    }

    /** Crea una entrada con audio de verdad en disco. */
    private function entradaConAudio(User $user, string $estadoAudio = 'present'): string
    {
        $uid  = Ulid::generate();
        $ruta = sprintf('audio/%s/2026/08/%s.webm', $user->uid, $uid);
        $abs  = Config::basePath('storage/' . $ruta);

        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0770, true);
        }

        $bytes = 'audio-de-prueba-' . $uid;

        file_put_contents($abs, $bytes);

        (new EntryRepository($this->pdo, $user->id))->createDraft(
            uid: $uid,
            capturedAt: '2026-08-30 10:00:00',
            localDate: '2026-08-30',
            timezone: 'Europe/Madrid',
            utcOffsetMinutes: 120,
            extra: [
                'audio_path'        => $estadoAudio === 'present' ? $ruta : null,
                'audio_bytes'       => strlen($bytes),
                'audio_sha256'      => hash('sha256', $bytes),
                'audio_mime'        => 'audio/webm',
                'audio_duration_ms' => 30000,
                'audio_state'       => $estadoAudio,
                'pipeline_state'    => 'captured',
            ],
        );

        return $uid;
    }

    /** @param array<string,mixed> $extra */
    private function ejecutar(User $user, string $uid, array $extra = []): void
    {
        $this->manejador()->handle(
            ['entry' => $uid, ...$extra],
            ['user_id' => $user->id],
        );
    }

    /** @return array<string,mixed> */
    private function entrada(User $user, string $uid): array
    {
        return (new EntryRepository($this->pdo, $user->id))->findByUid($uid) ?: [];
    }

    // ------------------------------------------------------- el camino feliz

    public function test_transcribe_y_guarda(): void
    {
        $a   = $this->crearUsuario('a');
        $uid = $this->entradaConAudio($a);

        $this->ejecutar($a, $uid);

        $entrada = $this->entrada($a, $uid);

        $this->assertSame('transcribed', $entrada['pipeline_state']);

        $transcripcion = (new TranscriptRepository($this->pdo, $a->id))
            ->currentFor((int) $entrada['id']);

        $this->assertNotNull($transcripcion);
        $this->assertSame('Hoy he dormido fatal. Estoy agotado.', $transcripcion['text']);
        $this->assertSame('fake', $transcripcion['provider']);
        $this->assertSame(6, (int) $transcripcion['word_count']);
        $this->assertSame(1, (int) $transcripcion['is_current']);
    }

    public function test_los_tramos_se_guardan_anclados_al_texto(): void
    {
        // Sin offsets, una tarjeta de revisión no puede enseñar la cita que la
        // originó, y el bucle de revisión es el producto.
        $a   = $this->crearUsuario('a');
        $uid = $this->entradaConAudio($a);

        $this->ejecutar($a, $uid);

        $fila = (new TranscriptRepository($this->pdo, $a->id))
            ->currentFor((int) $this->entrada($a, $uid)['id']);

        $segmentos = json_decode((string) $fila['segments'], true);

        $this->assertCount(2, $segmentos);
        $this->assertSame(0, $segmentos[0]['char_start']);
        $this->assertSame(21, $segmentos[0]['char_end']);

        // Y el segundo no empieza en cero: si empezara, el anclaje estaría
        // situando todos los tramos al principio del texto.
        $this->assertGreaterThan(0, $segmentos[1]['char_start']);
        $this->assertSame(
            'Hoy he dormido fatal.',
            mb_substr(
                (string) $fila['text'],
                $segmentos[0]['char_start'],
                $segmentos[0]['char_end'] - $segmentos[0]['char_start'],
            ),
        );
    }

    public function test_le_pasa_al_transcriptor_el_audio_y_el_idioma_del_usuario(): void
    {
        $a   = $this->crearUsuario('a');
        $uid = $this->entradaConAudio($a);

        $this->ejecutar($a, $uid);

        $llamada = $this->transcriptor->lastCall();

        $this->assertStringContainsString($a->uid, $llamada['audio']->path);
        $this->assertSame(30000, $llamada['audio']->durationMs);
        $this->assertSame('es', $llamada['language']);
    }

    public function test_encola_el_paso_siguiente(): void
    {
        // 'extract' no tiene manejador hasta la fase 3: el worker lo aparta sin
        // gastarle intentos y se ejecutará solo cuando esa fase se despliegue.
        $a   = $this->crearUsuario('a');
        $uid = $this->entradaConAudio($a);

        $this->ejecutar($a, $uid);

        $stmt = $this->pdo->prepare('SELECT payload FROM jobs WHERE user_id = ? AND type = ?');
        $stmt->execute([$a->id, 'extract']);

        $trabajos = $stmt->fetchAll();

        $this->assertCount(1, $trabajos);
        $this->assertSame(['entry' => $uid], json_decode((string) $trabajos[0]['payload'], true));
    }

    // ------------------------------------------------------- idempotencia

    public function test_repetirlo_no_vuelve_a_pagar_una_inferencia(): void
    {
        // El trabajo puede repetirse si el worker murió justo después de
        // guardar y antes de marcarlo hecho.
        $a   = $this->crearUsuario('a');
        $uid = $this->entradaConAudio($a);

        $this->ejecutar($a, $uid);
        $this->ejecutar($a, $uid);

        $this->assertSame(1, $this->transcriptor->callCount());

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM transcripts WHERE user_id = ?');
        $stmt->execute([$a->id]);

        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    // ------------------------------------------------------------- fallos

    public function test_un_audio_purgado_no_se_reintenta_eternamente(): void
    {
        $a   = $this->crearUsuario('a');
        $uid = $this->entradaConAudio($a, estadoAudio: 'purged');

        // No lanza: no hay nada que reintentar, así que el trabajo termina bien
        // y la entrada queda marcada.
        $this->ejecutar($a, $uid);

        $entrada = $this->entrada($a, $uid);

        $this->assertSame('failed', $entrada['pipeline_state']);
        $this->assertStringContainsString('audio', (string) $entrada['error_message']);
        $this->assertSame(0, $this->transcriptor->callCount());
    }

    public function test_un_fallo_temporal_deja_la_entrada_como_estaba(): void
    {
        // No en 'transcribing': eso haría creer que hay un worker trabajando en
        // ella cuando no lo hay.
        $a   = $this->crearUsuario('a');
        $uid = $this->entradaConAudio($a);

        $this->transcriptor->willFail('la API no responde');

        try {
            $this->ejecutar($a, $uid);
            $this->fail('Debería haber propagado el fallo para que la cola reintente');
        } catch (TranscriptionFailed $e) {
            $this->assertTrue($e->retryable);
        }

        $this->assertSame('captured', $this->entrada($a, $uid)['pipeline_state']);
    }

    public function test_un_fallo_definitivo_marca_la_entrada(): void
    {
        $a   = $this->crearUsuario('a');
        $uid = $this->entradaConAudio($a);

        $this->transcriptor->willFail('formato no admitido', retryable: false);

        try {
            $this->ejecutar($a, $uid);
            $this->fail('Debería haber lanzado');
        } catch (TranscriptionFailed $e) {
            $this->assertFalse($e->retryable);
        }

        $entrada = $this->entrada($a, $uid);

        $this->assertSame('failed', $entrada['pipeline_state']);
        $this->assertStringContainsString('formato', (string) $entrada['error_message']);
    }

    public function test_falla_y_al_reintentarlo_va_bien(): void
    {
        // El caso que de verdad ocurre: la API estaba caída un minuto.
        $a   = $this->crearUsuario('a');
        $uid = $this->entradaConAudio($a);

        $this->transcriptor->willFail();

        try {
            $this->ejecutar($a, $uid);
        } catch (TranscriptionFailed) {
            // Esperado.
        }

        $this->ejecutar($a, $uid);

        $entrada = $this->entrada($a, $uid);

        $this->assertSame('transcribed', $entrada['pipeline_state']);
        // Y el error de la vez anterior no se queda pegado.
        $this->assertNull($entrada['error_message']);
    }

    public function test_una_entrada_borrada_mientras_esperaba_no_es_un_fallo(): void
    {
        $a   = $this->crearUsuario('a');
        $uid = $this->entradaConAudio($a);

        $this->pdo->prepare('DELETE FROM entries WHERE uid = ?')->execute([$uid]);

        // No lanza: no hay nada que hacer y nada que arreglar.
        $this->ejecutar($a, $uid);

        $this->assertSame(0, $this->transcriptor->callCount());
    }


    // ------------------------------------------- cobertura del audio

    public function test_guarda_que_falta_audio_cuando_el_modelo_se_salta_un_trozo(): void
    {
        // El caso real del 2026-08-30: el modelo devolvió tramos que no cubren
        // toda la grabación, y el texto no lo delataba.
        $a   = $this->crearUsuario('a');
        $uid = $this->entradaConAudio($a);

        $this->transcriptor->willReturn(new TranscriptionResult(
            text: 'Primera parte. Segunda parte.',
            provider: 'fake',
            model: 'modelo-que-se-salta-cosas',
            segments: [
                new TranscriptionSegment(0, 'Primera parte.', 0, 12000),
                new TranscriptionSegment(1, 'Segunda parte.', 22000, 30000),
            ],
        ));

        $this->ejecutar($a, $uid);

        $fila = (new TranscriptRepository($this->pdo, $a->id))
            ->currentFor((int) $this->entrada($a, $uid)['id']);

        $this->assertSame(10000, (int) $fila['gap_total_ms']);
        $this->assertSame(
            [['start_ms' => 12000, 'end_ms' => 22000]],
            json_decode((string) $fila['coverage_gaps'], true),
        );
    }

    public function test_una_transcripcion_completa_no_deja_huecos(): void
    {
        $a   = $this->crearUsuario('a');
        $uid = $this->entradaConAudio($a);

        $this->ejecutar($a, $uid);

        $fila = (new TranscriptRepository($this->pdo, $a->id))
            ->currentFor((int) $this->entrada($a, $uid)['id']);

        $this->assertSame(0, (int) $fila['gap_total_ms']);
        $this->assertSame([], json_decode((string) $fila['coverage_gaps'], true));
    }

    public function test_las_transcripciones_a_las_que_falta_audio_se_pueden_listar(): void
    {
        $a   = $this->crearUsuario('a');
        $uid = $this->entradaConAudio($a);

        $this->transcriptor->willReturn(new TranscriptionResult(
            text: 'Solo el principio.',
            provider: 'fake', model: 'm',
            segments: [new TranscriptionSegment(0, 'Solo el principio.', 0, 5000)],
        ));

        $this->ejecutar($a, $uid);

        $conHuecos = (new TranscriptRepository($this->pdo, $a->id))->withCoverageGaps();

        $this->assertCount(1, $conHuecos);
        $this->assertSame(25000, (int) $conHuecos[0]['gap_total_ms']);
    }

    // ----------------------------------------------------- por la cola

    public function test_de_extremo_a_extremo_por_la_cola(): void
    {
        $a   = $this->crearUsuario('a');
        $uid = $this->entradaConAudio($a);

        $queue = new JobQueue($this->pdo);

        $queue->push(
            type: 'transcribe',
            payload: ['entry' => $uid],
            userId: $a->id,
            dedupeKey: 'transcribe:' . $uid,
        );

        $recuento = (new Worker($queue, new NullLogger(), 'worker-de-pruebas', ['transcribe']))
            ->register($this->manejador())
            ->drain();

        $this->assertSame(1, $recuento['done']);
        $this->assertSame('transcribed', $this->entrada($a, $uid)['pipeline_state']);
    }

    public function test_la_transcripcion_de_uno_no_es_visible_para_otro(): void
    {
        $a = $this->crearUsuario('a');
        $b = $this->crearUsuario('b');

        $uid = $this->entradaConAudio($a);

        $this->ejecutar($a, $uid);

        $entradaId = (int) $this->entrada($a, $uid)['id'];

        $this->assertNotNull((new TranscriptRepository($this->pdo, $a->id))->currentFor($entradaId));
        $this->assertNull((new TranscriptRepository($this->pdo, $b->id))->currentFor($entradaId));
    }

    public function test_el_coste_se_acumula_por_usuario(): void
    {
        // Para saber el coste unitario real del producto y para detectar abuso.
        $a   = $this->crearUsuario('a');
        $uid = $this->entradaConAudio($a);

        $this->ejecutar($a, $uid);

        // El falso cuesta 0, y que se note: no se puede confundir con una real.
        $this->assertSame(0, (new TranscriptRepository($this->pdo, $a->id))->totalCostMicros());
    }
}
