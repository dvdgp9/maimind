<?php

declare(strict_types=1);

namespace MaiMind\Domain\Jobs;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bucle del worker: reclama un trabajo, lo despacha a su manejador y anota el
 * resultado. Nada más.
 *
 * Concurrencia 1 por decisión de arquitectura (docs/design/04-arquitectura.md
 * §0): el trabajo es esperar a APIs, no calcular, y la máquina comparte dos
 * núcleos con el correo. Aun así el reclamo es seguro con varios workers a la
 * vez, porque de eso depende poder arrancar un segundo sin pensarlo si algún
 * día hace falta.
 */
final class Worker
{
    /** @var array<string,JobHandler> */
    private array $handlers = [];

    private bool $stopping = false;

    /**
     * Cuánto espera un trabajo cuyo tipo todavía no tiene manejador desplegado.
     * Una hora: ni quema CPU comprobándolo ni se pierde el trabajo.
     */
    private const ESPERA_TIPO_DESCONOCIDO = 3600;

    public function __construct(
        private readonly JobQueue $queue,
        private readonly LoggerInterface $logger,
        private readonly string $id,
    ) {
    }

    /**
     * Pide al bucle que termine en cuanto pueda.
     *
     * El estado vive aquí dentro y no en una variable del script que llama,
     * porque ahí ya se coló una vez: el manejador de la señal escribía en una
     * variable que el bucle había capturado **por valor**, así que la señal
     * llegaba y el worker seguía corriendo. Dentro del objeto no hay captura
     * que equivocar.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    public function isStopping(): bool
    {
        return $this->stopping;
    }

    public function register(JobHandler $handler): self
    {
        $this->handlers[$handler->type()] = $handler;

        return $this;
    }

    /** @return list<string> */
    public function registeredTypes(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Procesa un trabajo si lo hay.
     *
     * @return array{type:string,id:int,outcome:string}|null  null = cola vacía
     */
    public function step(): ?array
    {
        $job = $this->queue->claim($this->id);

        if ($job === null) {
            return null;
        }

        $id   = (int) $job['id'];
        $type = (string) $job['type'];

        $handler = $this->handlers[$type] ?? null;

        if ($handler === null) {
            // No es un trabajo roto: es un trabajo de una fase que aún no está
            // desplegada. Se aparta sin gastarle intentos y se ejecutará solo
            // cuando su manejador exista. `bin/jobs status` los saca a la vista
            // para que un tipo mal escrito no se quede esperando para siempre.
            $this->queue->defer(
                $id,
                self::ESPERA_TIPO_DESCONOCIDO,
                "Sin manejador para el tipo '{$type}' en esta versión.",
            );

            $this->logger->warning('Trabajo aplazado: tipo sin manejador', [
                'job' => $id, 'type' => $type,
            ]);

            return ['type' => $type, 'id' => $id, 'outcome' => 'deferred'];
        }

        $inicio = microtime(true);

        try {
            $handler->handle(JobQueue::payloadOf($job), $job);

            $this->queue->complete($id);

            $this->logger->info('Trabajo completado', [
                'job'  => $id,
                'type' => $type,
                'ms'   => (int) ((microtime(true) - $inicio) * 1000),
            ]);

            return ['type' => $type, 'id' => $id, 'outcome' => 'done'];
        } catch (Throwable $e) {
            $estado = $this->queue->fail($id, $e->getMessage());

            // error y no warning cuando muere: ahí ya no vuelve solo.
            $this->logger->log(
                $estado === JobQueue::DEAD ? 'error' : 'warning',
                $estado === JobQueue::DEAD ? 'Trabajo muerto' : 'Trabajo fallido, se reintentará',
                [
                    'job'      => $id,
                    'type'     => $type,
                    'attempts' => (int) $job['attempts'],
                    'error'    => $e->getMessage(),
                    'file'     => $e->getFile() . ':' . $e->getLine(),
                ],
            );

            return ['type' => $type, 'id' => $id, 'outcome' => $estado];
        }
    }

    /**
     * Bucle principal. Sale al llegar a $maxJobs o cuando alguien llama a
     * stop() —normalmente el manejador de SIGTERM.
     *
     * @return array<string,int>  recuento por resultado
     */
    public function run(
        int $maxJobs,
        int $sleepSeconds,
        int $staleAfterSeconds = 900,
    ): array {
        $recuento  = ['done' => 0, 'pending' => 0, 'dead' => 0, 'deferred' => 0, 'idle' => 0];
        $procesados = 0;

        $recuperados = $this->queue->reclaimStale($staleAfterSeconds);

        if ($recuperados > 0) {
            $this->logger->warning('Trabajos recuperados de un worker anterior', [
                'count' => $recuperados,
            ]);
        }

        while ($procesados < $maxJobs) {
            if ($this->stopping) {
                break;
            }

            $resultado = $this->step();

            if ($resultado === null) {
                $recuento['idle']++;

                // Dormir a trocitos de un segundo: si no, una parada ordenada
                // tardaría hasta `sleepSeconds` en atenderse, y systemd acaba
                // mandando SIGKILL.
                for ($i = 0; $i < $sleepSeconds; $i++) {
                    if ($this->stopping) {
                        return $recuento;
                    }

                    sleep(1);
                }

                continue;
            }

            $recuento[$resultado['outcome']] = ($recuento[$resultado['outcome']] ?? 0) + 1;
            $procesados++;
        }

        return $recuento;
    }

    /** Vacía la cola de trabajos ejecutables y para. Para tests y para cron. */
    public function drain(int $maxJobs = 100): array
    {
        $recuento = ['done' => 0, 'pending' => 0, 'dead' => 0, 'deferred' => 0];

        for ($i = 0; $i < $maxJobs; $i++) {
            $resultado = $this->step();

            if ($resultado === null) {
                break;
            }

            $recuento[$resultado['outcome']] = ($recuento[$resultado['outcome']] ?? 0) + 1;
        }

        return $recuento;
    }
}
