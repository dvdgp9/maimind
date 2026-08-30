<?php

declare(strict_types=1);

namespace MaiMind\Pipeline\Transcription;

use InvalidArgumentException;

/**
 * Lo que devuelve un transcriptor, sea cual sea.
 *
 * Todo lo derivable se calcula aquí y no lo pasa quien construye: el número de
 * palabras y la confianza media salen del texto y de los tramos. Si los pasara
 * el proveedor, dos proveedores contarían distinto y las comparaciones entre
 * ellos dejarían de significar nada.
 *
 * El coste va en **micros** (millonésimas de dólar) y como entero, igual que
 * `transcripts.cost_micros`. En coma flotante, sumar miles de costes de cinco
 * decimales acumula error.
 */
final class TranscriptionResult
{
    /** @var list<TranscriptionSegment> */
    public readonly array $segments;

    public readonly int $wordCount;

    public readonly ?float $avgConfidence;

    /**
     * @param  list<TranscriptionSegment>  $segments
     */
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        array $segments = [],
        public readonly ?string $language = null,
        public readonly ?int $costMicros = null,
        public readonly ?int $latencyMs = null,
    ) {
        if (trim($text) === '') {
            // Una transcripción vacía no es un resultado: o el audio estaba en
            // silencio o algo falló. Quien llama tiene que decidir cuál, y para
            // eso hace falta que esto reviente en vez de guardar una fila vacía.
            throw new InvalidArgumentException('Una transcripción vacía no es una transcripción.');
        }

        if (trim($provider) === '' || trim($model) === '') {
            // Sin esto no se puede saber qué motor produjo qué dato, que es
            // media razón de tener interfaces (04-arquitectura.md §1).
            throw new InvalidArgumentException('Toda transcripción dice qué la produjo.');
        }

        $this->segments      = self::anclar($segments, $text);
        $this->wordCount     = self::contarPalabras($text);
        $this->avgConfidence = self::confianzaMedia($this->segments);
    }

    /**
     * Sitúa cada tramo dentro del texto final.
     *
     * Se busca de verdad en el texto en vez de ir sumando longitudes: los
     * proveedores meten y quitan espacios entre tramos, y una suma acumularía
     * el desfase hasta dejar las citas apuntando a palabras equivocadas. Un
     * tramo que no aparezca se queda sin anclaje, que es honesto; inventarle
     * unos offsets sería peor que no tenerlos.
     *
     * @param  list<TranscriptionSegment>  $segments
     * @return list<TranscriptionSegment>
     */
    private static function anclar(array $segments, string $texto): array
    {
        $anclados = [];
        $desde    = 0;

        foreach ($segments as $segmento) {
            $aguja = trim($segmento->text);

            if ($aguja === '') {
                $anclados[] = $segmento;

                continue;
            }

            $posicion = mb_strpos($texto, $aguja, $desde);

            if ($posicion === false) {
                $anclados[] = $segmento;

                continue;
            }

            $fin        = $posicion + mb_strlen($aguja);
            $anclados[] = $segmento->withCharRange($posicion, $fin);
            $desde      = $fin;
        }

        return $anclados;
    }

    private static function contarPalabras(string $texto): int
    {
        return count(preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** @param list<TranscriptionSegment> $segments */
    private static function confianzaMedia(array $segments): ?float
    {
        $valores = array_values(array_filter(
            array_map(static fn (TranscriptionSegment $s): ?float => $s->confidence, $segments),
            static fn (?float $c): bool => $c !== null,
        ));

        if ($valores === []) {
            return null;
        }

        // A tres decimales, que es lo que admite transcripts.avg_confidence.
        return round(array_sum($valores) / count($valores), 3);
    }

    /** ¿Hay tramos y están todos situados en el texto? */
    public function isFullyAnchored(): bool
    {
        if ($this->segments === []) {
            return false;
        }

        foreach ($this->segments as $segmento) {
            if ($segmento->charStart === null) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,mixed>  columnas de transcripts, sin ids */
    public function toRow(): array
    {
        return [
            'provider'       => $this->provider,
            'model'          => $this->model,
            'language'       => $this->language,
            'text'           => $this->text,
            'word_count'     => $this->wordCount,
            'avg_confidence' => $this->avgConfidence,
            'segments'       => $this->segments === []
                ? null
                : json_encode(
                    array_map(static fn (TranscriptionSegment $s): array => $s->toArray(), $this->segments),
                    JSON_UNESCAPED_UNICODE,
                ),
            'cost_micros'    => $this->costMicros,
            'latency_ms'     => $this->latencyMs,
        ];
    }

    /** Convierte el coste en dólares que devuelve la API a micros enteros. */
    public static function costToMicros(?float $dolares): ?int
    {
        return $dolares === null ? null : (int) round($dolares * 1_000_000);
    }
}
