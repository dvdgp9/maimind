<?php

declare(strict_types=1);

namespace MaiMind\Domain;

use MaiMind\Support\Ulid;
use PDO;
use RuntimeException;

/**
 * Siembra el catálogo universal (user_id = 0) de variables y dominios vitales.
 *
 * Es un seeder y no una migración porque el catálogo core VA a cambiar: se
 * afinarán definiciones, se añadirán alias, alguna variable se promoverá desde
 * el vocabulario emergente. Congelarlo en una migración obligaría a escribir
 * un UPDATE a mano por cada matiz.
 *
 * Garantías:
 *
 *  - **Idempotente.** Ejecutarlo dos veces no cambia nada la segunda.
 *  - **No pisa datos de uso.** `occurrence_count`, `first_seen_at` y el `uid`
 *    se conservan al actualizar: son historia, no definición.
 *  - **No toca variables de usuario.** Solo opera sobre user_id = 0.
 *  - **Respeta los alias del usuario.** Al resembrar borra únicamente los
 *    alias con source='seed'; los que añadió el usuario o propuso la IA se quedan.
 */
final class CatalogSeeder
{
    private const UNIVERSAL = 0;

    /** Columnas que definen una variable y por tanto el seeder puede sobrescribir. */
    private const DEFINITION_COLUMNS = [
        'name', 'name_i18n', 'definition', 'definition_i18n', 'category',
        'value_type', 'scale_min', 'scale_max', 'unit', 'polarity',
        'temporal_kind', 'objectivity', 'auto_extractable', 'requires_confirm',
        'is_core', 'status', 'extraction_hint',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param  list<array<string,mixed>>  $variables
     * @param  list<array<string,string>>  $tags
     * @return array{variables:array{creadas:int,actualizadas:int,sin_cambios:int},
     *               alias:int, tags:array{creados:int,actualizados:int,sin_cambios:int}}
     */
    public function seed(array $variables, array $tags, bool $pretend = false): array
    {
        $this->assertSlugsUnicos($variables);
        $this->assertAliasesUnicos($variables);

        $resumen = [
            'variables' => ['creadas' => 0, 'actualizadas' => 0, 'sin_cambios' => 0],
            'alias'     => 0,
            'tags'      => ['creados' => 0, 'actualizados' => 0, 'sin_cambios' => 0],
        ];

        foreach ($variables as $definicion) {
            [$accion, $variableId] = $this->upsertVariable($definicion, $pretend);

            $resumen['variables'][$accion]++;

            if (! $pretend && $variableId !== null) {
                $resumen['alias'] += $this->syncAliases($variableId, $definicion['aliases'] ?? []);
            }
        }

        foreach ($tags as $tag) {
            $resumen['tags'][$this->upsertTag($tag, $pretend)]++;
        }

        return $resumen;
    }

    /**
     * @param  list<array<string,mixed>>  $variables
     */
    private function assertSlugsUnicos(array $variables): void
    {
        $slugs = array_column($variables, 'slug');
        $duplicados = array_keys(array_filter(array_count_values($slugs), fn ($n) => $n > 1));

        if ($duplicados !== []) {
            throw new RuntimeException('Slugs duplicados en el catálogo: ' . implode(', ', $duplicados));
        }
    }

    /**
     * Un alias tiene que apuntar a UNA sola variable. Si "rayado" valiera a la vez
     * para rumiación y para irritabilidad, el extractor no podría decidir y la
     * clave única de la tabla se quedaría con la primera en silencio. Mejor
     * reventar aquí que descubrirlo dentro de seis meses en los datos.
     *
     * @param  list<array<string,mixed>>  $variables
     */
    private function assertAliasesUnicos(array $variables): void
    {
        $duenos = [];

        foreach ($variables as $variable) {
            foreach ($variable['aliases'] ?? [] as $alias) {
                $duenos[mb_strtolower($alias)][] = (string) $variable['slug'];
            }
        }

        $colisiones = [];

        foreach ($duenos as $alias => $slugs) {
            $slugs = array_unique($slugs);

            if (count($slugs) > 1) {
                $colisiones[] = sprintf('"%s" → %s', $alias, implode(' y ', $slugs));
            }
        }

        if ($colisiones !== []) {
            throw new RuntimeException(
                "Alias reclamados por más de una variable:\n  " . implode("\n  ", $colisiones)
            );
        }
    }

    /**
     * @param  array<string,mixed>  $definicion
     * @return array{0:'creadas'|'actualizadas'|'sin_cambios', 1:int|null}
     */
    private function upsertVariable(array $definicion, bool $pretend): array
    {
        $valores = $this->normalizarVariable($definicion);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM variables WHERE user_id = ? AND slug = ?'
        );
        $stmt->execute([self::UNIVERSAL, $definicion['slug']]);
        $actual = $stmt->fetch();

        if ($actual === false) {
            if ($pretend) {
                return ['creadas', null];
            }

            $columnas = ['uid', 'user_id', 'slug', ...array_keys($valores)];
            $marcas   = implode(', ', array_fill(0, count($columnas), '?'));

            $insert = $this->pdo->prepare(
                'INSERT INTO variables (' . implode(', ', $columnas) . ") VALUES ({$marcas})"
            );

            $insert->execute([
                Ulid::generate(),
                self::UNIVERSAL,
                $definicion['slug'],
                ...array_values($valores),
            ]);

            return ['creadas', (int) $this->pdo->lastInsertId()];
        }

        $cambios = $this->diferencias($actual, $valores);

        if ($cambios === []) {
            return ['sin_cambios', (int) $actual['id']];
        }

        if ($pretend) {
            return ['actualizadas', (int) $actual['id']];
        }

        $asignaciones = implode(', ', array_map(static fn ($c) => "{$c} = ?", array_keys($cambios)));

        $update = $this->pdo->prepare("UPDATE variables SET {$asignaciones} WHERE id = ?");
        $update->execute([...array_values($cambios), (int) $actual['id']]);

        return ['actualizadas', (int) $actual['id']];
    }

