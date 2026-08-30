<?php

declare(strict_types=1);

namespace MaiMind\Tests\Domain;

use MaiMind\Domain\Capture\AudioStore;
use MaiMind\Domain\Jobs\Handlers\PurgeAudioHandler;
use MaiMind\Domain\Jobs\JobQueue;
use MaiMind\Domain\Jobs\Worker;
use MaiMind\Domain\User;
use MaiMind\Repository\EntryRepository;
use MaiMind\Support\Config;
use MaiMind\Tests\AppTestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Purga del audio al vencer la retención.
 *
 * La retención de 30 días es una promesa hecha al usuario (decisión D2). Estos
 * tests existen para que siga siendo una promesa y no una columna que nadie
 * lee: lo que se comprueba es que **el fichero desaparece del disco**, no que
 * una fila cambie de estado.
 */
final class PurgaAudioTest extends AppTestCase
{
    private function handler(): PurgeAudioHandler
    {
        return new PurgeAudioHandler($this->pdo, new NullLogger());
    }

    /**
     * Crea una entrada con un fichero de audio real en disco.
     *
     * @return array{uid:string,path:string,absolute:string}
     */
    private function entradaConAudio(User $user, string $purgeAfter): array
    {
        $uid  = \MaiMind\Support\Ulid::generate();
        $ruta = sprintf('audio/%s/2026/07/%s.webm', $user->uid, $uid);
        $abs  = Config::basePath('storage/' . $ruta);

        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0770, true);
        }

        file_put_contents($abs, 'audio de prueba');

        (new EntryRepository($this->pdo, $user->id))->createDraft(
            uid: $uid,
            capturedAt: '2026-07-01 10:00:00',
            localDate: '2026-07-01',
            timezone: 'Europe/Madrid',
            utcOffsetMinutes: 120,
            extra: [
                'audio_path'        => $ruta,
                'audio_bytes'       => 15,
                'audio_sha256'      => hash('sha256', 'audio de prueba'),
                'audio_mime'        => 'audio/webm',
                'audio_state'       => 'present',
                'audio_purge_after' => $purgeAfter,
            ],
        );

        return ['uid' => $uid, 'path' => $ruta, 'absolute' => $abs];
    }

    /** @return array<string,mixed> */
    private function fila(User $user, string $uid): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM entries WHERE user_id = ? AND uid = ?');
        $stmt->execute([$user->id, $uid]);

        return $stmt->fetch() ?: [];
    }

    private function ejecutar(User $user, string $hoy = '2026-08-30'): void
    {
        $this->handler()->handle(['today' => $hoy], ['user_id' => $user->id]);
    }

    public function test_el_fichero_desaparece_del_disco_al_vencer_el_plazo(): void
    {
        $a       = $this->crearUsuario('a');
        $entrada = $this->entradaConAudio($a, '2026-07-31');

        $this->assertFileExists($entrada['absolute']);

        $this->ejecutar($a);

        $this->assertFileDoesNotExist(
            $entrada['absolute'],
            'La promesa de retención es que el fichero se borra, no que la fila cambie',
        );

        $fila = $this->fila($a, $entrada['uid']);

        $this->assertSame('purged', $fila['audio_state']);
        $this->assertNull($fila['audio_path'], 'Una ruta que ya no existe es una mentira guardada');
        // Lo que describe la grabación se conserva; lo que dice dónde estaba, no.
        $this->assertSame(64, strlen((string) $fila['audio_sha256']));
        $this->assertSame(15, (int) $fila['audio_bytes']);
    }

    public function test_no_se_toca_lo_que_aun_esta_en_plazo(): void
    {
        $a       = $this->crearUsuario('a');
        $entrada = $this->entradaConAudio($a, '2026-09-29');

        $this->ejecutar($a);

        $this->assertFileExists($entrada['absolute']);
        $this->assertSame('present', $this->fila($a, $entrada['uid'])['audio_state']);

        unlink($entrada['absolute']);
    }

    public function test_el_ultimo_dia_del_plazo_todavia_no_se_purga(): void
    {
        $a       = $this->crearUsuario('a');
        $entrada = $this->entradaConAudio($a, '2026-08-30');

        // audio_purge_after es el día en que ya se puede purgar: <= hoy.
        $this->ejecutar($a, '2026-08-29');
        $this->assertFileExists($entrada['absolute']);

        $this->ejecutar($a, '2026-08-30');
        $this->assertFileDoesNotExist($entrada['absolute']);
    }

    public function test_la_purga_de_un_usuario_no_toca_el_audio_de_otro(): void
    {
        $a = $this->crearUsuario('a');
        $b = $this->crearUsuario('b');

        $deA = $this->entradaConAudio($a, '2026-07-31');
        $deB = $this->entradaConAudio($b, '2026-07-31');

        $this->ejecutar($a);

        $this->assertFileDoesNotExist($deA['absolute']);
        $this->assertFileExists($deB['absolute'], 'La purga de A se llevó por delante el audio de B');
        $this->assertSame('present', $this->fila($b, $deB['uid'])['audio_state']);

        unlink($deB['absolute']);
    }

    public function test_una_entrada_en_la_papelera_tambien_se_purga(): void
    {
        // Es la que más urge: el usuario ya dijo que no la quería.
        $a       = $this->crearUsuario('a');
        $entrada = $this->entradaConAudio($a, '2026-07-31');

        $this->pdo->prepare('UPDATE entries SET deleted_at = UTC_TIMESTAMP(3) WHERE uid = ?')
            ->execute([$entrada['uid']]);

        $this->ejecutar($a);

        $this->assertFileDoesNotExist($entrada['absolute']);
        $this->assertSame('purged', $this->fila($a, $entrada['uid'])['audio_state']);
    }

    public function test_si_el_fichero_ya_no_esta_la_fila_se_marca_igual(): void
    {
        $a       = $this->crearUsuario('a');
        $entrada = $this->entradaConAudio($a, '2026-07-31');

        unlink($entrada['absolute']);

        $this->ejecutar($a);

        $this->assertSame('purged', $this->fila($a, $entrada['uid'])['audio_state']);
    }

    public function test_ejecutarlo_dos_veces_no_cambia_nada(): void
    {
        $a       = $this->crearUsuario('a');
        $entrada = $this->entradaConAudio($a, '2026-07-31');

        $this->ejecutar($a);
        $this->ejecutar($a);

        $this->assertSame('purged', $this->fila($a, $entrada['uid'])['audio_state']);
    }

    public function test_sin_user_id_el_trabajo_se_niega_a_ejecutarse(): void
    {
        // Un purge_audio sin usuario solo podría implementarse leyendo entradas
        // de todo el mundo, que es justo lo que la regla de aislamiento prohíbe.
        $this->expectException(RuntimeException::class);

        $this->handler()->handle([], ['user_id' => null]);
    }

    public function test_de_extremo_a_extremo_por_la_cola(): void
    {
        $a       = $this->crearUsuario('a');
        $entrada = $this->entradaConAudio($a, '2026-07-31');

        $queue = new JobQueue($this->pdo);

        $queue->push(
            type: 'purge_audio',
            payload: ['today' => '2026-08-30'],
            userId: $a->id,
            dedupeKey: 'purge_audio:' . $a->id . ':2026-08-30',
        );

        $recuento = (new Worker($queue, new NullLogger(), 'worker-de-pruebas', ['purge_audio']))
            ->register($this->handler())
            ->drain();

        $this->assertSame(1, $recuento['done']);
        $this->assertFileDoesNotExist($entrada['absolute']);
    }

    public function test_un_audio_fuera_de_storage_no_se_borra(): void
    {
        // Si una ruta guardada apuntase fuera de storage/ —por un error o por
        // una escritura maliciosa— la purga no puede seguirla.
        $a     = $this->crearUsuario('a');
        $store = new AudioStore(Config::basePath('storage'));

        $fuera = Config::basePath('storage/../composer.json');

        $this->assertFalse($store->delete('../composer.json'));
        $this->assertFileExists($fuera);
    }
}
