<?php

declare(strict_types=1);

namespace MaiMind\Tests\Domain;

use MaiMind\Domain\Jobs\JobHandler;
use MaiMind\Domain\Jobs\JobQueue;
use MaiMind\Domain\Jobs\Worker;
use MaiMind\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * La cola de trabajos.
 *
 * El criterio de la tarea 1.3 es que dos workers en paralelo no procesen nunca
 * el mismo trabajo. Eso no se demuestra lanzando dos bucles y mirando: se
 * demuestra reteniendo el bloqueo de una fila desde una conexión y
 * comprobando que la otra ni la coge ni se queda esperándola.
 */
final class ColaTrabajosTest extends TestCase
{
    private PDO $pdo;

    private JobQueue $queue;

    /** Todos los trabajos de prueba llevan este prefijo en el tipo. */
    private const PREFIJO = 'test_';

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::connection();
        } catch (Throwable $e) {
            $this->markTestSkipped('Sin base de datos: ' . $e->getMessage());
        }

        $columna = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs'
                AND COLUMN_NAME = 'dedupe_key'"
        )->fetchColumn();

        if ($columna === 0) {
            $this->markTestSkipped('Esquema incompleto. Ejecuta: php bin/migrate');
        }

        $this->queue = new JobQueue($this->pdo);

        $this->limpiar();
    }

    protected function tearDown(): void
    {
        $this->limpiar();
    }

    private function limpiar(): void
    {
        $this->pdo->exec("DELETE FROM jobs WHERE type LIKE '" . self::PREFIJO . "%'");
    }

    private function tipo(string $sufijo = 'trabajo'): string
    {
        return self::PREFIJO . $sufijo;
    }

    /**
     * Reclama acotando a los tipos de prueba.
     *
     * Nunca `claim()` a secas en un test: la tabla es compartida y un reclamo
     * sin filtro se lleva lo primero que haya en la cola de desarrollo. Costó
     * un fallo que no era del código.
     */
    public function test_se_puede_adelantar_un_trabajo_que_espera_turno(): void
    {
        // Los aparcados por no tener manejador se reprograman a una hora vista.
        // Al desplegar la fase que los implementa hacía falta una forma de
        // decirles «ya puedes»; sin ella, comprobar que el despliegue funcionó
        // exigía esperar una hora. Salió al usarlo de verdad en producción.
        $id = $this->queue->push($this->tipo(), delaySeconds: 3600);

        $this->assertNull($this->reclamar(), 'Todavía no le tocaba');

        $this->assertSame(1, $this->queue->runNow($this->tipo()));
        $this->assertNotNull($this->reclamar(), 'Sigue sin poder reclamarse');
    }

    public function test_adelantar_no_revive_muertos_ni_roba_lo_que_se_esta_ejecutando(): void
    {
        $muerto = $this->queue->push($this->tipo(), maxAttempts: 1);
        $this->reclamar();
        $this->queue->fail($muerto, 'roto');

        $corriendo = $this->queue->push($this->tipo('otro'));
        $this->queue->claim('otro-worker', [$this->tipo('otro')]);

        $this->assertSame(0, $this->queue->runNow());

        $this->assertSame(JobQueue::DEAD, $this->queue->find($muerto)['state']);
        $this->assertSame(JobQueue::RUNNING, $this->queue->find($corriendo)['state']);
    }

    public function test_adelantar_puede_acotarse_por_tipo(): void
    {
        $este = $this->queue->push($this->tipo(), delaySeconds: 3600);
        $otro = $this->queue->push($this->tipo('otro'), delaySeconds: 3600);

        $this->assertSame(1, $this->queue->runNow($this->tipo()));

        $this->assertSame($este, (int) $this->reclamar()['id']);
        $this->assertNull($this->queue->claim('w', [$this->tipo('otro')]), 'No le tocaba al otro');
    }

    private function reclamar(string $worker = 'w'): ?array
    {
        return $this->queue->claim($worker, [
            $this->tipo(), $this->tipo('otro'), $this->tipo('futuro'), $this->tipo('este'),
        ]);
    }

    // ------------------------------------------------------------ lo básico

    public function test_encolar_y_reclamar_devuelve_el_trabajo(): void
    {
        $id = $this->queue->push($this->tipo(), ['n' => 7], userId: 3);

        $this->assertIsInt($id);

        $job = $this->reclamar('worker-1');

        $this->assertNotNull($job);
        $this->assertSame($id, (int) $job['id']);
        $this->assertSame(JobQueue::RUNNING, $job['state']);
        $this->assertSame('worker-1', $job['locked_by']);
        $this->assertSame(3, (int) $job['user_id']);
        $this->assertSame(['n' => 7], JobQueue::payloadOf($job));
    }

    public function test_una_cola_vacia_no_devuelve_nada(): void
    {
        $this->assertNull($this->queue->claim('worker-1', [$this->tipo()]));
    }

    public function test_el_intento_se_suma_al_reclamar_no_al_fallar(): void
    {
        // Si se sumara al fallar, un trabajo que tumbe al worker no llegaría a
        // registrar nada y volvería a tumbarlo para siempre.
        $id = $this->queue->push($this->tipo());

        $job = $this->reclamar('worker-1');

        $this->assertSame(1, (int) $job['attempts']);
    }

    public function test_un_trabajo_aplazado_no_se_reclama_antes_de_tiempo(): void
    {
        $this->queue->push($this->tipo(), delaySeconds: 120);

        $this->assertNull($this->queue->claim('worker-1', [$this->tipo()]));
    }

    public function test_se_reclama_por_prioridad_y_luego_por_antiguedad(): void
    {
        $normal    = $this->queue->push($this->tipo(), priority: 5);
        $urgente   = $this->queue->push($this->tipo(), priority: 1);
        $postergado = $this->queue->push($this->tipo(), priority: 9);

        $this->assertSame($urgente, (int) $this->reclamar('w')['id']);
        $this->assertSame($normal, (int) $this->reclamar('w')['id']);
        $this->assertSame($postergado, (int) $this->reclamar('w')['id']);
    }

    public function test_el_filtro_de_tipos_se_respeta(): void
    {
        $this->queue->push($this->tipo('otro'));

        $this->assertNull($this->queue->claim('w', [$this->tipo('este')]));
        $this->assertNotNull($this->queue->claim('w', [$this->tipo('otro')]));
    }

    // ------------------------------------------------------- deduplicación

    public function test_la_clave_de_deduplicacion_impide_encolar_dos_veces(): void
    {
        $primero  = $this->queue->push($this->tipo(), dedupeKey: 'entrada-42');
        $segundo  = $this->queue->push($this->tipo(), dedupeKey: 'entrada-42');

        $this->assertIsInt($primero);
        $this->assertNull($segundo, 'El segundo encolado debería haberse descartado');
    }

    public function test_sin_clave_de_deduplicacion_se_puede_encolar_lo_que_sea(): void
    {
        // NULL no colisiona en una UNIQUE KEY de MariaDB, y aquí eso es lo que
        // se quiere: un trabajo sin clave nunca deduplica.
        $this->assertIsInt($this->queue->push($this->tipo()));
        $this->assertIsInt($this->queue->push($this->tipo()));
    }

    public function test_al_terminar_se_libera_la_clave_para_manana(): void
    {
        $id = $this->queue->push($this->tipo(), dedupeKey: 'purga-diaria');

        $this->reclamar('w');
        $this->queue->complete($id);

        $this->assertIsInt(
            $this->queue->push($this->tipo(), dedupeKey: 'purga-diaria'),
            'Un trabajo terminado no puede bloquear el encolado del siguiente',
        );
    }

    // ---------------------------------------------- concurrencia (1.3)

    public function test_dos_workers_no_se_llevan_el_mismo_trabajo(): void
    {
        $ids = [];

        for ($i = 0; $i < 6; $i++) {
            $ids[] = $this->queue->push($this->tipo());
        }

        // Dos conexiones distintas de verdad, no dos objetos sobre la misma.
        $colaA = new JobQueue(Database::connect());
        $colaB = new JobQueue(Database::connect());

        $reclamados = [];

        for ($i = 0; $i < 6; $i++) {
            $job = ($i % 2 === 0 ? $colaA : $colaB)
                ->claim('worker-' . ($i % 2), [$this->tipo()]);

            $this->assertNotNull($job);

            $reclamados[] = (int) $job['id'];
        }

        $this->assertSame($ids, $reclamados);
        $this->assertCount(6, array_unique($reclamados), 'Un trabajo se reclamó dos veces');
        $this->assertNull(
            $colaA->claim('worker-0', [$this->tipo()]),
            'La cola debería haber quedado vacía',
        );
    }

    public function test_un_trabajo_bloqueado_se_salta_en_vez_de_esperarlo(): void
    {
        $primero  = $this->queue->push($this->tipo(), priority: 1);
        $segundo  = $this->queue->push($this->tipo(), priority: 5);

        $lento = Database::connect();
        $lento->beginTransaction();

        // Simula al worker A dentro de su transacción de reclamo: tiene la fila
        // bloqueada y todavía no ha hecho COMMIT.
        $stmt = $lento->prepare(
            'SELECT id FROM jobs WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$primero]);

        $inicio = microtime(true);
        $job    = (new JobQueue(Database::connect()))->claim('worker-b', [$this->tipo()]);
        $tardo  = microtime(true) - $inicio;

        $lento->rollBack();

        $this->assertNotNull($job, 'El segundo worker se quedó sin trabajo pudiendo coger otro');
        $this->assertSame(
            $segundo,
            (int) $job['id'],
            'El segundo worker se llevó la fila que el primero tenía bloqueada',
        );

        // Sin SKIP LOCKED esto esperaría al innodb_lock_wait_timeout entero.
        $this->assertLessThan(2.0, $tardo, 'El reclamo se quedó esperando al bloqueo ajeno');
    }

    // ------------------------------------------------- fallos y reintentos

    public function test_un_fallo_devuelve_el_trabajo_a_la_cola_con_espera(): void
    {
        $id = $this->queue->push($this->tipo(), maxAttempts: 3);

        $this->reclamar('w');

        $this->assertSame(JobQueue::PENDING, $this->queue->fail($id, 'la API no respondió'));

        $job = $this->queue->find($id);

        $this->assertSame(1, (int) $job['attempts']);
        $this->assertStringContainsString('la API no respondió', (string) $job['last_error']);
        $this->assertNull($job['locked_by']);

        // Y no se puede volver a reclamar hasta que pase la espera.
        $this->assertNull($this->queue->claim('w', [$this->tipo()]));
    }

    public function test_al_agotar_los_intentos_el_trabajo_muere(): void
    {
        $id = $this->queue->push($this->tipo(), maxAttempts: 2);

        $this->reclamar('w');
        $this->assertSame(JobQueue::PENDING, $this->queue->fail($id, 'primero'));

        // El segundo intento agota el máximo.
        $this->pdo->prepare('UPDATE jobs SET run_after = UTC_TIMESTAMP(3) WHERE id = ?')->execute([$id]);
        $this->reclamar('w');

        $this->assertSame(JobQueue::DEAD, $this->queue->fail($id, 'segundo'));
        $this->assertSame(JobQueue::DEAD, $this->queue->find($id)['state']);
    }

    public function test_la_espera_entre_reintentos_crece_y_tiene_tope(): void
    {
        $cola = new JobQueue($this->pdo, backoffBaseSeconds: 10, backoffMaxSeconds: 900);

        $this->assertGreaterThanOrEqual(10, $cola->backoffFor(1));
        $this->assertGreaterThan($cola->backoffFor(1) - 1, $cola->backoffFor(4));
        $this->assertLessThanOrEqual(900 * 1.2 + 1, $cola->backoffFor(20));
    }

    public function test_un_trabajo_muerto_se_puede_devolver_a_la_cola_a_mano(): void
    {
        $id = $this->queue->push($this->tipo(), maxAttempts: 1);

        $this->reclamar('w');
        $this->queue->fail($id, 'roto');

        $this->assertTrue($this->queue->retry($id));

        $job = $this->queue->find($id);

        $this->assertSame(JobQueue::PENDING, $job['state']);
        $this->assertSame(0, (int) $job['attempts']);
    }

    public function test_aplazar_no_gasta_intentos(): void
    {
        $id = $this->queue->push($this->tipo());

        $this->reclamar('w');
        $this->assertSame(1, (int) $this->queue->find($id)['attempts']);

        $this->queue->defer($id, 60, 'sin manejador todavía');

        $this->assertSame(0, (int) $this->queue->find($id)['attempts']);
        $this->assertSame(JobQueue::PENDING, $this->queue->find($id)['state']);
    }

    public function test_se_recuperan_los_trabajos_de_un_worker_que_murio(): void
    {
        $id = $this->queue->push($this->tipo());

        $this->reclamar('worker-difunto');

        // Su bloqueo es de hace media hora.
        $this->pdo->prepare(
            'UPDATE jobs SET locked_at = UTC_TIMESTAMP(3) - INTERVAL 1800 SECOND WHERE id = ?'
        )->execute([$id]);

        $this->assertSame(1, $this->queue->reclaimStale(900));

        $job = $this->queue->find($id);

        $this->assertSame(JobQueue::PENDING, $job['state']);
        $this->assertNull($job['locked_by']);
        // El intento NO se le perdona: pudo ser él quien tumbó al worker.
        $this->assertSame(1, (int) $job['attempts']);
    }

    public function test_un_trabajo_recien_reclamado_no_se_da_por_perdido(): void
    {
        $this->queue->push($this->tipo());
        $this->reclamar('worker-vivo');

        $this->assertSame(0, $this->queue->reclaimStale(900));
    }

    public function test_los_trabajos_hechos_se_olvidan_pero_no_los_muertos(): void
    {
        $hecho  = $this->queue->push($this->tipo());
        $muerto = $this->queue->push($this->tipo(), maxAttempts: 1);

        $this->reclamar('w');
        $this->queue->complete($hecho);
        $this->reclamar('w');
        $this->queue->fail($muerto, 'roto');

        $this->pdo->exec(
            'UPDATE jobs SET finished_at = UTC_TIMESTAMP(3) - INTERVAL 30 DAY
              WHERE id IN (' . $hecho . ',' . $muerto . ')'
        );

        $this->assertSame(1, $this->queue->forget(7));
        $this->assertNull($this->queue->find($hecho));
        $this->assertNotNull($this->queue->find($muerto), 'Un trabajo muerto es evidencia, no basura');
    }

    // ---------------------------------------------------------- el worker

    public function test_el_worker_ejecuta_el_manejador_y_marca_hecho(): void
    {
        $ejecutado = 0;

        $worker = $this->workerCon($this->manejador($this->tipo(), function () use (&$ejecutado): void {
            $ejecutado++;
        }));

        $id = $this->queue->push($this->tipo());

        $resultado = $worker->step();

        $this->assertSame('done', $resultado['outcome']);
        $this->assertSame(1, $ejecutado);
        $this->assertSame(JobQueue::DONE, $this->queue->find($id)['state']);
    }

    public function test_un_manejador_que_revienta_no_tumba_al_worker(): void
    {
        $worker = $this->workerCon($this->manejador($this->tipo(), function (): void {
            throw new RuntimeException('la API devolvió 503');
        }));

        $id = $this->queue->push($this->tipo(), maxAttempts: 3);

        $resultado = $worker->step();

        $this->assertSame(JobQueue::PENDING, $resultado['outcome']);
        $this->assertStringContainsString('503', (string) $this->queue->find($id)['last_error']);
    }

    public function test_un_tipo_sin_manejador_se_aplaza_no_se_mata(): void
    {
        // Es el caso de 'transcribe' hasta que se despliegue la fase 2: el
        // trabajo es correcto, lo que falta es el código que lo atiende.
        $worker = $this->workerCon($this->manejador($this->tipo('otro'), static function (): void {
        }));

        $id = $this->queue->push($this->tipo('futuro'));

        $resultado = $worker->step();

        $this->assertSame('deferred', $resultado['outcome']);

        $job = $this->queue->find($id);

        $this->assertSame(JobQueue::PENDING, $job['state']);
        $this->assertSame(0, (int) $job['attempts'], 'Aplazar no puede gastar intentos');
    }

    public function test_drain_vacia_la_cola_y_para(): void
    {
        $hechos = 0;

        $worker = $this->workerCon($this->manejador($this->tipo(), function () use (&$hechos): void {
            $hechos++;
        }));

        for ($i = 0; $i < 3; $i++) {
            $this->queue->push($this->tipo());
        }

        $recuento = $worker->drain();

        $this->assertSame(3, $recuento['done']);
        $this->assertSame(3, $hechos);
    }

    public function test_stop_saca_al_worker_del_bucle(): void
    {
        // Este test existe por un fallo real: el manejador de la señal escribía
        // en una variable que el bucle había capturado por valor, así que el
        // worker recibía SIGTERM y seguía corriendo tan tranquilo.
        $worker = null;

        $worker = $this->workerCon($this->manejador($this->tipo(), function () use (&$worker): void {
            $worker->stop();
        }));

        $this->queue->push($this->tipo());
        $this->queue->push($this->tipo());

        $recuento = $worker->run(maxJobs: 50, sleepSeconds: 1);

        $this->assertTrue($worker->isStopping());
        $this->assertSame(1, $recuento['done'], 'Siguió cogiendo trabajos después de que le pidieran parar');
        $this->assertSame(1, $this->pendientesDePrueba(), 'El segundo trabajo debería seguir en la cola');
    }

    /** countsByState() cuenta toda la tabla; aquí solo interesan los de prueba. */
    private function pendientesDePrueba(): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM jobs WHERE state = ? AND type LIKE ?"
        );
        $stmt->execute([JobQueue::PENDING, self::PREFIJO . '%']);

        return (int) $stmt->fetchColumn();
    }

    private function workerCon(JobHandler $handler): Worker
    {
        // Acotado a los tipos de prueba: la tabla es compartida.
        return (new Worker($this->queue, new NullLogger(), 'worker-de-pruebas', [
            $this->tipo(), $this->tipo('otro'), $this->tipo('futuro'),
        ]))->register($handler);
    }

    private function manejador(string $tipo, callable $accion): JobHandler
    {
        return new class ($tipo, $accion) implements JobHandler {
            public function __construct(
                private readonly string $tipo,
                private $accion,
            ) {
            }

            public function type(): string
            {
                return $this->tipo;
            }

            public function handle(array $payload, array $job): void
            {
                ($this->accion)($payload, $job);
            }
        };
    }
}
