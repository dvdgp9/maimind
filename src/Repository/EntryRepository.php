<?php

declare(strict_types=1);

namespace MaiMind\Repository;

use MaiMind\Support\Ulid;

/**
 * Registros de captura. De momento solo lo imprescindible para demostrar el
 * aislamiento entre usuarios; la API de captura completa llega en la tarea 1.2.
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

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 20): array
    {
        return $this->findWhere(
            columns: 'uid, source, captured_at, local_date, pipeline_state, mood_hint',
            orderBy: 'captured_at DESC',
            limit: $limit,
        );
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
    ): string {
        return $this->createDraft(
            uid: $uid,
            capturedAt: (string) $clock['captured_at'],
            localDate: (string) $clock['local_date'],
            timezone: (string) $clock['timezone'],
            utcOffsetMinutes: (int) $clock['utc_offset'],
            moodHint: $moodHint,
            extra: [
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
