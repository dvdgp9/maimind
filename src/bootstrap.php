<?php

declare(strict_types=1);

use MaiMind\Support\Config;
use MaiMind\Support\Lang;
use MaiMind\Support\Logger;
use Psr\Log\LoggerInterface;

/**
 * Arranque común de la aplicación. Lo usan el front controller, el worker,
 * los scripts de bin/ y los tests.
 *
 * @return array{logger: LoggerInterface}
 */
return (static function (): array {
    $basePath = dirname(__DIR__);

    require $basePath . '/vendor/autoload.php';

    Config::boot($basePath);

    // Todo el sistema trabaja en UTC. La zona del usuario solo se aplica al
    // presentar y al calcular el día local. Ver docs/design/01-modelo-nucleo.md §3.
    date_default_timezone_set('UTC');

    Lang::boot(
        Config::basePath((string) config('app.paths.lang')),
        (string) config('app.locale', 'es'),
        (string) config('app.fallback_locale', 'es'),
    );

    $logger = new Logger(
        directory: Config::basePath((string) config('app.paths.logs')),
        minLevel: (string) config('app.log_level', 'debug'),
        alsoStderr: PHP_SAPI === 'cli' && (bool) config('app.debug', false),
    );

    return ['logger' => $logger];
})();
