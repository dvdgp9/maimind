<?php

declare(strict_types=1);

namespace MaiMind\Support\Http;

/**
 * Lo mínimo para hablar con una API.
 *
 * Existe para poder probar los proveedores sin red: un test que dependa de que
 * OpenRouter esté levantado y cobre por cada ejecución no es un test.
 *
 * No lanza por un 4xx o un 5xx —eso es una respuesta, y quien llama sabrá qué
 * significa en su caso— pero sí por un fallo de transporte, que no lo es.
 */
interface HttpClient
{
    /**
     * @param  array<string,string>  $headers
     *
     * @throws HttpTransportFailed  si no se llegó a hablar con nadie
     */
    public function postJson(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse;
}
