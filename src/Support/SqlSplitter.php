<?php

declare(strict_types=1);

namespace MaiMind\Support;

/**
 * Parte un fichero SQL en sentencias sueltas.
 *
 * Existe porque ejecutar el fichero entero de golpe (multi-statement) da errores
 * inútiles: "algo ha fallado en este fichero de 400 líneas". Partiendo primero,
 * el migrador puede decir exactamente qué sentencia reventó.
 *
 * Reconoce cadenas entre comillas simples y dobles, identificadores entre
 * comillas invertidas, escapes con barra invertida, comillas duplicadas ('') y
 * comentarios (`-- `, `#`, y de bloque). Los `;` dentro de cualquiera de esos
 * contextos no cortan.
 *
 * NO soporta `DELIMITER`, así que no vale para procedimientos almacenados ni
 * triggers. El esquema no los usa; si algún día hacen falta, hay que ampliarlo.
 */
final class SqlSplitter
{
    private const NORMAL = 0;

    private const SINGLE_QUOTE = 1;

    private const DOUBLE_QUOTE = 2;

    private const BACKTICK = 3;

    private const LINE_COMMENT = 4;

    private const BLOCK_COMMENT = 5;

    /** @return list<string> */
    public static function split(string $sql): array
    {
        $statements = [];
        $current    = '';
        $state      = self::NORMAL;
        $length     = strlen($sql);
        $i          = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            switch ($state) {
                case self::NORMAL:
                    // En MySQL, `--` solo es comentario si le sigue un espacio.
                    if ($char === '-' && $next === '-'
                        && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
                        $state = self::LINE_COMMENT;
                        $i += 2;

                        continue 2;
                    }

                    if ($char === '#') {
                        $state = self::LINE_COMMENT;
                        $i++;

                        continue 2;
                    }

                    if ($char === '/' && $next === '*') {
                        $state = self::BLOCK_COMMENT;
                        $i += 2;

                        continue 2;
                    }

                    if ($char === ';') {
                        if (trim($current) !== '') {
                            $statements[] = trim($current);
                        }

                        $current = '';
                        $i++;

                        continue 2;
                    }

                    $state = match ($char) {
                        "'"     => self::SINGLE_QUOTE,
                        '"'     => self::DOUBLE_QUOTE,
                        '`'     => self::BACKTICK,
                        default => self::NORMAL,
                    };

                    $current .= $char;
                    $i++;

                    break;

                case self::SINGLE_QUOTE:
                case self::DOUBLE_QUOTE:
                    $quote = $state === self::SINGLE_QUOTE ? "'" : '"';

                    if ($char === '\\' && $next !== '') {
                        $current .= $char . $next;
                        $i += 2;

                        continue 2;
                    }

                    if ($char === $quote) {
                        // Comilla duplicada: sigue dentro de la cadena.
                        if ($next === $quote) {
                            $current .= $char . $next;
                            $i += 2;

                            continue 2;
                        }

                        $state = self::NORMAL;
                    }

                    $current .= $char;
                    $i++;

                    break;

                case self::BACKTICK:
                    if ($char === '`') {
                        $state = self::NORMAL;
                    }

                    $current .= $char;
                    $i++;

                    break;

                case self::LINE_COMMENT:
                    if ($char === "\n") {
                        $state = self::NORMAL;
                        $current .= $char;
                    }

                    $i++;

                    break;

                case self::BLOCK_COMMENT:
                    if ($char === '*' && $next === '/') {
                        $state = self::NORMAL;
                        $i += 2;

                        continue 2;
                    }

                    $i++;

                    break;
            }
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }
}
