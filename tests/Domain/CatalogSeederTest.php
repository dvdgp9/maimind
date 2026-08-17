<?php

declare(strict_types=1);

namespace MaiMind\Tests\Domain;

use MaiMind\Domain\CatalogSeeder;
use MaiMind\Support\Config;
use MaiMind\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class CatalogSeederTest extends TestCase
{
    private PDO $pdo;

    private CatalogSeeder $seeder;

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::connection();
        } catch (Throwable $e) {
            $this->markTestSkipped('Sin base de datos: ' . $e->getMessage());
        }

        $existe = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'variables'"
        )->fetchColumn();

        if ($existe === 0) {
            $this->markTestSkipped('Esquema no aplicado. Ejecuta: php bin/migrate');
        }

        $this->seeder = new CatalogSeeder($this->pdo);
    }

    /** @return list<array<string,mixed>> */
    private function catalogo(): array
    {
        return require Config::basePath('resources/seeds/variables_core.php');
    }

    /** @return list<array<string,string>> */
    private function dominios(): array
    {
        return require Config::basePath('resources/seeds/tags_core.php');
    }

    private function sembrar(bool $pretend = false): array
    {
        return $this->seeder->seed($this->catalogo(), $this->dominios(), $pretend);
    }

    public function test_es_idempotente(): void
    {
        $this->sembrar();

        $segunda = $this->sembrar();

        $this->assertSame(0, $segunda['variables']['creadas']);
        $this->assertSame(0, $segunda['variables']['actualizadas']);
        $this->assertSame(count($this->catalogo()), $segunda['variables']['sin_cambios']);
        $this->assertSame(0, $segunda['tags']['creados']);
        $this->assertSame(0, $segunda['tags']['actualizados']);
    }

    public function test_pretend_no_escribe(): void
    {
        $this->sembrar();

        $antes = $this->pdo->query('SELECT COUNT(*) FROM variables WHERE user_id = 0')->fetchColumn();

        $this->sembrar(pretend: true);

        $this->assertSame(
            $antes,
            $this->pdo->query('SELECT COUNT(*) FROM variables WHERE user_id = 0')->fetchColumn(),
        );
    }

    public function test_conserva_el_historial_de_uso_al_resembrar(): void
    {
        // occurrence_count y uid son historia, no definición: el seeder no los toca.
        $this->sembrar();

        $this->pdo->exec(
            "UPDATE variables SET occurrence_count = 77, first_seen_at = '2026-01-01 10:00:00'
              WHERE user_id = 0 AND slug = 'state.mood'"
        );

        $uidAntes = $this->pdo->query(
            "SELECT uid FROM variables WHERE user_id = 0 AND slug = 'state.mood'"
        )->fetchColumn();

        // Forzar una actualización cambiando la definición guardada.
        $this->pdo->exec(
            "UPDATE variables SET definition = 'texto viejo' WHERE user_id = 0 AND slug = 'state.mood'"
        );

        $resumen = $this->sembrar();
        $this->assertSame(1, $resumen['variables']['actualizadas']);

        $fila = $this->pdo->query(
            "SELECT uid, occurrence_count, first_seen_at, definition
               FROM variables WHERE user_id = 0 AND slug = 'state.mood'"
        )->fetch();

        $this->assertSame($uidAntes, $fila['uid']);
        $this->assertSame(77, $fila['occurrence_count']);
        $this->assertNotNull($fila['first_seen_at']);
        $this->assertNotSame('texto viejo', $fila['definition'], 'La definición sí se actualiza');
    }

    public function test_respeta_los_alias_que_no_son_del_seed(): void
    {
        $this->sembrar();

        $variableId = (int) $this->pdo->query(
            "SELECT id FROM variables WHERE user_id = 0 AND slug = 'state.mood'"
        )->fetchColumn();

        $this->pdo->prepare(
            "INSERT INTO variable_aliases (variable_id, user_id, lang, alias, source)
             VALUES (?, 0, 'es', 'palabra rarísima del usuario', 'user_defined')"
        )->execute([$variableId]);

        $this->sembrar();

        $sobrevive = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM variable_aliases
              WHERE alias = 'palabra rarísima del usuario' AND source = 'user_defined'"
        )->fetchColumn();

        $this->assertSame(1, $sobrevive);

        $this->pdo->exec("DELETE FROM variable_aliases WHERE source = 'user_defined'");
    }

    public function test_no_toca_las_variables_propias_de_un_usuario(): void
    {
        $this->pdo->exec(
            "INSERT INTO variables (uid, user_id, slug, name, category, value_type,
                                    objectivity, status, is_core)
             VALUES ('TESTTESTTESTTESTTESTTEST01', 999999, 'custom.mi_variable',
                     'Mi variable', 'custom', 'ordinal', 'subjective', 'candidate', 0)
             ON DUPLICATE KEY UPDATE name = 'Mi variable'"
        );

        $this->sembrar();

        $fila = $this->pdo->query(
            "SELECT status, is_core FROM variables WHERE user_id = 999999"
        )->fetch();

        $this->assertSame('candidate', $fila['status']);
        $this->assertSame(0, $fila['is_core']);

        $this->pdo->exec('DELETE FROM variables WHERE user_id = 999999');
    }

    public function test_revienta_si_hay_slugs_duplicados(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Slugs duplicados/');

        $variable = $this->catalogo()[0];

        $this->seeder->seed([$variable, $variable], [], pretend: true);
    }

    public function test_revienta_si_dos_variables_reclaman_el_mismo_alias(): void
    {
        // Sin esto, la clave única de la tabla se quedaría con la primera en
        // silencio y el extractor tendría un alias ambiguo para siempre.
        $catalogo = $this->catalogo();

        $catalogo[1]['aliases'][] = $catalogo[0]['aliases'][0];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/más de una variable/');

        $this->seeder->seed($catalogo, [], pretend: true);
    }

    public function test_todas_las_variables_quedan_como_core_y_activas(): void
    {
        $this->sembrar();

        $fila = $this->pdo->query(
            "SELECT COUNT(*) AS total,
                    SUM(is_core = 1 AND status = 'active') AS correctas
               FROM variables WHERE user_id = 0"
        )->fetch();

        $this->assertSame((int) $fila['total'], (int) $fila['correctas']);
        $this->assertSame(count($this->catalogo()), (int) $fila['total']);
    }

    public function test_los_alias_se_guardan_en_minusculas_y_en_espanol(): void
    {
        $this->sembrar();

        $raros = $this->pdo->query(
            "SELECT alias FROM variable_aliases
              WHERE source = 'seed' AND (lang <> 'es' OR BINARY alias <> BINARY LOWER(alias))"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame([], $raros);
    }

    public function test_los_dominios_vitales_estan_sembrados(): void
    {
        $this->sembrar();

        $slugs = $this->pdo->query(
            "SELECT slug FROM tags WHERE user_id = 0 AND kind = 'life_domain' ORDER BY slug"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach (['work', 'partner', 'family', 'health', 'self'] as $esperado) {
            $this->assertContains($esperado, $slugs);
        }
    }
}
