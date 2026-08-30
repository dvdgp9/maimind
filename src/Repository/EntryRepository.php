<?php

declare(strict_types=1);

namespace MaiMind\Repository;

use MaiMind\Support\Ulid;

/**
 * Registros de captura.
 */
final class EntryRepository extends UserScopedRepository
{
    protected function table(): string
    {
        return 'entries';
    }

    /** @return array<string,mixed>|null */
    public function findByUid(string $uid): ?array
    {
        // El filtro por user_id lo pone la clase base: buscar por uid a secas
        // no es posible desde aquí.
        return $this->findOneWhere(['uid' => $uid]);
    }

    /**
     * Busca una entrada por el testigo que trae el cliente.
     *
     * Es lo que hace idempotente la subida: si la cola del móvil reintenta una
     * grabación que ya llegó, aquí aparece y no se crea una segunda fila.
     *
     * @return array<string,mixed>|null
     */
    public function findByClientToken(string $token): ?array
    {
        return $this->findOneWhere(['client_token' => $token], 'uid, local_date, pipeline_state');
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 20): array
    {
        return $this->findWhere(
            columns: 'uid, source, captured_at, local_date, pipeline_state, mood_hint',
            orderBy: 'captured_at DESC',
            limit: $limit,
        );
    }

    /**
     * El listado, con lo poco que hace falta para decidir cuál abrir.
     *
     * Trae el número de palabras de la transcripción vigente para que la lista
     * distinga de un vistazo una grabación de veinte segundos de una de cinco
     * minutos. Es un JOIN y no una consulta por fila: con doscientas entradas,
     * doscientas consultas se notan.
     *
     * @return list<array<string,mixed>>
     */
    public function timeline(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.uid, e.captured_at, e.local_date, e.client_timezone,
                    e.mood_hint, e.pipeline_state, e.audio_state, e.audio_duration_ms,
                    t.word_count, t.provider AS transcript_provider
               FROM entries e
               LEFT JOIN transcripts t
                      ON t.entry_id = e.id AND t.is_current = 1 AND t.user_id = e.user_id
              WHERE e.user_id = ? AND e.deleted_at IS NULL
              ORDER BY e.captured_at DESC
              LIMIT ' . max(1, $limit)
        );

        $stmt->execute([$this->userId]);

