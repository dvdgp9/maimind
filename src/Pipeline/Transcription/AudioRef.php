<?php

declare(strict_types=1);

namespace MaiMind\Pipeline\Transcription;

use InvalidArgumentException;

/**
 * Referencia a una grabación guardada, para pasársela a un transcriptor.
 *
 * Es una referencia y no el contenido: un audio de diez minutos son varios
 * megas y no tiene por qué estar en memoria mientras el trabajo espera en la
 * cola. Quien transcribe decide cuándo leerlo.
 *
 * Lleva el sha256 aunque el transcriptor no lo necesite: es lo que permite
 * comprobar, antes de pagar una inferencia, que el fichero del disco sigue
 * siendo el que se grabó.
 */
final class AudioRef
{
    public function __construct(
        /** Ruta relativa a storage/, tal como está en entries.audio_path. */
        public readonly string $path,
        public readonly string $mime,
        public readonly int $bytes,
        public readonly string $sha256,
        public readonly ?int $durationMs = null,
    ) {
        if (trim($path) === '') {
            throw new InvalidArgumentException('Un audio necesita una ruta.');
        }

        // Nunca fuera de storage/. La ruta viene de la base de datos, pero una
        // fila mal escrita no puede acabar leyendo /etc de la máquina.
        if (str_contains($path, '..')) {
            throw new InvalidArgumentException('Ruta de audio sospechosa: ' . $path);
        }

        if ($bytes <= 0) {
            throw new InvalidArgumentException('Un audio de cero bytes no es un audio.');
        }

        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('El sha256 no tiene la pinta de serlo.');
        }
    }

    /** El formato como lo espera OpenRouter: la extensión, no el MIME. */
    public function format(): string
    {
        return match (mb_strtolower(explode(';', $this->mime)[0])) {
            'audio/webm' => 'webm',
            'audio/ogg'  => 'ogg',
            'audio/mp4'  => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/aac'  => 'aac',
            'audio/wav'  => 'wav',
            'audio/flac' => 'flac',
            default      => throw new InvalidArgumentException('Formato no admitido: ' . $this->mime),
        };
    }

    public function seconds(): ?float
    {
        return $this->durationMs === null ? null : $this->durationMs / 1000;
    }
}
