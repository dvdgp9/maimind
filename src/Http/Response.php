<?php

declare(strict_types=1);

namespace MaiMind\Http;

final class Response
{
    /** @var array<string,string> */
    private array $headers = [];

    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    private array $cookies = [];

    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return (new self($status, $body))->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (new self($status, $body))->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return (new self($status))->withHeader('Location', $to);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    /** @param array<string,mixed> $options */
    public function withCookie(string $name, string $value, array $options = []): self
    {
        $clone = clone $this;
        $clone->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];

        return $clone;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return list<array{name:string,value:string,options:array<string,mixed>}> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * Cabeceras de seguridad que lleva toda respuesta.
     *
     * La CSP es restrictiva porque la aplicación no carga nada de terceros y no
     * debe empezar a hacerlo por accidente. `blob:` está permitido en media e
     * img porque la grabación de audio del navegador lo necesita.
     *
     * @return array<string,string>
     */
    public static function securityHeaders(): array
    {
        return [
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data: blob:; "
                . "media-src 'self' blob:; object-src 'none'; base-uri 'none'; form-action 'self'",
        ];
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ([...self::securityHeaders(), ...$this->headers] as $name => $value) {
            header("{$name}: {$value}", true);
        }

        foreach ($this->cookies as $cookie) {
            setcookie($cookie['name'], $cookie['value'], $cookie['options']);
        }

        echo $this->body;
    }
}
