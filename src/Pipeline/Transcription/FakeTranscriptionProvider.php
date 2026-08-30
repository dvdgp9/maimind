<?php

declare(strict_types=1);

namespace MaiMind\Pipeline\Transcription;

use RuntimeException;

/**
 * Transcriptor de mentira, para los tests y para desarrollar sin gastar.
 *
 * No está en tests/ a propósito: también sirve para levantar el sistema entero
 * en local sin clave de OpenRouter y sin pagar una inferencia cada vez que se
 * prueba la pantalla de revisión. Se elige por configuración, como cualquier
 * otro proveedor.
 *
 * Guarda lo que se le pide para poder comprobar **con qué** se le llamó, no
 * solo qué devolvió: media parte de lo que puede salir mal en la fase 2 es
 * mandar el audio equivocado o el idioma equivocado.
 */
final class FakeTranscriptionProvider implements TranscriptionProvider
{
    /** @var list<array{audio:AudioRef,language:?string}> */
    private array $llamadas = [];

    /** @var list<TranscriptionResult|TranscriptionFailed> */
    private array $guion = [];

    public function __construct(
        private readonly string $textoPorDefecto = 'Hoy he dormido fatal y estoy agotado.',
        private readonly string $modelo = 'fake/whisper',
    ) {
    }

    public function name(): string
    {
        return 'fake';
    }

    /**
     * Encola lo siguiente que devolverá, en orden.
     *
     * Permite escribir el caso que de verdad preocupa: falla dos veces y a la
     * tercera va bien. Con un solo valor fijo eso no se puede probar.
     */
    public function willReturn(TranscriptionResult|TranscriptionFailed ...$respuestas): self
    {
        foreach ($respuestas as $respuesta) {
            $this->guion[] = $respuesta;
        }

        return $this;
    }

    public function willFail(string $mensaje = 'la API no responde', bool $retryable = true): self
    {
        return $this->willReturn($retryable
            ? TranscriptionFailed::temporary($mensaje)
            : TranscriptionFailed::permanent($mensaje));
    }

    public function transcribe(AudioRef $audio, ?string $languageHint = null): TranscriptionResult
    {
        $this->llamadas[] = ['audio' => $audio, 'language' => $languageHint];

        $siguiente = array_shift($this->guion);

        if ($siguiente instanceof TranscriptionFailed) {
            throw $siguiente;
        }

        return $siguiente ?? $this->porDefecto($audio, $languageHint);
    }

    /**
     * Un resultado creíble: tramos con tiempos repartidos por la duración real
     * del audio, para que lo que dependa de los tiempos se pueda probar.
     */
    private function porDefecto(AudioRef $audio, ?string $idioma): TranscriptionResult
    {
        $frases = preg_split('/(?<=[.!?])\s+/u', $this->textoPorDefecto, -1, PREG_SPLIT_NO_EMPTY)
            ?: [$this->textoPorDefecto];

        $duracion = $audio->durationMs ?? (count($frases) * 3000);
        $porTramo = intdiv($duracion, max(1, count($frases)));

        $segmentos = [];

        foreach (array_values($frases) as $i => $frase) {
            $segmentos[] = new TranscriptionSegment(
                index: $i,
                text: $frase,
                startMs: $i * $porTramo,
                endMs: ($i + 1) * $porTramo,
                confidence: 0.9,
            );
        }

        return new TranscriptionResult(
            text: $this->textoPorDefecto,
            provider: $this->name(),
            model: $this->modelo,
            segments: $segmentos,
            language: $idioma ?? 'es',
            // Gratis, y que se note: una fila con coste 0 y proveedor 'fake' no
            // se puede confundir con una real al sumar gastos.
            costMicros: 0,
            latencyMs: 1,
        );
    }

    /** @return list<array{audio:AudioRef,language:?string}> */
    public function calls(): array
    {
        return $this->llamadas;
    }

    public function callCount(): int
    {
        return count($this->llamadas);
    }

    /** @return array{audio:AudioRef,language:?string} */
    public function lastCall(): array
    {
        return $this->llamadas[array_key_last($this->llamadas)]
            ?? throw new RuntimeException('No se ha llamado al transcriptor ni una vez.');
    }
}
