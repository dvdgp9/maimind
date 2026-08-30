<?php

declare(strict_types=1);

namespace MaiMind\Domain\Jobs;

use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * Cola de trabajos sobre la tabla `jobs`.
 *
 * Deliberadamente en la base de datos y no en Redis ni en un servicio aparte:
 * el servidor comparte 2 vCPU con el correo y con otra aplicación en
 * producción, y la tabla ya está dentro del respaldo y de la transacción. La
 * cola no va a ver miles de trabajos por segundo; va a ver unas decenas al día.
 *
 * Esta clase **no** es un repositorio de usuario: la cola es infraestructura y
 * atiende a todos. Por eso no hereda de UserScopedRepository. El aislamiento
 * se mantiene donde importa: el trabajo lleva su `user_id` y quien lo ejecuta
 * construye un repositorio con ese usuario, así que ningún manejador ve datos
 * de dos personas a la vez.
 */
final class JobQueue
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const DONE    = 'done';
    public const FAILED  = 'failed';
    public const DEAD    = 'dead';

    /** SQLSTATE de violación de restricción de integridad (clave duplicada). */
    private const DUPLICATE = '23000';

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $backoffBaseSeconds = 10,
        private readonly int $backoffMaxSeconds = 900,
    ) {
    }

    /**
     * Encola un trabajo. Devuelve su id, o null si ya había uno vivo con la
     * misma clave de deduplicación.
     *
     * @param  array<string,mixed>  $payload
     */
    public function push(
        string $type,
        array $payload = [],
        ?int $userId = null,
        ?string $dedupeKey = null,
        int $priority = 5,
        int $maxAttempts = 5,
        int $delaySeconds = 0,
    ): ?int {
        if (trim($type) === '') {
            throw new InvalidArgumentException('Un trabajo necesita un tipo.');
        }

        $sql = 'INSERT INTO jobs (user_id, type, dedupe_key, payload, priority, max_attempts, run_after)
                VALUES (?, ?, ?, ?, ?, ?, ' . $this->timeExpression($delaySeconds) . ')';

        try {
            $this->pdo->prepare($sql)->execute([
                $userId,
                $type,
                $dedupeKey,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $priority,
                $maxAttempts,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === self::DUPLICATE) {
                return null;
            }

            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Reclama el siguiente trabajo ejecutable para este worker.
     *
     * `FOR UPDATE SKIP LOCKED` es lo que permite tener más de un worker sin
     * que dos se lleven el mismo trabajo: el segundo no espera al primero, se
     * salta la fila bloqueada y coge la siguiente. Sin SKIP LOCKED, dos
     * workers se serializarían el uno al otro.
     *
     * El intento se suma **aquí**, no al fallar. Si un trabajo tumba al worker
     * —memoria, timeout, un `kill -9`— no habría nadie que registrase el
     * fallo, y ese trabajo volvería a tumbarlo indefinidamente. Sumando al
     * reclamar, un trabajo venenoso agota sus intentos y muere.
     *
     * @param  list<string>  $types  vacío = cualquier tipo
     * @return array<string,mixed>|null
     */
    public function claim(string $workerId, array $types = []): ?array
    {
        $filtroTipos = '';
        $params      = [self::PENDING];

        if ($types !== []) {
            $filtroTipos = ' AND type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            $params      = [self::PENDING, ...$types];
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM jobs
                  WHERE state = ?
                    AND run_after <= UTC_TIMESTAMP(3)' . $filtroTipos . '
                  ORDER BY priority ASC, run_after ASC, id ASC
                  LIMIT 1
                  FOR UPDATE SKIP LOCKED'
            );

            $stmt->execute($params);

            $id = $stmt->fetchColumn();

            if ($id === false) {
                $this->pdo->commit();

                return null;
            }

            $this->pdo->prepare(
                'UPDATE jobs
                    SET state = ?, locked_by = ?, locked_at = UTC_TIMESTAMP(3),
                        attempts = attempts + 1, last_error = NULL
                  WHERE id = ?'
            )->execute([self::RUNNING, $workerId, $id]);

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $this->find((int) $id);
    }

    /** Trabajo terminado con éxito. */
    public function complete(int $id): void
    {
        // dedupe_key a NULL: la clave solo debe bloquear encolados mientras el
        // trabajo está vivo. Mañana toca volver a purgar.
        $this->pdo->prepare(
            'UPDATE jobs
                SET state = ?, dedupe_key = NULL, locked_by = NULL, locked_at = NULL,
                    finished_at = UTC_TIMESTAMP(3), last_error = NULL
              WHERE id = ?'
        )->execute([self::DONE, $id]);
    }

    /**
     * Trabajo fallido: vuelve a la cola con espera creciente, o muere si ya ha
     * gastado sus intentos.
     *
     * @return string  el estado en el que queda
     */
    public function fail(int $id, string $error): string
    {
        $job = $this->find($id);

        if ($job === null) {
            return self::DEAD;
        }

        $agotado = (int) $job['attempts'] >= (int) $job['max_attempts'];

        if ($agotado) {
            $this->pdo->prepare(
                'UPDATE jobs
                    SET state = ?, dedupe_key = NULL, locked_by = NULL, locked_at = NULL,
                        finished_at = UTC_TIMESTAMP(3), last_error = ?
                  WHERE id = ?'
            )->execute([self::DEAD, $this->trimError($error), $id]);

            return self::DEAD;
        }

        $this->pdo->prepare(
            'UPDATE jobs
                SET state = ?, locked_by = NULL, locked_at = NULL, last_error = ?,
                    run_after = ' . $this->timeExpression($this->backoffFor((int) $job['attempts'])) . '
              WHERE id = ?'
        )->execute([self::PENDING, $this->trimError($error), $id]);

        return self::PENDING;
    }

    /**
     * Devuelve el trabajo a la cola **sin gastarle un intento**.
     *
     * Existe para un caso concreto: un tipo de trabajo cuyo manejador todavía
     * no está desplegado. Encolarlo no es un error del trabajo, así que no
     * debe morir por ello; cuando la fase que lo implementa llegue al
     * servidor, se ejecutará solo.
     */
    public function defer(int $id, int $seconds, string $reason): void
    {
        $this->pdo->prepare(
            'UPDATE jobs
                SET state = ?, locked_by = NULL, locked_at = NULL, last_error = ?,
                    attempts = GREATEST(attempts - 1, 0),
                    run_after = ' . $this->timeExpression($seconds) . '
              WHERE id = ?'
        )->execute([self::PENDING, $this->trimError($reason), $id]);
    }

    /**
     * Devuelve a la cola los trabajos que quedaron marcados como en ejecución
     * por un worker que ya no existe (reinicio, OOM, corte de luz).
     *
     * No se les perdona el intento: si el worker murió ejecutándolos, pueden
     * ser justamente ellos la causa.
     *
     * @return int  cuántos se han recuperado
     */
    public function reclaimStale(int $timeoutSeconds): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE jobs
                SET state = ?, locked_by = NULL, locked_at = NULL,
                    last_error = ?, run_after = UTC_TIMESTAMP(3)
              WHERE state = ?
                AND locked_at < UTC_TIMESTAMP(3) - INTERVAL ? SECOND'
        );

        $stmt->execute([
            self::PENDING,
            'Recuperado: el worker que lo tenía dejó de responder.',
            self::RUNNING,
            $timeoutSeconds,
        ]);

        return $stmt->rowCount();
    }

    /** Borra trabajos ya terminados con éxito. La tabla no es un archivo histórico. */
    public function forget(int $olderThanDays): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM jobs
              WHERE state = ?
                AND finished_at < UTC_TIMESTAMP(3) - INTERVAL ? DAY'
        );

        $stmt->execute([self::DONE, $olderThanDays]);

        return $stmt->rowCount();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE id = ?');
        $stmt->execute([$id]);

        $job = $stmt->fetch();

        return $job === false ? null : $job;
    }

    /**
     * @param  array<string,mixed>  $job
     * @return array<string,mixed>
     */
    public static function payloadOf(array $job): array
    {
        $decoded = json_decode((string) ($job['payload'] ?? '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,int>  estado => número de trabajos */
    public function countsByState(): array
    {
        $rows = $this->pdo
            ->query('SELECT state, COUNT(*) AS n FROM jobs GROUP BY state')
            ->fetchAll();

        $counts = [
            self::PENDING => 0, self::RUNNING => 0,
            self::DONE => 0, self::FAILED => 0, self::DEAD => 0,
        ];

        foreach ($rows as $row) {
            $counts[(string) $row['state']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 20, ?string $state = null): array
    {
        $sql    = 'SELECT id, user_id, type, state, attempts, max_attempts, run_after,
                          locked_by, created_at, finished_at, last_error
                     FROM jobs';
        $params = [];

        if ($state !== null) {
            $sql     .= ' WHERE state = ?';
            $params[] = $state;
        }

        $stmt = $this->pdo->prepare($sql . ' ORDER BY id DESC LIMIT ' . max(1, $limit));
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Devuelve a la cola un trabajo muerto, con los intentos a cero. */
    public function retry(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE jobs
                SET state = ?, attempts = 0, locked_by = NULL, locked_at = NULL,
                    finished_at = NULL, run_after = UTC_TIMESTAMP(3)
              WHERE id = ? AND state IN (?, ?)'
        );

        $stmt->execute([self::PENDING, $id, self::DEAD, self::FAILED]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Espera creciente entre reintentos: 10 s, 20 s, 40 s… hasta el tope.
     *
     * Con ruido de hasta el 20 %, para que varios trabajos que fallaron a la
     * vez —porque se cayó la API que usan— no vuelvan todos en el mismo
     * segundo a golpearla otra vez.
     */
    public function backoffFor(int $attempts): int
    {
        $espera = $this->backoffBaseSeconds * (2 ** max(0, $attempts - 1));
        $espera = (int) min($espera, $this->backoffMaxSeconds);

        return $espera + random_int(0, max(1, (int) ($espera * 0.2)));
    }

    /**
     * La sesión trabaja siempre en UTC (ver Database), así que UTC_TIMESTAMP y
     * CURRENT_TIMESTAMP coinciden. Se escribe el explícito para que no dependa
     * de una configuración a distancia.
     */
    private function timeExpression(int $delaySeconds): string
    {
        return $delaySeconds > 0
            ? 'UTC_TIMESTAMP(3) + INTERVAL ' . $delaySeconds . ' SECOND'
            : 'UTC_TIMESTAMP(3)';
    }

    /** `last_error` es TEXT, pero un volcado de excepción entero no ayuda a nadie. */
    private function trimError(string $error): string
    {
        return mb_substr(trim($error), 0, 2000);
    }
}
