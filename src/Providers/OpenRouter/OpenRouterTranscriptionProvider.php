<?php

declare(strict_types=1);

namespace MaiMind\Providers\OpenRouter;

use MaiMind\Pipeline\Transcription\AudioRef;
use MaiMind\Pipeline\Transcription\TranscriptionFailed;
use MaiMind\Pipeline\Transcription\TranscriptionProvider;
use MaiMind\Pipeline\Transcription\TranscriptionResult;
use MaiMind\Pipeline\Transcription\TranscriptionSegment;
use MaiMind\Support\Http\HttpClient;
use MaiMind\Support\Http\HttpTransportFailed;
use Throwable;

/**
 * Transcripción con OpenRouter.
 *
 * `POST /audio/transcriptions` con el audio en base64. Ver
 * `docs/api/openrouter.md` §1 para el contrato y §4 para la política de datos,
 * que va en **todas** las peticiones.
 *
 * El modelo se fija por configuración y nunca se deja al enrutado automático:
 * un cambio silencioso de modelo altera la transcripción y rompe la
 * comparabilidad longitudinal, que es de lo que va todo esto.
 */
final class OpenRouterTranscriptionProvider implements TranscriptionProvider
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly string $storagePath,
        /** Cabeceras de cortesía que OpenRouter usa para atribuir el tráfico. */
        private readonly string $appUrl = '',
        private readonly string $appName = 'MaiMind',
    ) {
    }

    public function name(): string
    {
        return 'openrouter';
    }

    public function transcribe(AudioRef $audio, ?string $languageHint = null): TranscriptionResult
    {
        if (trim($this->apiKey) === '') {
            // Permanente: reintentarlo cinco veces no va a hacer aparecer una
            // clave. Se arregla en el .env del servidor.
            throw TranscriptionFailed::permanent('Falta OPENROUTER_API_KEY.');
        }

        $respuesta = $this->llamar($this->cuerpo($audio, $languageHint));

        if (! $respuesta->ok()) {
            throw TranscriptionFailed::fromStatus($respuesta->status, $respuesta->errorMessage());
        }

        return $this->interpretar($respuesta->json(), $respuesta->latencyMs, $languageHint);
    }

    /** @return array<string,mixed> */
    private function cuerpo(AudioRef $audio, ?string $idioma): array
    {
        $ruta = $this->storagePath . '/' . ltrim($audio->path, '/');

        if (! is_file($ruta)) {
            // El audio se purga a los 30 días. Un trabajo que se quedó atascado
            // más tiempo del debido no tiene nada que transcribir, y eso no
            // mejora reintentándolo.
            throw TranscriptionFailed::permanent('El audio ya no está en disco: ' . $audio->path);
        }

        $bytes = @file_get_contents($ruta);

        if ($bytes === false) {
            throw TranscriptionFailed::temporary('No se pudo leer el audio: ' . $audio->path);
        }

        // Se comprueba antes de pagar la inferencia: si el fichero no es el que
        // se grabó, transcribirlo no sirve de nada y encima cuesta.
        if (hash('sha256', $bytes) !== $audio->sha256) {
            throw TranscriptionFailed::permanent('El audio del disco no coincide con su sha256.');
        }

        $cuerpo = [
            'model' => $this->model,
            'input_audio' => [
                // Base64 crudo, NO un data URI. Ver docs/api/openrouter.md §1.
                'data'   => base64_encode($bytes),
                'format' => $audio->format(),
            ],
            'response_format'         => 'verbose_json',
            'timestamp_granularities' => ['segment'],
            // Cero, siempre: esto no es escritura creativa. Un transcriptor que
            // improvisa rompe el anclaje de evidencia.
            'temperature'             => 0,
        ];

        if ($idioma !== null && $idioma !== '') {
            // Pasarlo ahorra latencia y errores frente a dejar autodetectar.
            $cuerpo['language'] = $idioma;
        }

        return DataPolicy::applyTo($cuerpo);
    }

    private function llamar(array $cuerpo): \MaiMind\Support\Http\HttpResponse
    {
        $cabeceras = [
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];

        if ($this->appUrl !== '') {
            $cabeceras['HTTP-Referer'] = $this->appUrl;
            $cabeceras['X-Title']      = $this->appName;
        }

        try {
            return $this->http->postJson(
                rtrim($this->baseUrl, '/') . '/audio/transcriptions',
                $cabeceras,
                (string) json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $this->timeoutSeconds,
            );
        } catch (HttpTransportFailed $e) {
            // Red, DNS, TLS, timeout. Todo eso vuelve.
            throw TranscriptionFailed::temporary($e->getMessage(), $e);
        } catch (Throwable $e) {
            throw TranscriptionFailed::permanent('Petición mal formada: ' . $e->getMessage(), $e);
        }
    }

    /** @param array<string,mixed> $datos */
    private function interpretar(array $datos, int $latencyMs, ?string $idiomaPedido): TranscriptionResult
    {
        $texto = trim((string) ($datos['text'] ?? ''));

        if ($texto === '') {
            // Puede ser un audio en silencio o un fallo del proveedor. Como no
            // se puede distinguir aquí, se trata como temporal: perder una
            // grabación por un silencio mal detectado es peor que un reintento.
            throw TranscriptionFailed::temporary('La transcripción ha vuelto vacía.');
        }

        return new TranscriptionResult(
            text: $texto,
            provider: $this->name(),
            model: $this->model,
            segments: $this->segmentos((array) ($datos['segments'] ?? [])),
            language: (string) ($datos['language'] ?? $idiomaPedido ?? '') ?: null,
            costMicros: TranscriptionResult::costToMicros(
                isset($datos['usage']['cost']) ? (float) $datos['usage']['cost'] : null
            ),
            latencyMs: $latencyMs,
        );
    }

    /**
     * @param  list<mixed>  $crudos
     * @return list<TranscriptionSegment>
     */
    private function segmentos(array $crudos): array
    {
        $segmentos = [];

        foreach ($crudos as $i => $crudo) {
            if (! is_array($crudo)) {
                continue;
            }

            $texto = trim((string) ($crudo['text'] ?? ''));

            if ($texto === '') {
                continue;
            }

            $segmentos[] = new TranscriptionSegment(
                index: (int) ($crudo['id'] ?? $i),
                text: $texto,
                // La API los da en segundos con decimales.
                startMs: (int) round(((float) ($crudo['start'] ?? 0)) * 1000),
                endMs: (int) round(((float) ($crudo['end'] ?? 0)) * 1000),
                confidence: self::confianza($crudo),
                providerMetrics: self::metricas($crudo),
            );
        }

        return $segmentos;
    }

    /**
     * Whisper no da una confianza: da `avg_logprob`, la media de los logaritmos
     * de probabilidad de los tokens del tramo.
     *
     * `exp()` lo devuelve a 0..1, que es lo que cabe en la columna, **pero no
     * es una probabilidad calibrada**: sirve para ordenar tramos —cuál conviene
     * mirar antes— y no para decir «esto es correcto al 87 %». Presentarlo como
     * lo segundo sería exactamente la precisión inventada que el problema 4 del
     * diseño existe para evitar. El `avg_logprob` crudo se conserva igualmente
     * en el JSON de segmentos por si algún día hace falta de verdad.
     *
     * Por debajo de -1 la propia documentación de Whisper dice que los logprobs
     * han fallado, así que ahí no se devuelve nada en vez de un número bajo que
     * parecería medir algo.
     *
     * @param  array<string,mixed>  $crudo
     */
    private static function confianza(array $crudo): ?float
    {
        if (! isset($crudo['avg_logprob']) || ! is_numeric($crudo['avg_logprob'])) {
            return null;
        }

        $logprob = (float) $crudo['avg_logprob'];

        if ($logprob < -1.0) {
            return null;
        }

        return round(min(1.0, max(0.0, exp($logprob))), 3);
    }

    /**
     * Los números crudos del proveedor, sin interpretar.
     *
     * @param  array<string,mixed>  $crudo
     * @return array<string,float>|null
     */
    private static function metricas(array $crudo): ?array
    {
        $metricas = [];

        foreach (['avg_logprob', 'no_speech_prob', 'compression_ratio'] as $clave) {
            if (isset($crudo[$clave]) && is_numeric($crudo[$clave])) {
                $metricas[$clave] = (float) $crudo[$clave];
            }
        }

        return $metricas === [] ? null : $metricas;
    }
}
