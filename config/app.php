<?php

declare(strict_types=1);

return [
    'name'  => env('APP_NAME', 'MaiMind'),
    'env'   => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url'   => env('APP_URL', 'http://localhost:8080'),
    'key'   => env('APP_KEY', ''),

    // Todo se almacena en UTC, sin excepciones. Cada usuario lleva su propia zona
    // en users.timezone, que es la que se usa para presentar y para calcular el
    // día local (occurred_date). Ver docs/design/01-modelo-nucleo.md §3.
    'timezone' => 'UTC',

    'locale'           => env('APP_LOCALE', 'es'),
    'fallback_locale'  => env('APP_FALLBACK_LOCALE', 'es'),
    'supported_locales' => ['es', 'en'],

    'log_level' => env('LOG_LEVEL', 'debug'),

    'paths' => [
        'storage' => 'storage',
        'audio'   => 'storage/audio',
        'logs'    => 'storage/logs',
        'tmp'     => 'storage/tmp',
        'lang'    => 'resources/lang',
        'prompts' => 'resources/prompts',
        'schemas' => 'resources/schemas',
    ],
];
