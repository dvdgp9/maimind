<?php

declare(strict_types=1);

namespace MaiMind\Tests\Schema;

use MaiMind\Support\Database;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Comprueba que el esquema aplicado cumple los invariantes del diseño.
 *
 * No verifica "que las tablas existan" por burocracia: verifica las reglas que,
 * si se rompen en una migración futura, corrompen datos en silencio.
 */
final class EsquemaInicialTest extends TestCase
{
    private PDO $pdo;

    private string $schema;

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::connection();
        } catch (Throwable $e) {
            $this->markTestSkipped('Sin base de datos: ' . $e->getMessage());
        }

        $this->schema = (string) config('database.database');

        $applied = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM schema_migrations WHERE version = '001_esquema_inicial'")
            ->fetchColumn();

        if ($applied === 0) {
            $this->markTestSkipped('El esquema inicial no está aplicado. Ejecuta: php bin/migrate');
        }
    }

    /** @return list<string> */
    private function column(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->schema, ...$params]);

        return array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_estan_todas_las_tablas_del_diseno(): void
    {
        $esperadas = [
            // identidad
            'users', 'sessions', 'consents', 'grants', 'audit_log',
            // nivel 1 — crudo
            'entries', 'transcripts', 'extraction_runs',
            // catálogos
            'variables', 'variable_aliases', 'entities', 'entity_aliases', 'tags',
            // nivel 2 — estructurado
            'observations', 'measurements', 'observation_entities',
            'observation_tags', 'links', 'revisions',
            // nivel 3 — derivados
            'day_coverage', 'daily_metrics', 'baselines', 'hypotheses', 'embeddings',
            // infraestructura
            'jobs', 'schema_migrations', 'login_attempts',
        ];

        $existentes = $this->column(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?'
        );

        sort($esperadas);
        sort($existentes);

        $this->assertSame($esperadas, $existentes);
    }

    public function test_todas_las_tablas_usan_la_collation_portable(): void
    {
        // Si una migración futura omite COLLATE, MariaDB 11.4+ pondrá
        // utf8mb4_uca1400_ai_ci, que no existe en MySQL. Este test lo caza.
        $this->assertSame([], $this->column(
            'SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = ? AND TABLE_COLLATION <> ?',
            ['utf8mb4_unicode_ci'],
        ));
    }

    public function test_todas_las_tablas_son_innodb(): void
    {
        // Sin InnoDB no hay claves foráneas, ni transacciones, ni SKIP LOCKED.
        $this->assertSame([], $this->column(
            'SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = ? AND ENGINE <> ?',
            ['InnoDB'],
        ));
    }

    public function test_toda_tabla_de_datos_lleva_user_id(): void
    {
        // El aislamiento multiusuario depende de esto. Las excepciones son
        // deliberadas y están enumeradas.
        $sinUserId = [
            'users',              // es la propia tabla de usuarios
            'sessions',           // lo lleva, pero se comprueba aparte por la PK textual
            'schema_migrations',  // infraestructura
            'tags',               // catálogo: user_id = 0 es universal
            'variable_aliases',   // ídem
            'entity_aliases',     // cuelga de entities
            'observation_entities', 'observation_tags', // tablas puente
            'variables',          // catálogo
            // Solo guarda hashes de identificadores: un intento contra un correo
            // que no existe no tiene usuario al que apuntar, y guardarlo sería
            // convertir la tabla en un registro de quién intentó entrar.
            'login_attempts',
        ];

        $tablas = $this->column(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?'
        );

        foreach ($tablas as $tabla) {
            if (in_array($tabla, $sinUserId, true)) {
                continue;
            }

            $columnas = $this->column(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$tabla],
            );

            $tieneUsuario = in_array('user_id', $columnas, true)
                || in_array('subject_user_id', $columnas, true);

            $this->assertTrue($tieneUsuario, "La tabla {$tabla} no tiene user_id");
        }
    }

    public function test_los_catalogos_universales_no_tienen_fk_a_users(): void
    {
        // Usan user_id = 0 para la fila universal, y el 0 no existe en users.
        foreach (['variables', 'tags', 'variable_aliases'] as $tabla) {
            $fks = $this->column(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                    AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users'",
                [$tabla],
            );

            $this->assertSame([], $fks, "{$tabla} no debe tener FK de user_id a users");
        }
    }

    public function test_los_datetime_llevan_milisegundos(): void
    {
        // El esquema usa DATETIME(3) en todas partes. Un DATETIME sin precisión
        // perdería el orden de dos registros del mismo segundo.
        $sinPrecision = $this->column(
            "SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND DATA_TYPE = 'datetime' AND DATETIME_PRECISION = 0"
        );

        $this->assertSame([], $sinPrecision);
    }

    public function test_el_mood_hint_solo_admite_de_1_a_5(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo->exec("INSERT INTO entries
            (uid, user_id, captured_at, received_at, local_date, client_timezone,
             client_utc_offset, mood_hint)
            VALUES ('X', 1, NOW(3), NOW(3), CURDATE(), 'Europe/Madrid', 120, 9)");
    }

    public function test_una_medicion_sin_ningun_valor_se_rechaza(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo->exec("INSERT INTO measurements
            (user_id, variable_id, entry_id, source)
            VALUES (1, 1, 1, 'ai_inferred')");
    }

    public function test_un_intervalo_al_reves_se_rechaza(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo->exec("INSERT INTO observations
            (uid, user_id, entry_id, kind, label, source,
             occurred_start, occurred_end)
            VALUES ('X', 1, 1, 'event', 'prueba', 'user_explicit',
                    '2026-08-16 20:00:00', '2026-08-16 10:00:00')");
    }

    public function test_la_confianza_esta_entre_0_y_1(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo->exec("INSERT INTO observations
            (uid, user_id, entry_id, kind, label, source, confidence)
            VALUES ('X', 1, 1, 'event', 'prueba', 'ai_inferred', 1.5)");
    }

    public function test_las_relaciones_no_incluyen_causalidad_a_secas(): void
    {
        // El sistema no puede afirmar causalidad. No tener el verbo disponible
        // funciona mejor que acordarse de no usarlo.
        $tipo = $this->pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'links'
                AND COLUMN_NAME = 'relation'"
        )->fetchColumn();

        $this->assertStringNotContainsString("'causes'", (string) $tipo);
        $this->assertStringNotContainsString("'caused_by'", (string) $tipo);
        $this->assertStringContainsString("'user_claims_caused'", (string) $tipo);
    }

    public function test_links_tiene_los_campos_que_salvan_la_analitica(): void
    {
        $columnas = $this->column(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'links'"
        );

        // Sin estos dos, el motor de hallazgos redescubre las opiniones del
        // usuario y se las devuelve como si fueran datos.
        $this->assertContains('user_declared', $columnas);
        $this->assertContains('same_entry', $columnas);
    }

    public function test_existen_las_dos_lentes(): void
    {
        foreach (['observations', 'measurements'] as $tabla) {
            $tipo = (string) $this->pdo->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tabla}'
                    AND COLUMN_NAME = 'lens'"
            )->fetchColumn();

            $this->assertStringContainsString('as_experienced', $tipo);
            $this->assertStringContainsString('as_understood', $tipo);
        }
    }

    public function test_la_tabla_de_mediciones_tiene_los_indices_de_la_analitica(): void
    {
        $indices = $this->column(
            "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'measurements'"
        );

        foreach (['idx_m_series', 'idx_m_day', 'idx_m_intraday'] as $indice) {
            $this->assertContains($indice, $indices);
        }
    }

    public function test_los_indices_de_usuario_empiezan_por_user_id(): void
    {
        // Aislamiento y localidad de datos: las consultas de un usuario deben
        // tocar páginas contiguas.
        $stmt = $this->pdo->prepare(
            "SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'measurements' AND SEQ_IN_INDEX = 1
                AND INDEX_NAME LIKE 'idx_m_%'"
        );
        $stmt->execute([$this->schema]);

        foreach ($stmt->fetchAll() as $fila) {
            if (in_array($fila['INDEX_NAME'], ['idx_m_entry', 'idx_m_obs'], true)) {
                continue; // búsquedas por procedencia, no por usuario
            }

            $this->assertSame('user_id', $fila['COLUMN_NAME'], (string) $fila['INDEX_NAME']);
        }
    }
}
