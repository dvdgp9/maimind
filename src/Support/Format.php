<?php

declare(strict_types=1);

namespace MaiMind\Support;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;

/**
 * Fechas presentadas en la zona horaria y el idioma del usuario.
 *
 * Todo se guarda en UTC; aquí es donde se traduce a lo que la persona ve en su
 * reloj. Con `IntlDateFormatter` y no con `date()`, para que cambiar de idioma
 * no obligue a reescribir formatos a mano.
 */
final class Format
{
    public static function longDate(string $when, string $timezone, string $locale = 'es'): string
    {
        $date = self::toUserZone($when, $timezone);

        $formatter = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::FULL,
            IntlDateFormatter::NONE,
            $timezone,
        );

        // "martes, 17 de agosto de 2026" → "martes, 17 de agosto"
        $formatter->setPattern($locale === 'es' ? "EEEE, d 'de' MMMM" : 'EEEE, d MMMM');

        return (string) $formatter->format($date);
    }

    public static function time(string $when, string $timezone, string $locale = 'es'): string
    {
        $formatter = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $timezone,
        );

        // Patrón fijo: el formato corto de es-ES da "3:29" y aquí se prefiere
        // "03:29", que se lee mejor en una lista y no deja dudas de la hora.
        $formatter->setPattern('HH:mm');

        return (string) $formatter->format(self::toUserZone($when, $timezone));
    }

    /**
     * "hoy, 23:04" · "ayer, 09:12" · "12 de agosto, 18:30"
     */
    public static function relativeDay(
        string $localDate,
        string $capturedAt,
        string $timezone,
        string $locale = 'es',
    ): string {
        $hoy   = self::todayIn($timezone);
        $ayer  = (new DateTimeImmutable($hoy, new DateTimeZone($timezone)))
            ->modify('-1 day')->format('Y-m-d');

        $hora = self::time($capturedAt, $timezone, $locale);

        if ($localDate === $hoy) {
            return t('capture.today') . ', ' . $hora;
        }

        if ($localDate === $ayer) {
            return t('capture.yesterday') . ', ' . $hora;
        }

        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $timezone);
        $formatter->setPattern($locale === 'es' ? "d 'de' MMMM" : 'd MMMM');

        return $formatter->format(new DateTimeImmutable($localDate, new DateTimeZone($timezone)))
            . ', ' . $hora;
    }

    public static function todayIn(string $timezone): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
    }

    private static function toUserZone(string $when, string $timezone): DateTimeImmutable
    {
        $utc = $when === 'now'
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : new DateTimeImmutable($when, new DateTimeZone('UTC'));

        return $utc->setTimezone(new DateTimeZone($timezone));
    }
}
