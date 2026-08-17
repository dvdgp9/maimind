<?php

declare(strict_types=1);

namespace MaiMind\Repository;

use InvalidArgumentException;
use PDO;

/**
 * Base de todo acceso a datos de usuario.
 *
 * La regla de aislamiento del proyecto es que ninguna consulta puede escaparse
 * sin filtrar por usuario. Aquí no se cumple por convención ni por revisión de
 * código: **el objeto no existe sin un user_id**, y todos los métodos de
 * consulta inyectan el filtro ellos mismos. Para saltárselo habría que escribir
 * SQL a mano fuera de un repositorio, que es una decisión visible en una
 * revisión, no un olvido.
 */
abstract class UserScopedRepository
{
    public function __construct(
        protected readonly PDO $pdo,
        protected readonly int $userId,
    ) {
        if ($userId <= 0) {
            throw new InvalidArgumentException(
                'Un repositorio de datos de usuario necesita un user_id válido. '
                . 'Recibido: ' . $userId
            );
        }
    }

    abstract protected function table(): string;

    /** ¿La tabla usa borrado lógico? */
    protected function usesSoftDeletes(): bool
    {
        return true;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    /**
     * @param  array<string,mixed>  $conditions  columna => valor, unidas con AND
     * @return list<array<string,mixed>>
     */
    protected function findWhere(
        array $conditions = [],
        string $columns = '*',
        ?string $orderBy = null,
        ?int $limit = null,
    ): array {
        [$where, $params] = $this->buildWhere($conditions);

        $sql = sprintf('SELECT %s FROM %s WHERE %s', $columns, $this->table(), $where);

        if ($orderBy !== null) {
            $sql .= ' ORDER BY ' . $orderBy;
        }

        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @param  array<string,mixed>  $conditions
     * @return array<string,mixed>|null
     */
    protected function findOneWhere(array $conditions, string $columns = '*'): ?array
    {
        return $this->findWhere($conditions, $columns, limit: 1)[0] ?? null;
    }

    /** @param array<string,mixed> $conditions */
    protected function countWhere(array $conditions = []): int
    {
        return (int) ($this->findOneWhere($conditions, 'COUNT(*) AS n')['n'] ?? 0);
    }

    /** @param array<string,mixed> $data */
    protected function insert(array $data): int
    {
        // El user_id lo pone el repositorio, no quien lo llama: así no puede
        // escribirse una fila en el espacio de otra persona.
        $data['user_id'] = $this->userId;

        $columns = array_keys($data);
        $marks   = implode(', ', array_fill(0, count($columns), '?'));

        $stmt = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table(),
            implode(', ', $columns),
            $marks,
        ));

        $stmt->execute(array_values($data));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $conditions
     */
    protected function update(array $data, array $conditions): int
    {
        unset($data['user_id'], $data['id']);

        if ($data === []) {
            return 0;
        }

        [$where, $params] = $this->buildWhere($conditions);

        $assignments = implode(', ', array_map(static fn ($c) => "{$c} = ?", array_keys($data)));

        $stmt = $this->pdo->prepare(sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->table(),
            $assignments,
            $where,
        ));

        $stmt->execute([...array_values($data), ...$params]);

        return $stmt->rowCount();
    }

    /**
     * @param  array<string,mixed>  $conditions
     * @return array{0:string,1:list<mixed>}
     */
    private function buildWhere(array $conditions): array
    {
        $clauses = ['user_id = ?'];
        $params  = [$this->userId];

        if ($this->usesSoftDeletes()) {
            $clauses[] = 'deleted_at IS NULL';
        }

        foreach ($conditions as $column => $value) {
            if (! is_string($column) || preg_match('/^[a-z_][a-z0-9_]*$/i', $column) !== 1) {
                throw new InvalidArgumentException("Nombre de columna no válido: {$column}");
            }

            if ($value === null) {
                $clauses[] = "{$column} IS NULL";

                continue;
            }

            $clauses[] = "{$column} = ?";
            $params[]  = $value;
        }

        return [implode(' AND ', $clauses), $params];
    }
}
