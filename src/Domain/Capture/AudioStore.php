<?php

declare(strict_types=1);

namespace MaiMind\Domain\Capture;

use RuntimeException;

/**
 * Guarda los ficheros de audio fuera de la raíz web.
 *
 * Ruta: storage/audio/{uid de usuario}/{año}/{mes}/{uid de entrada}.{ext}
 *
 * El uid del usuario y no su id numérico, para que un listado del directorio no
 * revele cuántas cuentas hay ni en qué orden se crearon. Y particionado por mes
 * porque un solo directorio con años de grabaciones se vuelve incómodo de mover,
 * respaldar y purgar.
 */
final class AudioStore
{
    /** Extensión por tipo, según lo que aceptan los navegadores y OpenRouter. */
    private const EXTENSIONS = [
        'audio/webm' => 'webm',
        'audio/ogg'  => 'ogg',
        'audio/mp4'  => 'm4a',
        'audio/mpeg' => 'mp3',
        'audio/aac'  => 'aac',
        'audio/wav'  => 'wav',
        'audio/flac' => 'flac',
    ];

    public function __construct(private readonly string $basePath)
    {
    }

    public static function normalizeMime(string $mime): string
    {
        // "audio/webm;codecs=opus" → "audio/webm"
        return mb_strtolower(trim(explode(';', $mime)[0]));
    }

    public static function isAccepted(string $mime): bool
    {
        return isset(self::EXTENSIONS[self::normalizeMime($mime)]);
    }

    public static function extensionFor(string $mime): string
    {
        return self::EXTENSIONS[self::normalizeMime($mime)]
            ?? throw new RuntimeException('Tipo de audio no admitido: ' . $mime);
    }

    /**
     * @return array{path:string,bytes:int,sha256:string}  path relativo a storage/
     */
    public function store(string $userUid, string $entryUid, string $tmpFile, string $mime): array
    {
        if (! is_file($tmpFile)) {
            throw new RuntimeException('El fichero temporal de audio no existe.');
        }

        $relative = sprintf(
            'audio/%s/%s/%s/%s.%s',
            $userUid,
            gmdate('Y'),
            gmdate('m'),
            $entryUid,
            self::extensionFor($mime),
        );

        $absolute = $this->basePath . '/' . $relative;

        $directory = dirname($absolute);

        if (! is_dir($directory) && ! @mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio de audio.');
        }

        // move_uploaded_file en peticiones reales; rename en los tests.
        $moved = is_uploaded_file($tmpFile)
            ? move_uploaded_file($tmpFile, $absolute)
            : rename($tmpFile, $absolute);

        if (! $moved) {
            throw new RuntimeException('No se pudo guardar el audio.');
        }

        @chmod($absolute, 0640);

        return [
            'path'   => $relative,
            'bytes'  => (int) filesize($absolute),
            'sha256' => (string) hash_file('sha256', $absolute),
        ];
    }

    public function absolutePath(string $relative): string
    {
        return $this->basePath . '/' . ltrim($relative, '/');
    }

    public function delete(string $relative): bool
    {
        $absolute = realpath($this->absolutePath($relative));

        // Nunca borrar fuera de storage/, pase lo que pase con la ruta guardada.
        if ($absolute === false || ! str_starts_with($absolute, realpath($this->basePath) . DIRECTORY_SEPARATOR)) {
            return false;
        }

        return @unlink($absolute);
    }
}
