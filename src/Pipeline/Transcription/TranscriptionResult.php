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

    /**
     * Trozos del audio que no están representados en ningún tramo.
     *
     * Es el detector de pérdida de contenido del sistema, y existe porque un
     * transcriptor puede saltarse un trozo de audio **sin que el texto lo
     * delate**: se lee con total fluidez y nadie lo nota. Lo único que queda
     * es el hueco entre el final de un tramo y el principio del siguiente.
     *
     * Devuelve `null` cuando **no se puede saber** —el proveedor no dio
     * tramos—, que no es lo mismo que devolver una lista vacía. Decir «no hay
     * pérdida» cuando no se ha podido mirar sería la clase de mentira que este
     * sistema no puede permitirse.
     *
     * @param  int  $toleranceMs  por debajo de esto es una pausa al hablar, no
     *   una pérdida. 1,5 s: las pausas entre frases rondan los 300-800 ms, y el
     *   caso real medido fue de 4600 ms.
     * @return list<array{start_ms:int,end_ms:int}>|null
     */
    public function coverageGaps(int $audioDurationMs, int $toleranceMs = 1500): ?array
    {
        if ($this->segments === [] || $audioDurationMs <= 0) {
            return null;
        }

        $huecos = [];
        $hasta  = 0;

        // Ordenados por tiempo: nada garantiza que el proveedor los dé en orden.
        $tramos = $this->segments;

        usort($tramos, static fn ($a, $b): int => $a->startMs <=> $b->startMs);

        foreach ($tramos as $tramo) {
            if ($tramo->startMs - $hasta > $toleranceMs) {
                $huecos[] = ['start_ms' => $hasta, 'end_ms' => $tramo->startMs];
            }

            $hasta = max($hasta, $tramo->endMs);
        }

        // Y el final: un tramo que acaba mucho antes que el audio también es
        // audio sin transcribir, aunque ahí sea más probable que sea silencio.
        if ($audioDurationMs - $hasta > $toleranceMs) {
            $huecos[] = ['start_ms' => $hasta, 'end_ms' => $audioDurationMs];
        }

        return $huecos;
    }

    /** Milisegundos de audio sin representar, o null si no se puede saber. */
    public function gapTotalMs(int $audioDurationMs, int $toleranceMs = 1500): ?int
    {
        $huecos = $this->coverageGaps($audioDurationMs, $toleranceMs);

        if ($huecos === null) {
            return null;
        }

        return array_sum(array_map(
            static fn (array $h): int => $h['end_ms'] - $h['start_ms'],
            $huecos,
        ));
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

    /**
     * @param  int|null  $audioDurationMs  para calcular la cobertura
     * @return array<string,mixed>  columnas de transcripts, sin ids
     */
    public function toRow(?int $audioDurationMs = null): array
    {
        $huecos = $audioDurationMs === null ? null : $this->coverageGaps($audioDurationMs);

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
            'gap_total_ms'   => $audioDurationMs === null ? null : $this->gapTotalMs($audioDurationMs),
            // NULL = no se sabe. [] = se miró y no hay.
            'coverage_gaps'  => $huecos === null
                ? null
                : json_encode($huecos, JSON_UNESCAPED_UNICODE),
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
