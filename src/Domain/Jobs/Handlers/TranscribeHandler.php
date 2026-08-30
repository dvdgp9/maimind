<?php

declare(strict_types=1);

namespace MaiMind\Domain\Jobs\Handlers;

use MaiMind\Domain\Jobs\JobHandler;
use MaiMind\Domain\Jobs\JobQueue;
use MaiMind\Pipeline\Transcription\AudioRef;
use MaiMind\Pipeline\Transcription\TranscriptionFailed;
use MaiMind\Pipeline\Transcription\TranscriptionProvider;
use MaiMind\Repository\EntryRepository;
use MaiMind\Repository\TranscriptRepository;
use MaiMind\Repository\UserRepository;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Convierte una grabación en texto.
 *
 * Primer paso del pipeline. Al terminar encola `extract`, que hasta la fase 3
 * no tiene manejador: el worker lo aparta sin gastarle intentos y se ejecutará
 * solo cuando esa fase se despliegue.
 *
 * **Idempotente**: si la entrada ya tiene transcripción vigente, no se vuelve a
 * pagar una inferencia. El trabajo puede repetirse porque el worker muriera
 * justo después de guardar y antes de marcarlo hecho.
 */
final class TranscribeHandler implements JobHandler
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TranscriptionProvider $provider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function type(): string
    {
        return 'transcribe';
    }

    public function handle(array $payload, array $job): void
    {
        $userId = (int) ($job['user_id'] ?? 0);
        $uid    = (string) ($payload['entry'] ?? '');

        if ($userId <= 0 || $uid === '') {
            throw new RuntimeException('transcribe necesita un user_id y el uid de la entrada.');
        }

        $entries = new EntryRepository($this->pdo, $userId);
        $entrada = $entries->forTranscription($uid);

        if ($entrada === null) {
            // Borrada mientras esperaba en la cola. No es un fallo del sistema.
            $this->logger->info('Transcripción cancelada: la entrada ya no existe', [
                'user_id' => $userId, 'entry' => $uid,
            ]);

            return;
        }

        $transcripts = new TranscriptRepository($this->pdo, $userId);

        if ($transcripts->hasCurrentFor((int) $entrada['id'])) {
            $this->logger->info('Transcripción ya hecha, no se repite', ['entry' => $uid]);

            return;
        }

        if ($entrada['audio_state'] !== 'present' || $entrada['audio_path'] === null) {
            // Purgada a los 30 días, o nunca llegó a guardarse. No hay nada que
            // transcribir y reintentarlo no lo va a arreglar.
            $entries->moveToState($uid, 'failed', 'El audio ya no está disponible.');

            $this->logger->warning('Transcripción imposible: sin audio', [
                'entry' => $uid, 'audio_state' => $entrada['audio_state'],
            ]);

            return;
        }

        $entries->moveToState($uid, 'transcribing');

        try {
            $resultado = $this->provider->transcribe(
                new AudioRef(
                    path: (string) $entrada['audio_path'],
                    mime: (string) $entrada['audio_mime'],
                    bytes: (int) $entrada['audio_bytes'],
                    sha256: (string) $entrada['audio_sha256'],
                    durationMs: $entrada['audio_duration_ms'] === null
                        ? null
                        : (int) $entrada['audio_duration_ms'],
                ),
                $this->localeOf($userId),
            );
        } catch (TranscriptionFailed $e) {
            // Un fallo definitivo deja la entrada marcada; uno temporal la
            // devuelve a 'captured' para que el reintento la encuentre como
            // estaba. Dejarla en 'transcribing' haría creer que hay un worker
            // trabajando en ella cuando no lo hay.
            $entries->moveToState(
                $uid,
                $e->retryable ? 'captured' : 'failed',
                $e->getMessage(),
            );

            throw $e;
        }

        $duracion = $entrada['audio_duration_ms'] === null
            ? null
            : (int) $entrada['audio_duration_ms'];

        $transcripts->storeAsCurrent((int) $entrada['id'], $resultado, $duracion);
        $entries->moveToState($uid, 'transcribed');

        // Audio que no aparece en el texto. No es un fallo —la transcripción
        // sirve igual— pero tiene que constar: un transcriptor puede saltarse
        // una frase entera sin que el texto lo delate, y entonces desaparece
        // un acontecimiento que la persona sí contó.
        $huecos = $duracion === null ? null : $resultado->coverageGaps($duracion);

        if ($huecos !== null && $huecos !== []) {
            $this->logger->warning('La transcripción no cubre todo el audio', [
                'entry'   => $uid,
                'model'   => $resultado->model,
                'gap_ms'  => $resultado->gapTotalMs($duracion),
                'gaps'    => $huecos,
            ]);
        }

        // Nunca el texto en el registro: es exactamente lo que esta aplicación
        // existe para proteger.
        $this->logger->info('Transcripción guardada', [
            'user_id'  => $userId,
            'entry'    => $uid,
            'provider' => $resultado->provider,
            'model'    => $resultado->model,
            'words'    => $resultado->wordCount,
            'cost'     => $resultado->costMicros,
            'ms'       => $resultado->latencyMs,
        ]);

        (new JobQueue($this->pdo))->push(
            type: 'extract',
            payload: ['entry' => $uid],
            userId: $userId,
            dedupeKey: 'extract:' . $uid,
            priority: 4,
        );
    }

    /** El idioma del usuario ayuda al ASR; no le obliga a nada. */
    private function localeOf(int $userId): ?string
    {
        $usuario = (new UserRepository($this->pdo))->findById($userId);

        return $usuario?->locale ?: null;
    }
}
