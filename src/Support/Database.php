<?php

declare(strict_types=1);

namespace MaiMind\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexión PDO a MariaDB.
 *
 * Tres invariantes que no se negocian:
 *
 *  1. La sesión trabaja SIEMPRE en UTC. Todos los DATETIME se guardan en UTC; el día
 *     local del usuario se calcula aparte y vive en `occurred_date`. Si la conexión
 *     tuviera otra zona, NOW() escribiría en la zona equivocada y no se notaría hasta
 *     tener meses de datos corruptos. Ver docs/design/01-modelo-nucleo.md §3.
 *
 *  2. Modo estricto. Un truncamiento silencioso en una plataforma de datos longitudinales
 *     es peor que un error: el error se ve, el dato truncado no.
 *
 *  3. Consultas preparadas nativas (EMULATE_PREPARES = false). Además de la seguridad,
 *     devuelve enteros como enteros en vez de cadenas.
 *
 * `time_zone` y `sql_mode` van en MYSQL_ATTR_INIT_COMMAND y no en un query posterior,
 * para que se reapliquen también si el driver reconecta.
 */
final class Database
{
    private static ?PDO $connection = null;

    /** Conexión compartida, creada la primera vez que se pide. */
    public static function connection(): PDO
    {
        return self::$connection ??= self::connect();
    }

    /**
     * @param  array<string,mixed>|null  $config  Por defecto, config('database').
     */
    public static function connect(?array $config = null, bool $withDatabase = true): PDO
    {
        /** @var array<string,mixed> $config */
        $config ??= (array) Config::get('database', []);

        if ($config === []) {
            throw new RuntimeException('No hay configuración de base de datos.');
        }

        $charset = (string) ($config['charset'] ?? 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%d', (string) $config['host'], (int) $config['port']);

        if ($withDatabase) {
            $dsn .= ';dbname=' . (string) $config['database'];
        }

        $dsn .= ';charset=' . $charset;

        /** @var array<string,mixed> $options */
        $options = (array) ($config['options'] ?? []);

        // La collation se fija a mano a propósito. Desde MariaDB 11.4 la collation
        // por defecto de utf8mb4 es `utf8mb4_uca1400_ai_ci`, que NO existe en MySQL.
        // El esquema es portable entre ambos motores (ver 02-esquema-mysql.md §0) y
        // dejar que el servidor elija la rompería en silencio: mismas consultas,
        // distinto orden y distinta comparación de cadenas según dónde corran.
        $initCommand = sprintf(
            "SET time_zone = '%s', sql_mode = '%s', collation_connection = '%s'",
            (string) ($options['time_zone'] ?? '+00:00'),
            (string) ($options['sql_mode'] ?? 'STRICT_ALL_TABLES'),
            (string) ($config['collation'] ?? 'utf8mb4_unicode_ci'),
        );

        try {
            return new PDO(
                $dsn,
                (string) $config['username'],
                (string) $config['password'],
                [
                    PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES         => false,
                    PDO::ATTR_STRINGIFY_FETCHES        => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND       => $initCommand,
                ],
            );
        } catch (PDOException $e) {
            // Sin credenciales en el mensaje: acaba en logs.
            throw new RuntimeException(
                sprintf(
                    'No se pudo conectar a la base de datos %s@%s:%d — %s',
                    $withDatabase ? (string) $config['database'] : '(sin base)',
                    (string) $config['host'],
                    (int) $config['port'],
                    $e->getMessage(),
                ),
                (int) $e->getCode(),
                $e,
            );
        }
    }

    /** Conexión sin seleccionar base de datos, para poder crearla. */
    public static function connectWithoutDatabase(?array $config = null): PDO
    {
        return self::connect($config, withDatabase: false);
    }

    public static function disconnect(): void
    {
        self::$connection = null;
    }

    /** Versión del servidor, p. ej. "11.4.10-MariaDB". */
    public static function serverVersion(?PDO $pdo = null): string
    {
        $pdo ??= self::connection();

        return (string) $pdo->query('SELECT VERSION()')?->fetchColumn();
    }

    /** Solo la parte numérica: "11.4.10". */
    public static function serverVersionNumber(?PDO $pdo = null): string
    {
        preg_match('/^\d+\.\d+\.\d+/', self::serverVersion($pdo), $m);

        return $m[0] ?? '0.0.0';
    }

    public static function isMariaDb(?PDO $pdo = null): bool
    {
        return str_contains(strtolower(self::serverVersion($pdo)), 'mariadb');
    }

    /**
     * Ajustes efectivos de la sesión. Lo usa bin/check para demostrar que los
     * invariantes se cumplen de verdad, en vez de suponerlo.
     *
     * @return array<string,string>
     */
    public static function sessionSettings(?PDO $pdo = null): array
    {
        $pdo ??= self::connection();

        $row = $pdo->query(
            'SELECT @@session.time_zone   AS time_zone,
                    @@session.sql_mode    AS sql_mode,
                    @@character_set_client AS charset,
                    @@collation_connection AS collation'
        )?->fetch();

        /** @var array<string,string> $row */
        return $row ?: [];
    }
}
