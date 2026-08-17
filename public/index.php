<?php

declare(strict_types=1);

/**
 * Front controller. Único punto de entrada expuesto por el servidor web.
 */

use MaiMind\Http\Kernel;
use MaiMind\Http\Request;
use MaiMind\Support\Database;

// El servidor embebido de PHP enruta TODO por este fichero cuando se le pasa
// como script de router, estáticos incluidos. Devolver false le dice que sirva
// el fichero tal cual. En producción esto lo hace nginx y esta rama no se toca.
if (PHP_SAPI === 'cli-server') {
    $ruta = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $fichero = realpath(__DIR__ . (is_string($ruta) ? $ruta : ''));

    if ($fichero !== false && is_file($fichero) && str_starts_with($fichero, __DIR__ . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

$app = require dirname(__DIR__) . '/src/bootstrap.php';

$kernel = new Kernel(
    pdo: Database::connection(),
    logger: $app['logger'],
    appKey: (string) config('app.key'),
    secureCookies: str_starts_with((string) config('app.url'), 'https://'),
    debug: (bool) config('app.debug'),
);

$kernel->handle(Request::fromGlobals())->send();
