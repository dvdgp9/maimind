<?php

declare(strict_types=1);

namespace MaiMind\Support;

use InvalidArgumentException;

/**
 * ULID: 26 caracteres en base32 Crockford.
 * 48 bits de marca temporal en milisegundos + 80 bits aleatorios.
 *
 * Se usa en la columna `uid` de las entidades que se exponen en URL, para no
 * publicar identificadores secuenciales. La clave primaria sigue siendo BIGINT.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const LENGTH = 26;

    public static function generate(?int $timeMs = null): string
    {
        $timeMs ??= (int) floor(microtime(true) * 1000);

        if ($timeMs < 0) {
            throw new InvalidArgumentException('La marca temporal de un ULID no puede ser negativa.');
        }

        $time = '';

        for ($i = 0; $i < 10; $i++) {
            $time = self::ALPHABET[$timeMs % 32] . $time;
            $timeMs = intdiv($timeMs, 32);
        }

        $random = '';

        for ($i = 0; $i < 16; $i++) {
            $random .= self::ALPHABET[random_int(0, 31)];
        }

        return $time . $random;
    }

    public static function isValid(string $ulid): bool
    {
        if (strlen($ulid) !== self::LENGTH) {
            return false;
        }

        return strspn($ulid, self::ALPHABET) === self::LENGTH;
    }

    /** Milisegundos desde epoch codificados en el ULID. */
    public static function timestamp(string $ulid): int
    {
        if (! self::isValid($ulid)) {
            throw new InvalidArgumentException('ULID no válido: ' . $ulid);
        }

        $timeMs = 0;

        for ($i = 0; $i < 10; $i++) {
            $timeMs = $timeMs * 32 + strpos(self::ALPHABET, $ulid[$i]);
        }

        return $timeMs;
    }
}
