<?php

declare(strict_types=1);

namespace MaiMind\Tests\Http;

use MaiMind\Domain\Auth\SessionManager;
use MaiMind\Domain\User;
use MaiMind\Pipeline\Transcription\TranscriptionResult;
use MaiMind\Pipeline\Transcription\TranscriptionSegment;
use MaiMind\Repository\EntryRepository;
use MaiMind\Repository\TranscriptRepository;
use MaiMind\Support\Ulid;
use MaiMind\Tests\AppTestCase;
use PDO;

/**
 * La pantalla de una grabación y la corrección a mano (tarea 2.3).
 *
 * El sistema entero se apoya en citas literales de este texto: si el
 * transcriptor oyó mal, todo lo que venga después hereda el error. Esta
 * pantalla es donde se corta.
 */
final class EntradaTest extends AppTestCase
{
    protected function tearDown(): void
    {
        foreach ($this->pdo->query(
            "SELECT id FROM users WHERE email LIKE '" . self::EMAIL_PREFIX . "%'"
        )->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->pdo->prepare('DELETE FROM transcripts WHERE user_id = ?')->execute([$id]);
        }

        parent::tearDown();
    }

    private function entrada(User $user, string $estado = 'transcribed', ?int $duracionMs = 30000): string
    {
        $uid = Ulid::generate();

        (new EntryRepository($this->pdo, $user->id))->createDraft(
            uid: $uid,
            capturedAt: '2026-08-30 10:00:00',
            localDate: '2026-08-30',
            timezone: 'Europe/Madrid',
            utcOffsetMinutes: 120,
            moodHint: 4,
            extra: ['pipeline_state' => $estado, 'audio_duration_ms' => $duracionMs],
        );

        return $uid;
    }

    private function idDe(User $user, string $uid): int
    {
        return (int) (new EntryRepository($this->pdo, $user->id))->findByUid($uid)['id'];
    }

    /** @param list<TranscriptionSegment> $segmentos */
    private function conTranscripcion(
        User $user,
        string $uid,
        string $texto = 'Hoy he dormido fatal y estoy agotado.',
        array $segmentos = [],
        ?int $duracionMs = 30000,
    ): void {
        (new TranscriptRepository($this->pdo, $user->id))->storeAsCurrent(
            $this->idDe($user, $uid),
            new TranscriptionResult(
                text: $texto,
                provider: 'openrouter',
                model: 'openai/whisper-1',
                segments: $segmentos,
                language: 'es',
            ),
            $duracionMs,
        );
    }

    private function actual(User $user, string $uid): ?array
    {
        return (new TranscriptRepository($this->pdo, $user->id))->currentFor($this->idDe($user, $uid));
    }

    // -------------------------------------------------------------- la vista

    public function test_ensena_la_transcripcion(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a);

        $this->conTranscripcion($a, $uid);

        $html = $this->getComo($token, '/entrada/' . $uid)->body;

