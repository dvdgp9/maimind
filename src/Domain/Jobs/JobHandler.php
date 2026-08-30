<?php

declare(strict_types=1);

namespace MaiMind\Domain\Jobs;

/**
 * Un paso del pipeline.
 *
 * Cada manejador tiene que ser **idempotente**: el mismo trabajo puede
 * ejecutarse dos veces si el worker muere justo después de terminar y antes de
 * marcarlo hecho. Repetirlo no puede duplicar filas ni volver a cobrar una
 * llamada que ya se hizo.
 *
 * Un manejador falla lanzando. La cola decide si eso es un reintento o una
 * muerte; el manejador no tiene por qué saberlo.
 */
interface JobHandler
{
    /** El valor de `jobs.type` que atiende. */
    public function type(): string;

    /**
     * @param  array<string,mixed>  $payload  contenido de `jobs.payload`
     * @param  array<string,mixed>  $job      la fila entera, por si hace falta el user_id
     */
    public function handle(array $payload, array $job): void;
}
