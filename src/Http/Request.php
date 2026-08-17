<?php

declare(strict_types=1);

namespace MaiMind\Http;

/**
 * Petición HTTP inmutable.
 *
 * Se puede construir a mano, no solo desde las superglobales: así los tests
 * ejercitan las rutas de verdad sin levantar un servidor.
 */
final class Request
{
    /**
     * @param  array<string,string>  $query
     * @param  array<string,mixed>   $body
     * @param  array<string,string>  $cookies
     * @param  array<string,string>  $headers
     * @param  array<string,array<string,mixed>>  $files  como $_FILES
     * @param  array<string,string>  $attributes  parámetros de ruta y extras
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $cookies = [],
        public readonly array $headers = [],
        public readonly string $ip = '127.0.0.1',
        public array $attributes = [],
        public readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        return new self(
            method:  strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path:    rtrim(is_string($path) ? $path : '/', '/') ?: '/',
            query:   array_map(strval(...), $_GET),
            body:    $_POST,
            cookies: array_map(strval(...), $_COOKIE),
            headers: $headers,
            ip:      self::clientIp(),
            files:   $_FILES,
        );
    }

    private static function clientIp(): string
    {
        // Producción va detrás de nginx, así que la IP real llega en cabecera.
        // Solo se confía en ella si la petición viene del proxy local.
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        if (in_array($remote, ['127.0.0.1', '::1'], true)) {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');

            if ($forwarded !== '') {
                return trim(explode(',', $forwarded)[0]);
            }
        }

        return $remote;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function attribute(string $name): ?string
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Fichero subido, solo si llegó entero.
     *
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
     */
    public function file(string $name): ?array
    {
        $file = $this->files[$name] ?? null;

        if (! is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        return $file;
    }

    /** Código de error de subida de PHP, para poder decir QUÉ falló. */
    public function fileError(string $name): int
    {
        return (int) ($this->files[$name]['error'] ?? UPLOAD_ERR_NO_FILE);
    }

    /** ¿El cliente espera JSON en vez de una página? */
    public function wantsJson(): bool
    {
        return str_contains($this->header('accept') ?? '', 'application/json')
            || str_contains($this->header('content-type') ?? '', 'application/json')
            || str_starts_with($this->path, '/api/');
    }
}
