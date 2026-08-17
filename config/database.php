<?php

declare(strict_types=1);

return [
    // MariaDB. Ver docs/design/02-esquema-mysql.md §0 sobre compatibilidad.
    //
    // Versión de producción. bin/check avisa si el servidor local difiere en
    // mayor.menor: el esquema no usa nada específico de una versión, pero una
    // divergencia silenciosa es justo la clase de cosa que solo se descubre al
    // desplegar.
    'target_version' => '11.4',

    'host'      => env('DB_HOST', '127.0.0.1'),
    'port'      => (int) env('DB_PORT', 3306),
    'database'  => env('DB_DATABASE', 'maimind'),
    'username'  => env('DB_USERNAME', 'maimind'),
    'password'  => (string) env('DB_PASSWORD', ''),
    'charset'   => env('DB_CHARSET', 'utf8mb4'),
    'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),

    'options' => [
        // La conexión trabaja en UTC pase lo que pase en el servidor.
        'time_zone' => '+00:00',
        'sql_mode'  => 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
    ],
];
