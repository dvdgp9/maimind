<?php

declare(strict_types=1);

namespace MaiMind\Support\Http;

use RuntimeException;

/**
 * Cliente HTTP de mentira, para los tests.
 *
 * Guarda los cuerpos que se le mandan además de las respuestas que devuelve:
 * en un proveedor, la mitad de lo que puede estar mal está en la petición
 * —el modelo equivocado, el audio sin codificar, la política de datos que no
 * se envió— y eso no se ve mirando lo que devuelve.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{url:string,headers:array<string,string>,body:string}> */
    private array $peticiones = [];

    /** @var list<HttpResponse|HttpTransportFailed> */
    private array $guion = [];

    public function willRespond(HttpResponse|HttpTransportFailed ...$respuestas): self
    {
        foreach ($respuestas as $respuesta) {
            $this->guion[] = $respuesta;
        }

        return $this;
    }

    /** @param array<string,mixed> $cuerpo */
    public function willReturnJson(array $cuerpo, int $estado = 200, int $latencyMs = 120): self
    {
        return $this->willRespond(new HttpResponse(
            $estado,
            (string) json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
            $latencyMs,
        ));
    }

    public function postJson(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        $this->peticiones[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

        $siguiente = array_shift($this->guion);

        if ($siguiente instanceof HttpTransportFailed) {
            throw $siguiente;
        }

        return $siguiente ?? new HttpResponse(200, '{}');
    }

    /** @return array{url:string,headers:array<string,string>,body:string} */
    public function lastRequest(): array
    {
        return $this->peticiones[array_key_last($this->peticiones)]
            ?? throw new RuntimeException('No se ha hecho ni una petición.');
    }

    /** @return array<string,mixed> */
    public function lastBody(): array
    {
        $decoded = json_decode($this->lastRequest()['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    public function requestCount(): int
    {
        return count($this->peticiones);
    }
}