        $this->assertStringContainsString('Hoy he dormido fatal y estoy agotado.', $html);
        $this->assertStringContainsString('openai/whisper-1', $html);
        // El toque previo, que es la única señal que no pasó por un modelo.
        $this->assertStringContainsString('4 de 5', $html);
    }

    public function test_una_entrada_sin_transcribir_lo_dice_en_vez_de_ensenar_nada(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a, estado: 'captured');

        $html = $this->getComo($token, '/entrada/' . $uid)->body;

        $this->assertStringContainsString(t('entry.not_yet'), $html);
        $this->assertStringNotContainsString('<textarea', $html);
    }

    public function test_una_entrada_que_fallo_lo_dice(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a, estado: 'failed');

        $this->assertStringContainsString(t('entry.failed'), $this->getComo($token, '/entrada/' . $uid)->body);
    }

    public function test_la_entrada_de_otro_da_404_y_no_403(): void
    {
        // Un 403 confirmaría que ese identificador existe.
        $a = $this->crearUsuario('a');
        $b = $this->crearUsuario('b');

        $uid = $this->entrada($a);
        $this->conTranscripcion($a, $uid, 'algo muy privado');

        $respuesta = $this->getComo($this->iniciarSesion($b), '/entrada/' . $uid);

        $this->assertSame(404, $respuesta->status);
        $this->assertStringNotContainsString('algo muy privado', $respuesta->body);
    }

    public function test_sin_sesion_no_se_ve_nada(): void
    {
        $a   = $this->crearUsuario('a');
        $uid = $this->entrada($a);

        $this->conTranscripcion($a, $uid, 'algo muy privado');

        $respuesta = $this->get('/entrada/' . $uid);

        $this->assertSame(302, $respuesta->status);
        $this->assertStringNotContainsString('algo muy privado', $respuesta->body);
    }

    // ----------------------------------------------- el aviso de huecos

    public function test_avisa_de_lo_que_falta_antes_de_ensenar_el_texto(): void
    {
        // Quien lo lea tiene que saber que está incompleto ANTES de leerlo.
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a, duracionMs: 40396);

        $this->conTranscripcion($a, $uid, 'Primera parte. Segunda parte.', [
            new TranscriptionSegment(0, 'Primera parte.', 0, 25400),
            new TranscriptionSegment(1, 'Segunda parte.', 30000, 40300),
        ], duracionMs: 40396);

        $html = $this->getComo($token, '/entrada/' . $uid)->body;

        $this->assertStringContainsString(t('entry.gap_notice', ['seconds' => 5]), $html);
        // Y dice dónde, en minutos y segundos.
        $this->assertStringContainsString('0:25', $html);
        $this->assertStringContainsString('0:30', $html);

        $this->assertLessThan(
            strpos($html, 'Primera parte'),
            strpos($html, t('entry.gap_explain')),
            'El aviso aparece después del texto que avisa que está incompleto',
        );
    }

    public function test_una_transcripcion_completa_no_avisa_de_nada(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a);

        $this->conTranscripcion($a, $uid, 'Todo seguido.', [
            new TranscriptionSegment(0, 'Todo seguido.', 0, 30000),
        ]);

        $this->assertStringNotContainsString(
            t('entry.gap_explain'),
            $this->getComo($token, '/entrada/' . $uid)->body,
        );
    }

    public function test_tras_corregirlo_a_mano_deja_de_avisar_del_hueco(): void
    {
        // Una persona ya ha leído y arreglado ese texto. Seguir avisándole es
        // insistir sobre algo atendido, que es lo que prohíbe el §3 del tono.
        // El dato se conserva en la fila; lo que se deja de hacer es dar la
        // matraca con él.
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a, duracionMs: 40396);

        $this->conTranscripcion($a, $uid, 'Primera parte. Segunda parte.', [
            new TranscriptionSegment(0, 'Primera parte.', 0, 25400),
            new TranscriptionSegment(1, 'Segunda parte.', 30000, 40300),
        ], duracionMs: 40396);

        $this->assertStringContainsString(
            t('entry.gap_explain'),
            $this->getComo($token, '/entrada/' . $uid)->body,
        );

        $this->corregir($a, $token, $uid, 'Primera parte. Lo que faltaba. Segunda parte.');

        $this->assertStringNotContainsString(
            t('entry.gap_explain'),
            $this->getComo($token, '/entrada/' . $uid)->body,
        );

        // Pero el dato sigue guardado: la máquina se dejó ese audio, y eso no
        // deja de ser verdad porque alguien haya escrito encima.
        $this->assertGreaterThan(0, (int) $this->actual($a, $uid)['gap_total_ms']);
    }

    // ------------------------------------------------------- volver

    public function test_volver_lleva_a_donde_se_vino(): void
    {
        // Volver es volver de donde vengo, no ir siempre al mismo sitio.
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a);

        $desdeInicio = $this->get('/entrada/' . $uid, [SessionManager::COOKIE => $token], [
            'host'    => 'maimind.test',
            'referer' => 'https://maimind.test/',
        ])->body;

        $this->assertStringContainsString('href="/"', $desdeInicio);

        $desdeListado = $this->get('/entrada/' . $uid, [SessionManager::COOKIE => $token], [
            'host'    => 'maimind.test',
            'referer' => 'https://maimind.test/grabaciones',
        ])->body;

        $this->assertStringContainsString('href="/grabaciones"', $desdeListado);
    }

    public function test_volver_no_lleva_fuera_del_sitio(): void
    {
        // El Referer lo manda el navegador: no puede decidir a dónde mandamos
        // a nadie. Cualquier cosa que no sea una ruta propia conocida cae en
        // la pantalla de grabar.
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a);

        $ajenos = [
            'https://sitio-ajeno.test/grabaciones',
            'https://maimind.test/otra',
            '',
        ];

        foreach ($ajenos as $referer) {
            $html = $this->get('/entrada/' . $uid, [SessionManager::COOKIE => $token], [
                'host'    => 'maimind.test',
                'referer' => $referer,
            ])->body;

            $this->assertStringNotContainsString('sitio-ajeno', $html);
            $this->assertStringContainsString('href="/"', $html);
        }
    }

    // ------------------------------------------------- la pantalla de inicio

    public function test_el_inicio_dice_que_ha_pasado_con_la_ultima(): void
    {
        // Antes enseñaba la fecha y un número suelto que era el total y no lo
        // decía: no se sabía ni si estaba transcrita.
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a, estado: 'captured');

        $this->assertStringContainsString(t('entry.not_yet'), $this->getComo($token, '/')->body);

        $this->conTranscripcion($a, $uid, 'Una dos tres cuatro cinco.');

        $html = $this->getComo($token, '/')->body;

        $this->assertStringContainsString(t('entry.words', ['count' => 5]), $html);
        $this->assertStringContainsString('/entrada/' . $uid, $html);
    }

    public function test_el_inicio_dice_lo_que_es_ese_numero(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $this->entrada($a);
        $this->entrada($a);

        // El total va etiquetado y como enlace al listado, no suelto.
        $this->assertStringContainsString(
            t('list.see_all_count', ['count' => 2]),
            $this->getComo($token, '/')->body,
        );
    }

    public function test_el_inicio_avisa_si_la_ultima_fallo(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $this->entrada($a, estado: 'failed');

        $this->assertStringContainsString(t('entry.failed'), $this->getComo($token, '/')->body);
    }

    // ---------------------------------------------------- la corrección

    private function corregir(User $user, string $token, string $uid, string $texto)
    {
        return $this->post('/entrada/' . $uid . '/transcripcion', [
            '_csrf' => $this->csrfDeSesion($token),
            'text'  => $texto,
        ], [SessionManager::COOKIE => $token]);
    }

    public function test_corregir_guarda_el_texto_nuevo(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a);

        $this->conTranscripcion($a, $uid, 'Hoy he dormido fatal.');

        $respuesta = $this->corregir($a, $token, $uid, 'Hoy he dormido regular.');

        $this->assertSame(302, $respuesta->status);
        $this->assertSame('Hoy he dormido regular.', $this->actual($a, $uid)['text']);
    }

    public function test_corregir_no_destruye_lo_que_dijo_la_maquina(): void
    {
        // Lo que oyó el ASR y lo que la persona dice que dijo son dos datos
        // distintos, y la diferencia dice dónde falla el ASR con esta voz.
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a);

        $this->conTranscripcion($a, $uid, 'Hoy he dormido fatal.');
        $this->corregir($a, $token, $uid, 'Hoy he dormido regular.');

        $historial = (new TranscriptRepository($this->pdo, $a->id))
            ->historyFor($this->idDe($a, $uid));

        $this->assertCount(2, $historial);
        $this->assertSame('user', $historial[0]['provider']);
        $this->assertSame('openrouter', $historial[1]['provider']);
        $this->assertSame(1, (int) $historial[0]['is_current']);
        $this->assertSame(0, (int) $historial[1]['is_current']);
    }

    public function test_una_correccion_se_marca_como_hecha_por_la_persona(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a);

        $this->conTranscripcion($a, $uid, 'Hoy he dormido fatal.');
        $this->corregir($a, $token, $uid, 'Hoy he dormido regular.');

        $html = $this->getComo($token, '/entrada/' . $uid)->body;

        // Dice que lo corregiste tú **y sobre qué motor**. Esconder el modelo
        // dejaba sin forma de saber qué produjo qué, que es media razón de
        // guardar el proveedor en cada fila (04-arquitectura.md §1). Y con dos
        // transcriptores en comparación, es justo el dato que hace falta.
        $this->assertStringContainsString(
            t('entry.edited_over', ['model' => 'openai/whisper-1']),
            $html,
        );
    }

    public function test_si_no_hubo_maquina_no_se_inventa_un_modelo(): void
    {
        // Una entrada cuyo único texto lo escribió una persona no tiene motor
        // original que enseñar.
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a);

        (new TranscriptRepository($this->pdo, $a->id))->storeManualEdit(
            $this->idDe($a, $uid),
            'Escrito a mano desde el principio.',
        );

        $html = $this->getComo($token, '/entrada/' . $uid)->body;

        $this->assertStringContainsString(t('entry.edited_by_you'), $html);
        $this->assertStringNotContainsString('sobre', mb_strtolower(
            (string) preg_replace('/.*?entry__source[^>]*>(.*?)<\/p>.*/su', '$1', $html)
        ));
    }

    public function test_una_correccion_no_cuesta_dinero(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a);

        $this->conTranscripcion($a, $uid, 'Hoy he dormido fatal.');
        $this->corregir($a, $token, $uid, 'Hoy he dormido regular.');

        $this->assertNull($this->actual($a, $uid)['cost_micros']);
    }

    public function test_los_tramos_se_vuelven_a_anclar_al_texto_corregido(): void
    {
        // Los tiempos siguen describiendo el audio, pero los offsets no: el
        // tramo que ya no aparezca se queda sin anclaje en vez de señalar a
        // palabras que nadie dijo.
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a);

        $this->conTranscripcion($a, $uid, 'Uno. Dos.', [
            new TranscriptionSegment(0, 'Uno.', 0, 15000),
            new TranscriptionSegment(1, 'Dos.', 15000, 30000),
        ]);

        $this->corregir($a, $token, $uid, 'Uno. Tres.');

        $segmentos = json_decode((string) $this->actual($a, $uid)['segments'], true);

        $this->assertSame(0, $segmentos[0]['char_start'], 'El que sigue estando debería seguir anclado');
        $this->assertNull($segmentos[1]['char_start'], 'El que ya no está no puede tener offsets');
        // Y los tiempos se conservan: siguen describiendo el audio.
        $this->assertSame(15000, $segmentos[1]['start_ms']);
    }

    public function test_guardar_lo_mismo_no_llena_el_historial(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a);

        $this->conTranscripcion($a, $uid, 'Hoy he dormido fatal.');
        $this->corregir($a, $token, $uid, 'Hoy he dormido fatal.');

        $this->assertCount(
            1,
            (new TranscriptRepository($this->pdo, $a->id))->historyFor($this->idDe($a, $uid)),
        );
    }

    public function test_un_texto_vacio_no_borra_la_transcripcion(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a);

        $this->conTranscripcion($a, $uid, 'Hoy he dormido fatal.');
        $this->corregir($a, $token, $uid, '   ');

        $this->assertSame('Hoy he dormido fatal.', $this->actual($a, $uid)['text']);
    }

    public function test_corregir_vuelve_a_encolar_la_extraccion(): void
    {
        // El texto ha cambiado, así que lo que se extraiga de él también.
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a);

        $this->conTranscripcion($a, $uid, 'Hoy he dormido fatal.');
        $this->corregir($a, $token, $uid, 'Hoy he dormido regular.');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM jobs WHERE user_id = ? AND type = ?');
        $stmt->execute([$a->id, 'extract']);

        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_no_se_puede_corregir_la_grabacion_de_otro(): void
    {
        $a = $this->crearUsuario('a');
        $b = $this->crearUsuario('b');

        $uid = $this->entrada($a);
        $this->conTranscripcion($a, $uid, 'Hoy he dormido fatal.');

        $respuesta = $this->corregir($b, $this->iniciarSesion($b), $uid, 'texto metido por otro');

        $this->assertSame(404, $respuesta->status);
        $this->assertSame('Hoy he dormido fatal.', $this->actual($a, $uid)['text']);
    }

    public function test_corregir_sin_csrf_no_hace_nada(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->entrada($a);

        $this->conTranscripcion($a, $uid, 'Hoy he dormido fatal.');

        $respuesta = $this->post('/entrada/' . $uid . '/transcripcion', [
            '_csrf' => 'inventado', 'text' => 'texto metido sin testigo',
        ], [SessionManager::COOKIE => $token]);

        $this->assertSame(419, $respuesta->status);
        $this->assertSame('Hoy he dormido fatal.', $this->actual($a, $uid)['text']);
    }
}
