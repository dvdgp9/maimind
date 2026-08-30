<?php

declare(strict_types=1);

namespace MaiMind\Repository;

use MaiMind\Pipeline\Transcription\TranscriptionResult;

/**
 * Transcripciones.
 *
 * Una entrada puede tener varias: se retranscribe al cambiar de proveedor o
 * cuando el usuario corrige el texto a mano. `is_current` marca cuál manda, y
 * las anteriores **no se borran** — son la única forma de comparar la calidad
 * de dos proveedores con datos reales, que es media razón de tener interfaces.
 */
final class TranscriptRepository extends UserScopedRepository
{
    protected function table(): string
    {
        return 'transcripts';
    }

    /** La tabla no tiene borrado lógico: cuelga de entries, que sí. */
    protected function usesSoftDeletes(): bool
    {
        return false;
    }

    /** @return array<string,mixed>|null */
    public function currentFor(int $entryId): ?array
    {
        return $this->findOneWhere(['entry_id' => $entryId, 'is_current' => 1]);
    }

    public function hasCurrentFor(int $entryId): bool
    {
        return $this->currentFor($entryId) !== null;
    }

    /**
     * Guarda una transcripción y la deja como la vigente.
     *
     * Las dos escrituras van en una transacción: entre bajar la bandera de la
     * anterior y subir la de la nueva no puede haber un instante con ninguna
     * vigente, porque cualquier lectura de en medio vería la entrada como si no
     * estuviera transcrita.
     */
    public function storeAsCurrent(
        int $entryId,
        TranscriptionResult $resultado,
        ?int $audioDurationMs = null,
    ): int {
        $propia = ! $this->pdo->inTransaction();

        if ($propia) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->update(['is_current' => 0], ['entry_id' => $entryId, 'is_current' => 1]);

            $id = $this->insert([
                'entry_id'   => $entryId,
                'is_current' => 1,
                ...$resultado->toRow($audioDurationMs),
            ]);

            if ($propia) {
                $this->pdo->commit();
            }

            return $id;
        } catch (\Throwable $e) {
            if ($propia) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function historyFor(int $entryId): array
    {
        return $this->findWhere(
            ['entry_id' => $entryId],
            columns: 'id, provider, model, language, word_count, avg_confidence, cost_micros, is_current, created_at',
            orderBy: 'created_at DESC',
        );
    }

    /**
     * Transcripciones a las que les falta audio.
     *
     * `gap_total_ms > 0` significa que hay trozos de la grabación que no
     * aparecen en el texto. NULL significa que no se pudo comprobar —el
     * proveedor no dio tramos— y por eso no entra aquí: no es lo mismo no
     * tener pérdida que no haber podido mirar.
     *
     * @return list<array<string,mixed>>
     */
    public function withCoverageGaps(int $limit = 50): array
    {
        // SQL a mano porque findWhere() solo sabe de igualdades, y aquí hace
        // falta un `> 0`. Sigue dentro del repositorio y sigue llevando el
        // user_id, que es lo que la regla de aislamiento exige.
        $stmt = $this->pdo->prepare(
            'SELECT id, entry_id, model, gap_total_ms, coverage_gaps, created_at
               FROM transcripts
              WHERE user_id = ? AND is_current = 1 AND gap_total_ms > 0
              ORDER BY gap_total_ms DESC
              LIMIT ' . max(1, $limit)
        );

        $stmt->execute([$this->userId]);

        return $stmt->fetchAll();
    }

    /** Lo gastado en transcripción por este usuario, en micros. */
    public function totalCostMicros(): int
    {
        return (int) ($this->findOneWhere([], 'COALESCE(SUM(cost_micros), 0) AS n')['n'] ?? 0);
    }
}
