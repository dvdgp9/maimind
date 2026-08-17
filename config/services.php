<?php

declare(strict_types=1);

return [
    // OpenRouter cubre transcripción y extracción, pero por endpoints distintos y
    // detrás de interfaces distintas. Ver docs/api/openrouter.md.
    'openrouter' => [
        'api_key'  => (string) env('OPENROUTER_API_KEY', ''),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),

        'transcription' => [
            // ASR real y verbatim. Nunca un LLM multimodal: parafrasea el habla y
            // rompe el anclaje de evidencia (citas literales con offsets).
            'model'    => env('OPENROUTER_TRANSCRIPTION_MODEL', 'openai/whisper-1'),
            'timeout'  => (int) env('OPENROUTER_TRANSCRIPTION_TIMEOUT', 120),
            'defaults' => [
                'temperature'             => 0,
                'response_format'         => 'verbose_json',
                'timestamp_granularities' => ['segment'],
            ],
        ],

        'extraction' => [
            // Se fija explícitamente: un cambio silencioso de modelo altera la
            // extracción y rompe la comparabilidad longitudinal de los datos.
            'model'   => (string) env('OPENROUTER_EXTRACTION_MODEL', ''),
            'timeout' => (int) env('OPENROUTER_EXTRACTION_TIMEOUT', 180),
        ],
    ],

    'audio' => [
        'retention_days' => (int) env('AUDIO_RETENTION_DAYS', 30),
        'max_bytes'      => 25 * 1024 * 1024, // límite de OpenRouter
        'allowed_mime'   => [
            'audio/webm', 'audio/ogg', 'audio/mpeg',
            'audio/mp4', 'audio/wav', 'audio/flac', 'audio/aac',
        ],
    ],

    'worker' => [
        'sleep_seconds'     => (int) env('WORKER_SLEEP_SECONDS', 5),
        'max_jobs_per_run'  => (int) env('WORKER_MAX_JOBS_PER_RUN', 50),
        // El servidor comparte 2 vCPU con otras aplicaciones. Uno basta: el
        // trabajo es esperar a APIs, no calcular.
        'concurrency'       => 1,
    ],
];
