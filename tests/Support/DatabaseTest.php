<?php

declare(strict_types=1);

namespace MaiMind\Tests\Support;

use MaiMind\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Test de integración: necesita una base de datos viva.
 * Se salta con aviso si no la hay, para no romper la suite en un entorno sin BD.
 */
final class DatabaseTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::connection();
        } catch (Throwable $e) {
            $this->markTestSkipped('Sin base de datos: ' . $e->getMessage());
        }
    }

    public function test_la_sesion_trabaja_en_utc(): void
    {
        // El invariante más caro del proyecto. Si la sesión no está en UTC, todos
        // los sellos temporales escritos por el servidor van a la zona equivocada
        // y no se nota hasta tener meses de datos.
        $this->assertSame('+00:00', Database::sessionSettings($this->pdo)['time_zone'] ?? null);
    }

    public function test_el_reloj_del_servidor_coincide_con_utc(): void
    {
        $dbNow = (int) $this->pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();

        $this->assertLessThanOrEqual(2, abs($dbNow - time()));
    }

    public function test_modo_estricto_activo(): void
    {
        $this->assertStringContainsString(
            'STRICT_ALL_TABLES',
            Database::sessionSettings($this->pdo)['sql_mode'] ?? '',
        );
    }

    public function test_el_modo_estricto_rechaza_datos_que_no_caben(): void
    {
        $this->pdo->exec('CREATE TEMPORARY TABLE t_estricto (c VARCHAR(3))');

        // Sin modo estricto esto guardaría "abc" en silencio. Un truncamiento
        // silencioso en datos longitudinales es peor que un error.
        $this->expectExceptionMessageMatches('/Data too long|too long for column/i');

        $stmt = $this->pdo->prepare('INSERT INTO t_estricto (c) VALUES (?)');
        $stmt->execute(['abcdef']);
    }

    public function test_utf8mb4_conserva_acentos_y_emoji(): void
    {
        $this->pdo->exec(
            'CREATE TEMPORARY TABLE t_utf8 (c VARCHAR(64))
             CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        $texto = 'discusión con Martí — 😔 ñ';

        $stmt = $this->pdo->prepare('INSERT INTO t_utf8 (c) VALUES (?)');
        $stmt->execute([$texto]);

        $this->assertSame(
            $texto,
            $this->pdo->query('SELECT c FROM t_utf8')->fetchColumn(),
        );
    }

    public function test_usa_consultas_preparadas_nativas(): void
    {
        // El atributo cambia de tipo entre versiones de PHP: 8.3 devuelve
        // int(0) y 8.4 bool(false). Comparar en estricto pasa en local y falla
        // en producción, así que se comprueba el COMPORTAMIENTO.
        $this->assertFalse((bool) $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));

        // Con emulación activa esto se aceptaría; con preparación nativa, no.
        $this->expectException(\PDOException::class);
        $this->pdo->prepare('SELECT 1; SELECT 2')->execute();
    }

    public function test_los_enteros_vuelven_como_enteros(): void
    {
        // Ojo: `SELECT ?` con execute([42]) NO sirve para comprobar esto. PDO envía
        // el parámetro como cadena y el servidor devuelve una cadena, correctamente.
        // Hace falta una columna realmente entera.
        $this->pdo->exec('CREATE TEMPORARY TABLE t_int (n INT, big BIGINT UNSIGNED)');
        $this->pdo->exec('INSERT INTO t_int (n, big) VALUES (42, 9007199254740993)');

        $row = $this->pdo->query('SELECT n, big FROM t_int')->fetch();

        $this->assertSame(42, $row['n']);
        $this->assertIsInt($row['big']);
    }

    public function test_la_collation_es_la_portable_no_la_de_mariadb(): void
    {
        // MariaDB 11.4+ usa utf8mb4_uca1400_ai_ci por defecto, que no existe en
        // MySQL. La fijamos a mano para que el orden y las comparaciones de cadenas
        // sean idénticos en cualquier motor.
        $this->assertSame(
            'utf8mb4_unicode_ci',
            Database::sessionSettings($this->pdo)['collation'] ?? null,
        );
    }

    public function test_los_datetime_con_milisegundos_sobreviven(): void
    {
        // El esquema usa DATETIME(3) en todas partes. Comprobamos que la precisión
        // no se pierde por el camino.
        $this->pdo->exec('CREATE TEMPORARY TABLE t_ms (d DATETIME(3))');

        $stmt = $this->pdo->prepare('INSERT INTO t_ms (d) VALUES (?)');
        $stmt->execute(['2026-08-16 21:04:33.123']);

        $this->assertSame(
            '2026-08-16 21:04:33.123',
            $this->pdo->query('SELECT d FROM t_ms')->fetchColumn(),
        );
    }

    public function test_now_del_servidor_esta_en_utc(): void
    {
        $dbNow = (string) $this->pdo->query('SELECT NOW(3)')->fetchColumn();

        $this->assertSame(gmdate('Y-m-d H'), substr($dbNow, 0, 13));
    }

    public function test_el_servidor_es_mariadb(): void
    {
        $this->assertTrue(Database::isMariaDb($this->pdo));
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Database::serverVersionNumber($this->pdo));
    }
}
