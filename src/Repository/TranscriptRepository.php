<?php

declare(strict_types=1);

namespace MaiMind\Repository;

use MaiMind\Pipeline\Transcription\TranscriptionResult;
use MaiMind\Pipeline\Transcription\TranscriptionSegment;

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
    /** Lo que se guarda en `provider` cuando el texto lo escribió una persona. */
    public const PROVIDER_MANUAL = 'user';

    public static function isManual(?array $transcripcion): bool
    {
        return ($transcripcion['provider'] ?? null) === self::PROVIDER_MANUAL;
    }

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
     * Guarda una corrección hecha a mano.
     *
     * **No sobrescribe la del transcriptor: añade una encima.** Lo que oyó la
     * máquina y lo que la persona dice que dijo son dos datos distintos, y la
     * diferencia entre ambos es información —dice dónde falla el ASR con esta
     * voz concreta—. Machacar el original la destruiría para siempre.
     *
     * Los tramos se conservan porque siguen describiendo el audio, pero al
     * cambiar el texto se vuelven a anclar solos: los que ya no aparezcan se
     * quedan sin offsets, que es lo honesto.
     */
    public function storeManualEdit(
        int $entryId,
        string $texto,
        ?int $audioDurationMs = null,
    ): int {
        $anterior = $this->currentFor($entryId);

        $tramos = [];

        foreach (json_decode((string) ($anterior['segments'] ?? '[]'), true) ?: [] as $tramo) {
            $tramos[] = TranscriptionSegment::fromArray($tramo);
        }

        return $this->storeAsCurrent(
            $entryId,
            new TranscriptionResult(
                text: $texto,
                // 'user' y no el proveedor anterior: quien mire esta fila tiene
                // que poder saber que estas palabras las escribió una persona.
                provider: self::PROVIDER_MANUAL,
                model: 'manual',
                segments: $tramos,
                language: $anterior['language'] ?? null,
                // Ni coste ni latencia: no hubo inferencia que pagar.
            ),
            $audioDurationMs,
        );
    }

    /**
     * Qué motor produjo la transcripción original de una entrada.
     *
     * Hace falta porque una corrección a mano tapaba el modelo: la pantalla
     * decía «corregido por ti» y ya no había forma de saber quién había
     * transcrito eso. El proveedor se guarda en cada fila justamente para
     * poder saber siempre qué motor produjo qué dato
     * (04-arquitectura.md §1), así que esconderlo era tirar esa garantía.
     */
    public function originalModelFor(int $entryId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT model FROM transcripts
              WHERE user_id = ? AND entry_id = ? AND provider <> ?
              ORDER BY id ASC LIMIT 1'
        );

        $stmt->execute([$this->userId, $entryId, self::PROVIDER_MANUAL]);

        $modelo = $stmt->fetchColumn();

        return $modelo === false ? null : (string) $modelo;
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
