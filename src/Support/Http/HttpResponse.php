<?php

declare(strict_types=1);

namespace MaiMind\Support\Http;

use JsonException;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly int $latencyMs = 0,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * El mensaje de error del cuerpo, si lo trae.
     *
     * Se recorta: las APIs devuelven a veces cuerpos enormes y esto acaba en
     * `jobs.last_error`, que se lee en una terminal.
     */
    public function errorMessage(): string
    {
        $cuerpo = $this->json();

        $mensaje = $cuerpo['error']['message']
            ?? $cuerpo['error']
            ?? $cuerpo['message']
            ?? null;

        if (! is_string($mensaje) || trim($mensaje) === '') {
            $mensaje = mb_substr(trim($this->body), 0, 300);
        }

        return sprintf('HTTP %d: %s', $this->status, $mensaje === '' ? '(sin cuerpo)' : $mensaje);
    }
}
