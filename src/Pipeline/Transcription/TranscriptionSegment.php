<?php

declare(strict_types=1);

namespace MaiMind\Pipeline\Transcription;

use InvalidArgumentException;

/**
 * Un tramo de la transcripción con sus tiempos.
 *
 * Los segmentos son lo que permite volver del dato al audio: «esto lo dijiste
 * en el minuto 3:12». Sin ellos, una tarjeta de revisión solo puede enseñar
 * texto, y comprobar si el modelo entendió bien exige reescuchar entera una
 * grabación de diez minutos.
 *
 * Los offsets de carácter se calculan al montar el resultado, no los da el
 * proveedor: son posiciones sobre el texto final, y ese texto lo compone quien
 * junta los segmentos.
 *
 * **Van en caracteres, no en bytes.** El texto es español y está lleno de
 * acentos y eñes: en bytes, un offset señalaría media letra. Todo lo que toque
 * estos números tiene que usar `mb_substr` y compañía.
 */
final class TranscriptionSegment
{
    public function __construct(
        public readonly int $index,
        public readonly string $text,
        public readonly int $startMs,
        public readonly int $endMs,
        /** Confianza del ASR, 0..1. Whisper no siempre la da. */
        public readonly ?float $confidence = null,
        public readonly ?int $charStart = null,
        public readonly ?int $charEnd = null,
    ) {
        if ($startMs < 0 || $endMs < $startMs) {
            throw new InvalidArgumentException(
                "Tramo con tiempos imposibles: {$startMs}..{$endMs}"
            );
        }

        if ($confidence !== null && ($confidence < 0 || $confidence > 1)) {
            throw new InvalidArgumentException('La confianza va de 0 a 1, no ' . $confidence);
        }
    }

    public function withCharRange(int $start, int $end): self
    {
        return new self(
            $this->index,
            $this->text,
            $this->startMs,
            $this->endMs,
            $this->confidence,
            $start,
            $end,
        );
    }

    /** @return array<string,mixed>  tal como se guarda en transcripts.segments */
    public function toArray(): array
    {
        return [
            'i'          => $this->index,
            'text'       => $this->text,
            'start_ms'   => $this->startMs,
            'end_ms'     => $this->endMs,
            'confidence' => $this->confidence,
            'char_start' => $this->charStart,
            'char_end'   => $this->charEnd,
        ];
    }

    /** @param array<string,mixed> $fila */
    public static function fromArray(array $fila): self
    {
        return new self(
            index: (int) ($fila['i'] ?? 0),
            text: (string) ($fila['text'] ?? ''),
            startMs: (int) ($fila['start_ms'] ?? 0),
            endMs: (int) ($fila['end_ms'] ?? 0),
            confidence: isset($fila['confidence']) ? (float) $fila['confidence'] : null,
            charStart: isset($fila['char_start']) ? (int) $fila['char_start'] : null,
            charEnd: isset($fila['char_end']) ? (int) $fila['char_end'] : null,
        );
    }
}
