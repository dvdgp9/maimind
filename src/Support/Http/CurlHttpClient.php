<?php

declare(strict_types=1);

namespace MaiMind\Support\Http;

use CurlHandle;

/**
 * Cliente HTTP con curl, que es lo que hay en el servidor.
 */
final class CurlHttpClient implements HttpClient
{
    public function postJson(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        $curl = curl_init($url);

        if (! $curl instanceof CurlHandle) {
            throw new HttpTransportFailed('No se pudo inicializar curl.');
        }

        $cabeceras = ['Content-Type: application/json'];

        foreach ($headers as $nombre => $valor) {
            $cabeceras[] = $nombre . ': ' . $valor;
        }

        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            // Aparte del total: una conexión que no se establece en 10 s no se
            // va a establecer, y no tiene sentido gastar en ella el minuto
            // entero que puede necesitar una transcripción larga.
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Nada de seguir redirecciones: un POST con la clave de la API en
            // la cabecera no se manda a donde diga un tercero.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
        ]);

        $inicio    = microtime(true);
        $respuesta = curl_exec($curl);
        $latencia  = (int) round((microtime(true) - $inicio) * 1000);

        if ($respuesta === false) {
            $error = curl_error($curl);
            curl_close($curl);

            throw new HttpTransportFailed('No se pudo completar la petición: ' . $error);
        }

        $estado = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        curl_close($curl);

        return new HttpResponse($estado, (string) $respuesta, $latencia);
    }
}
