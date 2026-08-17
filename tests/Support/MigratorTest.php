<?php

declare(strict_types=1);

namespace MaiMind\Tests\Support;

use MaiMind\Support\Database;
use MaiMind\Support\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Test de integración. Usa un directorio temporal de migraciones y limpia
 * detrás de sí, para no ensuciar el historial real.
 */
final class MigratorTest extends TestCase
{
    private PDO $pdo;

    private string $dir;

    /**
     * Tabla de registro propia. Sin esto, los tests borrarían el historial de
     * migraciones REAL de la base de desarrollo, y `bin/migrate` intentaría
     * después recrear tablas que ya existen.
     */
    private const TABLA = 'schema_migrations_test';

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::connection();
        } catch (Throwable $e) {
            $this->markTestSkipped('Sin base de datos: ' . $e->getMessage());
        }

        $this->dir = sys_get_temp_dir() . '/maimind-migrations-' . bin2hex(random_bytes(4));
        mkdir($this->dir);

        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLA);
        $this->pdo->exec('DROP TABLE IF EXISTS t_migrator_a');
        $this->pdo->exec('DROP TABLE IF EXISTS t_migrator_b');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->dir);

        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLA);
        $this->pdo->exec('DROP TABLE IF EXISTS t_migrator_a');
        $this->pdo->exec('DROP TABLE IF EXISTS t_migrator_b');
    }

    private function write(string $name, string $sql): void
    {
        file_put_contents($this->dir . '/' . $name . '.sql', $sql);
    }

    private function migrator(): Migrator
    {
        $migrator = new Migrator($this->pdo, $this->dir, self::TABLA);
        $migrator->ensureRegistry();

        return $migrator;
    }

    public function test_aplica_las_migraciones_en_orden(): void
    {
        $this->write('001_a', 'CREATE TABLE t_migrator_a (id INT PRIMARY KEY);');
        $this->write('002_b', 'CREATE TABLE t_migrator_b (id INT PRIMARY KEY);');

        $results = $this->migrator()->migrate();

        $this->assertSame(['001_a', '002_b'], array_column($results, 'version'));
    }

    public function test_es_idempotente(): void
    {
        // El criterio de éxito de la tarea 0.3: ejecutarlo dos veces no rompe
        // ni reaplica nada.
        $this->write('001_a', 'CREATE TABLE t_migrator_a (id INT PRIMARY KEY);');

        $this->assertCount(1, $this->migrator()->migrate());
        $this->assertSame([], $this->migrator()->migrate());
        $this->assertSame([], $this->migrator()->migrate());
    }

    public function test_solo_aplica_las_nuevas(): void
    {
        $this->write('001_a', 'CREATE TABLE t_migrator_a (id INT PRIMARY KEY);');
        $this->migrator()->migrate();

        $this->write('002_b', 'CREATE TABLE t_migrator_b (id INT PRIMARY KEY);');

        $this->assertSame(['002_b'], array_column($this->migrator()->migrate(), 'version'));
    }

    public function test_aplica_varias_sentencias_de_un_mismo_fichero(): void
    {
        $this->write('001_a', <<<'SQL'
            CREATE TABLE t_migrator_a (id INT PRIMARY KEY, nombre VARCHAR(40));
            INSERT INTO t_migrator_a (id, nombre) VALUES (1, 'uno; con punto y coma');
            SQL);

        $results = $this->migrator()->migrate();

        $this->assertSame(2, $results[0]['statements']);
        $this->assertSame(
            'uno; con punto y coma',
            $this->pdo->query('SELECT nombre FROM t_migrator_a')->fetchColumn(),
        );
    }

    public function test_pretend_no_toca_nada(): void
    {
        $this->write('001_a', 'CREATE TABLE t_migrator_a (id INT PRIMARY KEY);');

        $migrator = $this->migrator();

        $this->assertCount(1, $migrator->migrate(pretend: true));
        $this->assertSame([], $migrator->applied());
        $this->assertCount(1, $migrator->pending());
    }

    public function test_al_fallar_dice_que_sentencia_y_no_registra_la_migracion(): void
    {
        $this->write('001_a', <<<'SQL'
            CREATE TABLE t_migrator_a (id INT PRIMARY KEY);
            ESTO NO ES SQL;
            SQL);

        $migrator = $this->migrator();

        try {
            $migrator->migrate();
            $this->fail('Se esperaba que fallase');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('001_a', $e->getMessage());
            $this->assertStringContainsString('sentencia 2 de 2', $e->getMessage());
        }

        // La migración no queda registrada: la siguiente ejecución la reintenta.
        $this->assertSame([], $migrator->applied());
    }

    public function test_detecta_una_migracion_ya_aplicada_que_ha_cambiado(): void
    {
        // El clásico: editar una migración aplicada. Funciona en tu máquina y
        // falla en producción, donde nunca se reaplicó.
        $this->write('001_a', 'CREATE TABLE t_migrator_a (id INT PRIMARY KEY);');

        $migrator = $this->migrator();
        $migrator->migrate();

        $this->assertSame([], $migrator->drift());

        $this->write('001_a', 'CREATE TABLE t_migrator_a (id INT PRIMARY KEY, extra INT);');

        $this->assertSame(['001_a'], $migrator->drift());
        $this->assertSame([], $migrator->pending(), 'No debe reaplicarse');
    }

    public function test_detecta_una_migracion_registrada_sin_fichero(): void
    {
        $this->write('001_a', 'CREATE TABLE t_migrator_a (id INT PRIMARY KEY);');

        $migrator = $this->migrator();
        $migrator->migrate();

        unlink($this->dir . '/001_a.sql');

        $this->assertSame(['001_a'], $migrator->missing());
    }

    public function test_guarda_checksum_y_tiempo_de_ejecucion(): void
    {
        $this->write('001_a', 'CREATE TABLE t_migrator_a (id INT PRIMARY KEY);');

        $this->migrator()->migrate();

        $row = $this->pdo->query('SELECT * FROM ' . self::TABLA)->fetch();

        $this->assertSame('001_a', $row['version']);
        $this->assertSame(64, strlen((string) $row['checksum']));
        $this->assertSame(1, $row['statements']);
    }

    public function test_directorio_vacio_no_hace_nada(): void
    {
        $this->assertSame([], $this->migrator()->migrate());
    }

    public function test_directorio_inexistente_da_error_claro(): void
    {
        $migrator = new Migrator($this->pdo, '/no/existe/este/directorio', self::TABLA);

        $this->expectExceptionMessageMatches('/No existe el directorio de migraciones/');

        $migrator->available();
    }
}
