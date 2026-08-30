<?php

declare(strict_types=1);

namespace MaiMind\Pipeline\Transcription;

use RuntimeException;
use Throwable;

/**
 * Falló una transcripción.
 *
 * Distingue si tiene sentido repetirla, porque la cola no puede adivinarlo y
 * las dos equivocaciones cuestan: reintentar cinco veces un audio que el
 * proveedor nunca va a aceptar son cinco llamadas de pago tiradas, y dar por
 * muerta una que solo falló porque la API estaba caída pierde la grabación.
 */
final class TranscriptionFailed extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly bool $retryable,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** La API no está, va lenta o ha devuelto un 5xx. Volverá. */
    public static function temporary(string $message, ?Throwable $previous = null): self
    {
        return new self($message, retryable: true, previous: $previous);
    }

    /** El audio o la petición no valen. Repetirlo dará exactamente lo mismo. */
    public static function permanent(string $message, ?Throwable $previous = null): self
    {
        return new self($message, retryable: false, previous: $previous);
    }

    /**
     * Clasifica por código HTTP.
     *
     * 429 cuenta como temporal: es «ahora no», no «esto no». Y 401 también,
     * aunque suene raro — una clave mal puesta se arregla en el servidor sin
     * tocar la cola, y dar por muertas las transcripciones mientras tanto
     * perdería el trabajo de todo el mundo.
     */
    public static function fromStatus(int $status, string $message): self
    {
        return $status >= 500 || $status === 429 || $status === 408 || $status === 401
            ? self::temporary($message)
            : self::permanent($message);
    }
}