    /**
     * @param  array<string,mixed>  $definicion
     * @return array<string,mixed>
     */
    private function normalizarVariable(array $definicion): array
    {
        $nombre = (string) $definicion['name'];

        $i18n = $definicion['name_i18n'] ?? ['es' => $nombre];

        $definicionTexto = (string) ($definicion['definition'] ?? '');

        return [
            'name'             => $nombre,
            'name_i18n'        => $this->json($i18n),
            'definition'       => $definicionTexto,
            'definition_i18n'  => $this->json(
                $definicion['definition_i18n'] ?? ['es' => $definicionTexto]
            ),
            'category'         => (string) $definicion['category'],
            'value_type'       => (string) $definicion['value_type'],
            'scale_min'        => $definicion['scale_min'] ?? null,
            'scale_max'        => $definicion['scale_max'] ?? null,
            'unit'             => $definicion['unit'] ?? null,
            'polarity'         => (string) ($definicion['polarity'] ?? 'neutral'),
            'temporal_kind'    => (string) ($definicion['temporal_kind'] ?? 'instant'),
            'objectivity'      => (string) $definicion['objectivity'],
            'auto_extractable' => (int) ($definicion['auto_extractable'] ?? 1),
            'requires_confirm' => (int) ($definicion['requires_confirm'] ?? 0),
            'is_core'          => 1,
            'status'           => 'active',
            'extraction_hint'  => (string) ($definicion['extraction_hint'] ?? ''),
        ];
    }

    /**
     * Compara solo las columnas de definición. `occurrence_count`, `first_seen_at`
     * y `uid` no se tocan nunca: son historia de uso, no definición.
     *
     * @param  array<string,mixed>  $actual
     * @param  array<string,mixed>  $deseado
     * @return array<string,mixed>
     */
    private function diferencias(array $actual, array $deseado): array
    {
        $cambios = [];

        foreach (self::DEFINITION_COLUMNS as $columna) {
            $nuevo  = $deseado[$columna] ?? null;
            $previo = $actual[$columna] ?? null;

            if ($this->normalizarParaComparar($previo) !== $this->normalizarParaComparar($nuevo)) {
                $cambios[$columna] = $nuevo;
            }
        }

        return $cambios;
    }

    private function normalizarParaComparar(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        // DECIMAL vuelve como "1.000" y en el seed es 1: comparar como número.
        if (is_numeric($valor)) {
            return rtrim(rtrim(number_format((float) $valor, 4, '.', ''), '0'), '.');
        }

        return (string) $valor;
    }

    /**
     * Reemplaza los alias de origen `seed`. Los del usuario y los propuestos por
     * la IA se respetan: son suyos, no del catálogo.
     *
     * @param  list<string>  $aliases
     */
    private function syncAliases(int $variableId, array $aliases): int
    {
        $borrar = $this->pdo->prepare(
            "DELETE FROM variable_aliases WHERE variable_id = ? AND source = 'seed'"
        );
        $borrar->execute([$variableId]);

        if ($aliases === []) {
            return 0;
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO variable_aliases (variable_id, user_id, lang, alias, source)
             VALUES (?, ?, 'es', ?, 'seed')
             ON DUPLICATE KEY UPDATE variable_id = variable_id"
        );

        $insertados = 0;

        foreach (array_unique(array_map(mb_strtolower(...), $aliases)) as $alias) {
            $insert->execute([$variableId, self::UNIVERSAL, $alias]);
            $insertados += $insert->rowCount() > 0 ? 1 : 0;
        }

        return $insertados;
    }

    /**
     * @param  array<string,string>  $tag
     * @return 'creados'|'actualizados'|'sin_cambios'
     */
    private function upsertTag(array $tag, bool $pretend): string
    {
        $i18n = $this->json(['es' => $tag['name'], 'en' => $tag['en'] ?? $tag['name']]);

        $stmt = $this->pdo->prepare('SELECT * FROM tags WHERE user_id = ? AND slug = ?');
        $stmt->execute([self::UNIVERSAL, $tag['slug']]);
        $actual = $stmt->fetch();

        if ($actual === false) {
            if (! $pretend) {
                $this->pdo->prepare(
                    "INSERT INTO tags (user_id, slug, name, name_i18n, kind)
                     VALUES (?, ?, ?, ?, 'life_domain')"
                )->execute([self::UNIVERSAL, $tag['slug'], $tag['name'], $i18n]);
            }

            return 'creados';
        }

        if ($actual['name'] === $tag['name'] && (string) $actual['name_i18n'] === $i18n) {
            return 'sin_cambios';
        }

        if (! $pretend) {
            $this->pdo->prepare('UPDATE tags SET name = ?, name_i18n = ? WHERE id = ?')
                ->execute([$tag['name'], $i18n, (int) $actual['id']]);
        }

        return 'actualizados';
    }

    /** @param array<string,mixed> $data */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
