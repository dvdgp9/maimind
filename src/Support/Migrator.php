<?php

declare(strict_types=1);

namespace MaiMind\Support;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Aplica los ficheros SQL de migrations/ en orden y lleva la cuenta en
 * `schema_migrations`.
 *
 * Decisiones que conviene conocer:
 *
 *  - **No hay rollback.** En MySQL y MariaDB el DDL provoca commit implícito, así que
 *    envolver una migración en una transacción da una falsa sensación de seguridad.
 *    En su lugar: se aplica sentencia a sentencia, y si una falla se corta ahí y se
 *    dice exactamente cuál. La reparación es escribir otra migración, no deshacer.
 *
 *  - **Checksum de cada fichero.** Editar una migración ya aplicada es un clásico:
 *    funciona en tu máquina y falla en producción, donde nunca se reaplicó. Si el
 *    contenido cambia después de aplicarse, el migrador lo detecta y avisa.
 *
 *  - **Bloqueo con GET_LOCK.** Dos despliegues simultáneos no pueden migrar a la vez.
 */
final class Migrator
{
    private const LOCK_NAME = 'maimind_migrations';

    private const LOCK_TIMEOUT = 10;

    /**
     * @param  string  $table  Nombre de la tabla de registro. Solo se cambia en los
     *                         tests, para que no puedan tocar el historial real.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $path,
        private readonly string $table = 'schema_migrations',
    ) {
        if (preg_match('/^[a-z0-9_]+$/', $this->table) !== 1) {
            throw new RuntimeException('Nombre de tabla de migraciones no válido: ' . $this->table);
        }
    }

    public function table(): string
    {
        return $this->table;
    }

    /**
     * Crea la tabla de control. Tiene que existir antes que cualquier migración,
     * porque la propia 001 necesita poder registrarse.
     */
    public function ensureRegistry(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                version      VARCHAR(120) NOT NULL,
                checksum     CHAR(64)     NOT NULL,
                statements   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
                applied_at   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * Migraciones encontradas en disco, ordenadas por nombre de fichero.
     *
     * @return array<string,array{version:string,file:string,checksum:string}>
     */
    public function available(): array
    {
        if (! is_dir($this->path)) {
            throw new RuntimeException('No existe el directorio de migraciones: ' . $this->path);
        }

        $files = glob(rtrim($this->path, '/') . '/*.sql') ?: [];

        sort($files, SORT_NATURAL);

        $found = [];

        foreach ($files as $file) {
            $version = basename($file, '.sql');

            $found[$version] = [
                'version'  => $version,
                'file'     => $file,
                'checksum' => hash('sha256', (string) file_get_contents($file)),
            ];
        }

        return $found;
    }

    /**
     * @return array<string,array{checksum:string,applied_at:string}>
     */
    public function applied(): array
    {
        $rows = $this->pdo
            ->query("SELECT version, checksum, applied_at FROM {$this->table} ORDER BY version")
            ?->fetchAll() ?: [];

        $applied = [];

        foreach ($rows as $row) {
            $applied[(string) $row['version']] = [
                'checksum'   => (string) $row['checksum'],
                'applied_at' => (string) $row['applied_at'],
            ];
        }

        return $applied;
    }

    /** @return array<string,array{version:string,file:string,checksum:string}> */
    public function pending(): array
    {
        return array_diff_key($this->available(), $this->applied());
    }

    /**
     * Migraciones ya aplicadas cuyo fichero ha cambiado desde entonces.
     *
     * @return list<string>
     */
    public function drift(): array
    {
        $available = $this->available();
        $drifted   = [];

        foreach ($this->applied() as $version => $record) {
            if (! isset($available[$version])) {
                continue; // el fichero ya no está: se informa aparte
            }

            if ($available[$version]['checksum'] !== $record['checksum']) {
                $drifted[] = $version;
            }
        }

        return $drifted;
    }

    /** Migraciones registradas cuyo fichero ha desaparecido. @return list<string> */
    public function missing(): array
    {
        return array_values(array_diff(
            array_keys($this->applied()),
            array_keys($this->available()),
        ));
    }

    /**
     * Aplica las migraciones pendientes.
     *
     * @return list<array{version:string,statements:int,ms:int}>
     */
    public function migrate(bool $pretend = false): array
    {
        $pending = $this->pending();

        if ($pending === []) {
            return [];
        }

        if (! $pretend && ! $this->acquireLock()) {
            throw new RuntimeException(
                'Otro proceso está migrando ahora mismo. Inténtalo en unos segundos.'
            );
        }

        $results = [];

        try {
            foreach ($pending as $migration) {
                $sql = (string) file_get_contents($migration['file']);

                $statements = SqlSplitter::split($sql);

                if ($pretend) {
                    $results[] = [
                        'version'    => $migration['version'],
                        'statements' => count($statements),
                        'ms'         => 0,
                    ];

                    continue;
                }

                $started = microtime(true);

                foreach ($statements as $index => $statement) {
                    try {
                        $this->pdo->exec($statement);
                    } catch (Throwable $e) {
                        throw new RuntimeException(
                            sprintf(
                                "Falló la migración %s en la sentencia %d de %d:\n\n%s\n\n%s",
                                $migration['version'],
                                $index + 1,
                                count($statements),
                                $this->preview($statement),
                                $e->getMessage(),
                            ),
                            0,
                            $e,
                        );
                    }
                }

                $ms = (int) round((microtime(true) - $started) * 1000);

                $insert = $this->pdo->prepare(
                    "INSERT INTO {$this->table} (version, checksum, statements, execution_ms)
                     VALUES (?, ?, ?, ?)"
                );

                $insert->execute([
                    $migration['version'],
                    $migration['checksum'],
                    count($statements),
                    $ms,
                ]);

                $results[] = [
                    'version'    => $migration['version'],
                    'statements' => count($statements),
                    'ms'         => $ms,
                ];
            }
        } finally {
            if (! $pretend) {
                $this->releaseLock();
            }
        }

        return $results;
    }

    private function preview(string $statement, int $lines = 6): string
    {
        $split = explode("\n", $statement);

        $head = array_slice($split, 0, $lines);

        if (count($split) > $lines) {
            $head[] = sprintf('  … (%d líneas más)', count($split) - $lines);
        }

        return implode("\n", $head);
    }

    private function acquireLock(): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([self::LOCK_NAME, self::LOCK_TIMEOUT]);

        return (int) $stmt->fetchColumn() === 1;
    }

    private function releaseLock(): void
    {
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([self::LOCK_NAME]);
    }
}