        return $stmt->fetchAll();
    }

    public function countAll(): int
    {
        return $this->countWhere();
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    public function createDraft(
        string $capturedAt,
        string $localDate,
        string $timezone,
        int $utcOffsetMinutes,
        ?int $moodHint = null,
        array $extra = [],
        ?string $uid = null,
    ): string {
        // El uid puede venir dado: el fichero de audio se nombra con él antes de
        // que exista la fila, y ambos tienen que coincidir.
        $uid ??= Ulid::generate();

        $this->insert([
            'uid'               => $uid,
            'source'            => 'audio',
            'captured_at'       => $capturedAt,
            'received_at'       => gmdate('Y-m-d H:i:s'),
            'local_date'        => $localDate,
            'client_timezone'   => $timezone,
            'client_utc_offset' => $utcOffsetMinutes,
            'mood_hint'         => $moodHint,
            ...$extra,
        ]);

        return $uid;
    }

    /**
     * Registra una captura de audio ya almacenada en disco.
     *
     * @param  array<string,mixed>  $audio  path, bytes, sha256, mime, duration_ms
     * @param  array<string,mixed>  $clock  salida de CaptureClock::resolve()
     */
    public function createFromAudio(
        array $clock,
        array $audio,
        ?int $moodHint,
        int $retentionDays,
        ?string $uid = null,
        ?string $clientToken = null,
    ): string {
        return $this->createDraft(
            uid: $uid,
            capturedAt: (string) $clock['captured_at'],
            localDate: (string) $clock['local_date'],
            timezone: (string) $clock['timezone'],
            utcOffsetMinutes: (int) $clock['utc_offset'],
            moodHint: $moodHint,
            extra: [
                'client_token'      => $clientToken,
                'received_at'       => (string) $clock['received_at'],
                'audio_path'        => (string) $audio['path'],
                'audio_bytes'       => (int) $audio['bytes'],
                'audio_sha256'      => (string) $audio['sha256'],
                'audio_mime'        => (string) $audio['mime'],
                'audio_duration_ms' => $audio['duration_ms'] ?? null,
                'audio_state'       => 'present',
                // La purga la ejecuta un job; aquí solo se marca desde cuándo.
                'audio_purge_after' => $retentionDays > 0
                    ? gmdate('Y-m-d', time() + $retentionDays * 86400)
                    : gmdate('Y-m-d'),
                'pipeline_state'    => 'captured',
            ],
        );
    }

    /**
     * Grabaciones cuyo plazo de retención ha vencido.
     *
     * A propósito **sin** el filtro de borrado lógico que pone la clase base:
     * una entrada en la papelera es justamente la que más urge purgar. Guardar
     * su audio treinta días más porque el usuario la borró sería lo contrario
     * de lo que pidió.
     *
     * @return list<array{uid:string,audio_path:string}>
     */
    public function audioDuePurge(string $today, int $limit = 500): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT uid, audio_path
               FROM entries
              WHERE user_id = ?
                AND audio_state = ?
                AND audio_path IS NOT NULL
                AND audio_purge_after IS NOT NULL
                AND audio_purge_after <= ?
              ORDER BY audio_purge_after ASC
              LIMIT ' . max(1, $limit)
        );

        $stmt->execute([$this->userId, 'present', $today]);

        return $stmt->fetchAll();
    }

    /**
     * Marca el audio como purgado.
     *
     * `audio_path` se pone a NULL porque el fichero ya no está: dejar la ruta
     * escrita sería guardar una mentira comprobable. El sha256, el tamaño y la
     * duración se conservan — describen lo que hubo, no dónde estaba, y sin
     * ellos no habría forma de saber si dos grabaciones eran la misma.
     */
    public function markAudioPurged(string $uid): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE entries
                SET audio_state = ?, audio_path = NULL
              WHERE user_id = ? AND uid = ? AND audio_state = ?'
        );

        $stmt->execute(['purged', $this->userId, $uid, 'present']);

        return $stmt->rowCount() === 1;
    }

    /**
     * Una entrada con lo que hace falta para transcribirla.
     *
     * @return array<string,mixed>|null
     */
    public function forTranscription(string $uid): ?array
    {
        return $this->findOneWhere(
            ['uid' => $uid],
            'id, uid, audio_path, audio_mime, audio_bytes, audio_sha256, '
            . 'audio_duration_ms, audio_state, pipeline_state',
        );
    }

    /**
     * Una entrada con todo lo que la pantalla de detalle necesita.
     *
     * @return array<string,mixed>|null
     */
    public function detail(string $uid): ?array
    {
        return $this->findOneWhere(
            ['uid' => $uid],
            'id, uid, captured_at, local_date, client_timezone, mood_hint, '
            . 'audio_state, audio_duration_ms, pipeline_state, error_message, reviewed_at',
        );
    }

    /**
     * Mueve el estado del pipeline.
     *
     * `error_message` se limpia al avanzar: si no, una entrada que falló y
     * luego se procesó bien seguiría enseñando el error de la vez anterior.
     */
    public function moveToState(string $uid, string $estado, ?string $error = null): bool
    {
        return $this->update(
            ['pipeline_state' => $estado, 'error_message' => $error],
            ['uid' => $uid],
        ) === 1;
    }

    /** @return array<string,mixed>|null */
    public function latest(): ?array
    {
        return $this->findWhere(
            columns: 'uid, local_date, captured_at, pipeline_state, mood_hint, audio_duration_ms',
            orderBy: 'captured_at DESC',
            limit: 1,
        )[0] ?? null;
    }
}
