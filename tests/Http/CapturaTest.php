<?php

declare(strict_types=1);

namespace MaiMind\Tests\Http;

use MaiMind\Domain\Auth\SessionManager;
use MaiMind\Domain\Capture\AudioStore;
use MaiMind\Domain\Jobs\JobQueue;
use MaiMind\Http\Request;
use MaiMind\Repository\EntryRepository;
use MaiMind\Support\Config;
use MaiMind\Tests\AppTestCase;

final class CapturaTest extends AppTestCase
{
    /** @var list<string> */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $f) {
            @unlink($f);
        }

        // El audio de los usuarios de prueba lo limpia AppTestCase.
        parent::tearDown();
    }

    /** Simula un fichero subido. @return array<string,mixed> */
    private function audioFalso(string $mime = 'audio/webm', int $bytes = 4096): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mm-audio-');
        file_put_contents($tmp, random_bytes($bytes));

        $this->temporales[] = $tmp;

        return [
            'name'     => 'captura',
            'type'     => $mime,
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => $bytes,
        ];
    }

    /**
     * @param  array<string,mixed>  $body
     * @param  array<string,mixed>|null  $file
     */
    private function subir(string $token, array $body = [], ?array $file = null)
    {
        return $this->kernel->handle(new Request(
            method: 'POST',
            path: '/api/entries',
            body: [
                '_csrf'       => $this->csrfDeSesion($token),
                'mime'        => 'audio/webm',
                'captured_at' => gmdate('c'),
                'timezone'    => 'Europe/Madrid',
                'utc_offset'  => '120',
                'duration_ms' => '30000',
                ...$body,
            ],
            cookies: [SessionManager::COOKIE => $token],
            files: ['audio' => $file ?? $this->audioFalso()],
        ));
    }

    public function test_una_grabacion_crea_una_fila_y_guarda_el_fichero(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $respuesta = $this->subir($token, ['mood_hint' => '4']);

        // 202 y no 201: la grabación está guardada, pero lo que se hará con
        // ella acaba de encolarse.
        $this->assertSame(202, $respuesta->status);

        $cuerpo = json_decode($respuesta->body, true);
        $this->assertArrayHasKey('uid', $cuerpo);

        $fila = (new EntryRepository($this->pdo, $a->id))->findByUid($cuerpo['uid']);

        $this->assertNotNull($fila);
        $this->assertSame(4, $fila['mood_hint']);
        $this->assertSame('audio/webm', $fila['audio_mime']);
        $this->assertSame(30000, $fila['audio_duration_ms']);
        $this->assertSame('captured', $fila['pipeline_state']);
        $this->assertSame(64, strlen((string) $fila['audio_sha256']));

        // El fichero está en disco, fuera de public/.
        $ruta = Config::basePath('storage/' . $fila['audio_path']);

        $this->assertFileExists($ruta);
        $this->assertStringNotContainsString('/public/', $ruta);
    }

    public function test_el_audio_se_guarda_bajo_el_uid_del_usuario_no_su_id(): void
    {
        // Un listado del directorio no debe revelar cuántas cuentas hay.
        $a = $this->crearUsuario('a');

        $cuerpo = json_decode($this->subir($this->iniciarSesion($a))->body, true);

        $fila = (new EntryRepository($this->pdo, $a->id))->findByUid($cuerpo['uid']);

        $this->assertStringContainsString($a->uid, (string) $fila['audio_path']);
        $this->assertStringNotContainsString('/' . $a->id . '/', (string) $fila['audio_path']);
    }

    public function test_el_fichero_y_la_fila_comparten_identificador(): void
    {
        // Si no coinciden, correlacionar un fichero suelto con su registro
        // obliga a consultar la base de datos por audio_path.
        $a = $this->crearUsuario('a');

        $uid = json_decode($this->subir($this->iniciarSesion($a))->body, true)['uid'];

        $fila = (new EntryRepository($this->pdo, $a->id))->findByUid($uid);

        $this->assertStringContainsString($uid, (string) $fila['audio_path']);
        $this->assertSame($uid, basename((string) $fila['audio_path'], '.webm'));
    }

    public function test_la_ruta_del_audio_esta_bien_formada(): void
    {
        $a = $this->crearUsuario('a');

        $uid  = json_decode($this->subir($this->iniciarSesion($a))->body, true)['uid'];
        $fila = (new EntryRepository($this->pdo, $a->id))->findByUid($uid);

        // audio/{uid usuario}/{año}/{mes}/{uid entrada}.webm
        $this->assertMatchesRegularExpression(
            '#^audio/[0-9A-Z]{26}/\d{4}/\d{2}/[0-9A-Z]{26}\.webm$#',
            (string) $fila['audio_path'],
        );

        $this->assertStringEndsWith('.webm', (string) $fila['audio_path']);
    }

    public function test_el_sha256_guardado_es_el_del_fichero_en_disco(): void
    {
        $a = $this->crearUsuario('a');

        $uid  = json_decode($this->subir($this->iniciarSesion($a))->body, true)['uid'];
        $fila = (new EntryRepository($this->pdo, $a->id))->findByUid($uid);

        $ruta = Config::basePath('storage/' . $fila['audio_path']);

        $this->assertSame(hash_file('sha256', $ruta), (string) $fila['audio_sha256']);
        $this->assertSame((int) filesize($ruta), (int) $fila['audio_bytes']);
    }

    public function test_el_toque_de_animo_es_opcional(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $cuerpo = json_decode($this->subir($token)->body, true);

        $fila = (new EntryRepository($this->pdo, $a->id))->findByUid($cuerpo['uid']);

        $this->assertNull($fila['mood_hint']);
    }

    public function test_un_toque_de_animo_imposible_no_tumba_la_grabacion(): void
    {
        // El audio es lo importante. Un valor fuera de rango se descarta, pero
        // no se pierde lo que la persona acaba de contar.
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $respuesta = $this->subir($token, ['mood_hint' => '99']);

        $this->assertSame(202, $respuesta->status);

        $fila = (new EntryRepository($this->pdo, $a->id))
            ->findByUid(json_decode($respuesta->body, true)['uid']);

        $this->assertNull($fila['mood_hint']);
    }

    public function test_calcula_el_dia_local_del_usuario(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        // 23:30 UTC = 01:30 del día siguiente en Madrid.
        $ayer = gmdate('Y-m-d', time() - 86400);

        $respuesta = $this->subir($token, [
            'captured_at' => $ayer . 'T23:30:00Z',
            'utc_offset'  => '120',
        ]);

        $cuerpo = json_decode($respuesta->body, true);

        $this->assertSame(gmdate('Y-m-d', strtotime($ayer) + 86400), $cuerpo['local_date']);
    }

    public function test_rechaza_un_formato_que_no_admite_la_transcripcion(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $respuesta = $this->subir($token, ['mime' => 'application/zip'],
            $this->audioFalso('application/zip'));

        $this->assertSame(415, $respuesta->status);
        $this->assertSame(0, (new EntryRepository($this->pdo, $a->id))->countAll());
    }

    public function test_rechaza_un_audio_demasiado_grande(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $grande = $this->audioFalso('audio/webm', 1024);
        $grande['size'] = 30 * 1024 * 1024;   // por encima del límite de OpenRouter

        $this->assertSame(413, $this->subir($token, [], $grande)->status);
    }

    public function test_rechaza_un_audio_vacio(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $vacio = $this->audioFalso('audio/webm', 1);
        $vacio['size'] = 0;

        $this->assertSame(422, $this->subir($token, [], $vacio)->status);
    }

    public function test_distingue_no_ha_llegado_de_demasiado_grande(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        // El usuario acaba de hablar dos minutos: merece saber qué pasó.
        $ini = ['name' => 'x', 'type' => 'audio/webm', 'tmp_name' => '',
                'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0];

        $respuesta = $this->subir($token, [], $ini);

        $this->assertSame(422, $respuesta->status);
        $this->assertStringContainsString('larga', json_decode($respuesta->body, true)['error']);
    }

    public function test_sin_sesion_no_se_puede_subir_nada(): void
    {
        $respuesta = $this->kernel->handle(new Request(
            method: 'POST',
            path: '/api/entries',
            body: ['_csrf' => 'lo-que-sea'],
            files: ['audio' => $this->audioFalso()],
        ));

        $this->assertSame(401, $respuesta->status);
    }

    public function test_sin_csrf_no_se_puede_subir_nada(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $respuesta = $this->kernel->handle(new Request(
            method: 'POST',
            path: '/api/entries',
            body: ['mime' => 'audio/webm'],
            cookies: [SessionManager::COOKIE => $token],
            files: ['audio' => $this->audioFalso()],
        ));

        $this->assertSame(419, $respuesta->status);
        $this->assertSame(0, (new EntryRepository($this->pdo, $a->id))->countAll());
    }

    public function test_la_grabacion_de_A_no_aparece_en_la_de_B(): void
    {
        $a = $this->crearUsuario('a');
        $b = $this->crearUsuario('b');

        $uidA = json_decode($this->subir($this->iniciarSesion($a))->body, true)['uid'];

        $listadoB = $this->getComo($this->iniciarSesion($b), '/api/entries');

        $this->assertStringNotContainsString($uidA, $listadoB->body);
        $this->assertSame(404, $this->getComo($this->iniciarSesion($b), '/api/entries/' . $uidA)->status);
    }

    public function test_marca_cuando_hay_que_purgar_el_audio(): void
    {
        $a = $this->crearUsuario('a');

        $cuerpo = json_decode($this->subir($this->iniciarSesion($a))->body, true);

        $fila = (new EntryRepository($this->pdo, $a->id))->findByUid($cuerpo['uid']);

        $this->assertSame('present', $fila['audio_state']);
        $this->assertNotNull($fila['audio_purge_after']);

        $dias = (int) config('services.audio.retention_days');
        $this->assertSame(gmdate('Y-m-d', time() + $dias * 86400), (string) $fila['audio_purge_after']);
    }

    // ------------------------------------------------------- AudioStore

    public function test_los_tipos_admitidos_coinciden_con_lo_que_graban_los_navegadores(): void
    {
        // Chrome graba webm/opus; Safari anterior a la 18.4, mp4/aac.
        $this->assertTrue(AudioStore::isAccepted('audio/webm;codecs=opus'));
        $this->assertTrue(AudioStore::isAccepted('audio/mp4'));
        $this->assertTrue(AudioStore::isAccepted('audio/ogg;codecs=opus'));

        $this->assertFalse(AudioStore::isAccepted('video/mp4'));
        $this->assertFalse(AudioStore::isAccepted('text/html'));

        $this->assertSame('webm', AudioStore::extensionFor('audio/webm;codecs=opus'));
        $this->assertSame('m4a', AudioStore::extensionFor('audio/mp4'));
    }

    public function test_no_se_puede_borrar_fuera_de_storage(): void
    {
        $store = new AudioStore(Config::basePath('storage'));

        $this->assertFalse($store->delete('../.env'));
        $this->assertFalse($store->delete('../../etc/passwd'));
        $this->assertFileExists(Config::basePath('.env'));
    }

    public function test_una_grabacion_encola_su_transcripcion(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $uid = json_decode($this->subir($token)->body, true)['uid'];

        $stmt = $this->pdo->prepare(
            'SELECT * FROM jobs WHERE user_id = ? AND type = ?'
        );
        $stmt->execute([$a->id, 'transcribe']);

        $trabajos = $stmt->fetchAll();

        $this->assertCount(1, $trabajos);
        $this->assertSame(JobQueue::PENDING, $trabajos[0]['state']);
        $this->assertSame(['entry' => $uid], JobQueue::payloadOf($trabajos[0]));
    }

    public function test_el_trabajo_de_transcripcion_pertenece_a_quien_grabo(): void
    {
        $a = $this->crearUsuario('a');
        $b = $this->crearUsuario('b');

        $this->subir($this->iniciarSesion($a));

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM jobs WHERE user_id = ?');
        $stmt->execute([$b->id]);

        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    // ------------------------------------------------- idempotencia (1.4)

    public function test_reintentar_la_misma_grabacion_no_crea_una_segunda(): void
    {
        // El caso que la cola sin conexión provoca de verdad: el servidor
        // guardó la entrada y la respuesta se perdió por el camino, así que el
        // móvil la vuelve a subir creyendo que falló.
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $primera = $this->subir($token, ['client_token' => 'cola-abc-123']);
        $segunda = $this->subir($token, ['client_token' => 'cola-abc-123']);

        $this->assertSame(202, $primera->status);
        $this->assertSame(202, $segunda->status, 'Un reintento no es un error');

        $uno = json_decode($primera->body, true);
        $dos = json_decode($segunda->body, true);

        $this->assertSame($uno['uid'], $dos['uid'], 'El reintento creó otra entrada');
        $this->assertFalse($uno['duplicate']);
        $this->assertTrue($dos['duplicate']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM entries WHERE user_id = ?');
        $stmt->execute([$a->id]);

        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_un_reintento_no_encola_una_segunda_transcripcion(): void
    {
        // Es lo que costaría dinero: dos llamadas de pago por el mismo audio.
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $this->subir($token, ['client_token' => 'cola-abc-123']);
        $this->subir($token, ['client_token' => 'cola-abc-123']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM jobs WHERE user_id = ? AND type = ?');
        $stmt->execute([$a->id, 'transcribe']);

        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_un_reintento_no_deja_el_audio_duplicado_en_disco(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $this->subir($token, ['client_token' => 'cola-abc-123']);
        $this->subir($token, ['client_token' => 'cola-abc-123']);

        $ficheros = glob(Config::basePath('storage/audio/' . $a->uid . '/*/*/*')) ?: [];

        $this->assertCount(1, $ficheros);
    }

    public function test_dos_grabaciones_distintas_llegan_las_dos(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $this->subir($token, ['client_token' => 'cola-abc-123']);
        $this->subir($token, ['client_token' => 'cola-def-456']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM entries WHERE user_id = ?');
        $stmt->execute([$a->id]);

        $this->assertSame(2, (int) $stmt->fetchColumn());
    }

    public function test_el_testigo_de_uno_no_tapa_la_grabacion_de_otro(): void
    {
        // El testigo viene del cliente. Si fuera único en toda la tabla, otra
        // persona podría hacer desaparecer una grabación ajena adivinándolo.
        $a = $this->crearUsuario('a');
        $b = $this->crearUsuario('b');

        $deA = $this->subir($this->iniciarSesion($a), ['client_token' => 'mismo-testigo-1']);
        $deB = $this->subir($this->iniciarSesion($b), ['client_token' => 'mismo-testigo-1']);

        $this->assertSame(202, $deB->status);
        $this->assertNotSame(
            json_decode($deA->body, true)['uid'],
            json_decode($deB->body, true)['uid'],
            'La grabación de B se perdió detrás del testigo de A',
        );
        $this->assertFalse(json_decode($deB->body, true)['duplicate']);
    }

    public function test_un_testigo_con_mala_pinta_no_tumba_la_grabacion(): void
    {
        // Se ignora y esa subida deja de ser idempotente. Perder lo que la
        // persona acaba de contar por un campo mal formado sería absurdo.
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $respuesta = $this->subir($token, ['client_token' => 'con espacios y símbolos ñ!']);

        $this->assertSame(202, $respuesta->status);

        $fila = (new EntryRepository($this->pdo, $a->id))
            ->findByUid(json_decode($respuesta->body, true)['uid']);

        $this->assertNull($fila['client_token']);
    }

    public function test_sin_testigo_la_subida_sigue_funcionando(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $this->assertSame(202, $this->subir($token)->status);
    }
}
