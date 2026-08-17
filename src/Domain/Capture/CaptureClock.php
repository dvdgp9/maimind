<?php

declare(strict_types=1);

namespace MaiMind\Domain\Capture;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Resuelve los dos relojes de una captura.
 *
 * El cliente dice cuándo grabó y con qué desfase horario; el servidor dice
 * cuándo lo recibió. Se guardan los dos (`captured_at` y `received_at`) porque
 * son datos distintos, y de ahí sale el **día local** del usuario, que es lo que
 * usan todos los agregados diarios.
 *
 * El reloj del cliente no es de fiar: puede ir mal, o venir manipulado. Se acepta
 * dentro de una ventana razonable y fuera de ella se usa el del servidor, dejando
 * constancia. Ver docs/design/01-modelo-nucleo.md §3.
 */
final class CaptureClock
{
    /** Margen que se le concede al reloj del cliente. */
    private const TOLERANCE_SECONDS = 172800; // 48 h

    public function __construct(private readonly ?DateTimeImmutable $now = null)
    {
    }

    /**
     * @return array{
     *     captured_at:string, received_at:string, local_date:string,
     *     utc_offset:int, timezone:string, clock_was_adjusted:bool
     * }
     */
    public function resolve(?string $clientCapturedAt, ?string $timezone, mixed $utcOffset): array
    {
        $now = $this->now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $offset   = $this->normalizeOffset($utcOffset, $timezone, $now);
        $timezone = $this->normalizeTimezone($timezone);

        $captured = $this->parse($clientCapturedAt);
        $adjusted = false;

        if ($captured === null || abs($captured->getTimestamp() - $now->getTimestamp()) > self::TOLERANCE_SECONDS) {
            $captured = $now;
            $adjusted = $clientCapturedAt !== null;
        }

        // El día local es el que ve el usuario en su reloj. Sin esto, todo lo
        // grabado después de medianoche cae en el día equivocado y los agregados
        // diarios mienten desde el primer registro.
        $local = $captured->modify(sprintf('%+d minutes', $offset));

        return [
            'captured_at'        => $captured->format('Y-m-d H:i:s.v'),
            'received_at'        => $now->format('Y-m-d H:i:s.v'),
            'local_date'         => $local->format('Y-m-d'),
            'utc_offset'         => $offset,
            'timezone'           => $timezone,
            'clock_was_adjusted' => $adjusted,
        ];
    }

    private function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /** Minutos por delante de UTC. Madrid en verano: +120. */
    private function normalizeOffset(mixed $utcOffset, ?string $timezone, DateTimeImmutable $now): int
    {
        if (is_numeric($utcOffset)) {
            $minutes = (int) $utcOffset;

            // Rango real de husos horarios: de -12:00 a +14:00.
            if ($minutes >= -720 && $minutes <= 840) {
                return $minutes;
            }
        }

        // Sin desfase utilizable, se deduce de la zona declarada.
        if ($timezone !== null && in_array($timezone, timezone_identifiers_list(), true)) {
            return intdiv((new DateTimeZone($timezone))->getOffset($now), 60);
        }

        return 0;
    }

    private function normalizeTimezone(?string $timezone): string
    {
        return $timezone !== null && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'UTC';
    }
}
