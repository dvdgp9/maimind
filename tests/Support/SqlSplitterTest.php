<?php

declare(strict_types=1);

namespace MaiMind\Tests\Support;

use MaiMind\Support\SqlSplitter;
use PHPUnit\Framework\TestCase;

final class SqlSplitterTest extends TestCase
{
    public function test_parte_por_punto_y_coma(): void
    {
        $this->assertSame(
            ['SELECT 1', 'SELECT 2'],
            SqlSplitter::split('SELECT 1; SELECT 2;'),
        );
    }

    public function test_ignora_el_punto_y_coma_final_y_las_lineas_vacias(): void
    {
        $this->assertSame(['SELECT 1'], SqlSplitter::split("SELECT 1;\n\n;  ;\n"));
    }

    public function test_no_corta_dentro_de_una_cadena(): void
    {
        $sql = "INSERT INTO t (c) VALUES ('hola; adiós')";

        $this->assertSame([$sql], SqlSplitter::split($sql));
    }

    public function test_respeta_comillas_escapadas_con_barra(): void
    {
        $sql = "INSERT INTO t (c) VALUES ('no es \\'facil\\'; de verdad')";

        $this->assertCount(1, SqlSplitter::split($sql));
    }

    public function test_respeta_comillas_duplicadas(): void
    {
        $sql = "INSERT INTO t (c) VALUES ('d''Artagnan; uno')";

        $this->assertCount(1, SqlSplitter::split($sql));
    }

    public function test_no_corta_dentro_de_un_identificador_con_comillas_invertidas(): void
    {
        $sql = 'SELECT `raro;nombre` FROM t';

        $this->assertSame([$sql], SqlSplitter::split($sql));
    }

    public function test_elimina_comentarios_de_linea(): void
    {
        $sql = "-- comentario con ; dentro\nSELECT 1;\n# otro ; comentario\nSELECT 2;";

        $this->assertSame(['SELECT 1', 'SELECT 2'], SqlSplitter::split($sql));
    }

    public function test_dos_guiones_sin_espacio_no_son_comentario(): void
    {
        // En MySQL `--` solo abre comentario si le sigue un espacio.
        $this->assertSame(['SELECT 1--2'], SqlSplitter::split('SELECT 1--2;'));
    }

    public function test_elimina_comentarios_de_bloque(): void
    {
        $sql = "/* esto; se ignora\n   y esto también; */ SELECT 1;";

        $this->assertSame(['SELECT 1'], SqlSplitter::split($sql));
    }

    public function test_parte_ddl_realista(): void
    {
        $sql = <<<'SQL'
        -- Tabla de usuarios
        CREATE TABLE users (
          id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          status ENUM('active','suspended') NOT NULL DEFAULT 'active',
          note   VARCHAR(80) NULL COMMENT 'ojo; con el punto y coma',
          PRIMARY KEY (id)
        ) ENGINE=InnoDB;

        CREATE INDEX idx_users_status ON users (status);
        SQL;

        $statements = SqlSplitter::split($sql);

        $this->assertCount(2, $statements);
        $this->assertStringStartsWith('CREATE TABLE users', $statements[0]);
        $this->assertStringContainsString('ojo; con el punto y coma', $statements[0]);
        $this->assertStringStartsWith('CREATE INDEX', $statements[1]);
    }

    public function test_cadena_vacia_no_da_sentencias(): void
    {
        $this->assertSame([], SqlSplitter::split(''));
        $this->assertSame([], SqlSplitter::split("-- solo un comentario\n"));
    }
}
