<?php

declare(strict_types=1);

namespace MaiMind\Http;

final class Response
{
    /** @var array<string,string> */
    private array $headers = [];

    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    private array $cookies = [];

    /**
     * Un fichero que se manda desde disco en vez de desde memoria.
     *
     * @var array{path:string,offset:int,length:int}|null
     */
    private ?array $file = null;

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

    /**
     * Manda un fichero del disco sin cargarlo entero en memoria.
     *
     * Importa: una grabación puede pesar 25 MB y el servidor tiene 3,7 GB
     * compartidos con el correo y otra aplicación. Leer el fichero a una
     * cadena para escupirlo es gratis con un usuario y un problema con diez.
     *
     * @param  array{0:int,1:int}|null  $range  byte inicial y final, inclusive
     */
    public static function file(
        string $path,
        string $contentType,
        ?array $range = null,
        int $status = 200,
    ): self {
        $tamano = (int) filesize($path);

        [$desde, $hasta] = $range ?? [0, $tamano - 1];

        $respuesta = (new self($status))
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Length', (string) ($hasta - $desde + 1))
            // Sin esto, algunos navegadores no dejan mover la barra de
            // reproducción: piden un trozo, no se les da, y se rinden.
            ->withHeader('Accept-Ranges', 'bytes')
            // El audio es de una persona concreta: ni cachés compartidas ni
            // intermediarios guardándolo.
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        if ($range !== null) {
            $respuesta = $respuesta->withHeader(
                'Content-Range',
                sprintf('bytes %d-%d/%d', $desde, $hasta, $tamano),
            );
        }

        $respuesta->file = ['path' => $path, 'offset' => $desde, 'length' => $hasta - $desde + 1];

        return $respuesta;
    }

    /** @return array{path:string,offset:int,length:int}|null */
    public function fileInfo(): ?array
    {
        return $this->file;
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return (new self($status))->withHeader('Location', $to);
    }

    public function withHeader(string $name, string $value): self
    {
        // clone copia $file también, que es lo que hace que file() pueda
        // encadenar cabeceras sin perder el fichero por el camino.
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

        if ($this->file !== null) {
            $manejador = fopen($this->file['path'], 'rb');

            if ($manejador !== false) {
                fseek($manejador, $this->file['offset']);
                // A trozos, no de golpe: es lo que evita tener 25 MB en
                // memoria por cada persona que le da al play.
                $restante = $this->file['length'];

                while ($restante > 0 && ! feof($manejador)) {
                    $trozo = fread($manejador, (int) min(262144, $restante));

                    if ($trozo === false) {
                        break;
                    }

                    echo $trozo;
                    $restante -= strlen($trozo);
                }

                fclose($manejador);
            }

            return;
        }

        echo $this->body;
    }
}
