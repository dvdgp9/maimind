<?php

declare(strict_types=1);

namespace MaiMind\Pipeline\Transcription;

use MaiMind\Providers\OpenRouter\OpenRouterTranscriptionProvider;
use MaiMind\Support\Config;
use MaiMind\Support\Http\CurlHttpClient;
use MaiMind\Support\Http\HttpClient;
use RuntimeException;

/**
 * Elige el transcriptor según la configuración.
 *
 * Un único sitio donde se decide, para que el worker no sepa nada de
 * OpenRouter: cambiar de proveedor tiene que ser una clase nueva y una línea de
 * configuración (04-arquitectura.md §1).
 */
final class TranscriptionProviders
{
    public static function fromConfig(?HttpClient $http = null): TranscriptionProvider
    {
        $driver = (string) config('services.transcription.driver', 'openrouter');

        return match ($driver) {
            'fake' => new FakeTranscriptionProvider(),

            'openrouter' => new OpenRouterTranscriptionProvider(
                http: $http ?? new CurlHttpClient(),
                apiKey: (string) config('services.openrouter.api_key'),
                baseUrl: (string) config('services.openrouter.base_url'),
                model: (string) config('services.openrouter.transcription.model'),
                timeoutSeconds: (int) config('services.openrouter.transcription.timeout'),
                storagePath: Config::basePath((string) config('app.paths.storage')),
                appUrl: (string) config('app.url', ''),
                appName: (string) config('app.name', 'MaiMind'),
            ),

            default => throw new RuntimeException(
                "Transcriptor desconocido: '{$driver}'. Admitidos: openrouter, fake."
            ),
        };
    }
}
