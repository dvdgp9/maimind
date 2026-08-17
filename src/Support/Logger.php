<?php

declare(strict_types=1);

namespace MaiMind\Support;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Logger PSR-3 a fichero, una línea JSON por registro.
 *
 * Regla del proyecto: la salida debe traer información útil para depurar. Por eso
 * cada línea lleva contexto estructurado en lugar de un mensaje plano.
 *
 * Aviso: este sistema maneja material extremadamente personal. NUNCA registrar
 * transcripciones, audio ni contenido de observaciones. Identificadores sí; contenido no.
 */
final class Logger extends AbstractLogger
{
    private const LEVEL_WEIGHT = [
        LogLevel::DEBUG     => 0,
        LogLevel::INFO      => 1,
        LogLevel::NOTICE    => 2,
        LogLevel::WARNING   => 3,
        LogLevel::ERROR     => 4,
        LogLevel::CRITICAL  => 5,
        LogLevel::ALERT     => 6,
        LogLevel::EMERGENCY => 7,
    ];

    public function __construct(
        private readonly string $directory,
        private readonly string $minLevel = LogLevel::DEBUG,
        private readonly bool $alsoStderr = false,
    ) {
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $level = (string) $level;

        if (! $this->passesThreshold($level)) {
            return;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $record = [
            'ts'      => $now->format('Y-m-d\TH:i:s.vP'),
            'level'   => $level,
            'message' => (string) $message,
        ];

        if ($context !== []) {
            $record['context'] = $context;
        }

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            $line = json_encode([
                'ts'      => $record['ts'],
                'level'   => LogLevel::ERROR,
                'message' => 'No se pudo serializar el registro de log',
            ]);
        }

        $this->write($now, (string) $line);
    }

    private function write(DateTimeImmutable $now, string $line): void
    {
        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0770, true);
        }

        $file = sprintf('%s/app-%s.log', rtrim($this->directory, '/'), $now->format('Y-m-d'));

        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        if ($this->alsoStderr) {
            @file_put_contents('php://stderr', $line . PHP_EOL);
        }
    }

    private function passesThreshold(string $level): bool
    {
        $current = self::LEVEL_WEIGHT[$level] ?? 0;
        $minimum = self::LEVEL_WEIGHT[$this->minLevel] ?? 0;

        return $current >= $minimum;
    }
}
