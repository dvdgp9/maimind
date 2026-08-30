<?php

declare(strict_types=1);

namespace MaiMind\Domain\Jobs\Handlers;

use MaiMind\Domain\Capture\AudioStore;
use MaiMind\Domain\Jobs\JobHandler;
use MaiMind\Repository\EntryRepository;
use MaiMind\Support\Config;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Borra del disco las grabaciones cuyo plazo de retención ha vencido.
 *
 * La retención de audio es una promesa al usuario (30 días, D2), y una promesa
 * que solo existe en una columna que nadie lee no es una promesa. Este es el
 * proceso que la cumple.
 *
 * **Un trabajo por usuario, no uno global.** Podría hacerse con un solo SELECT
 * sobre toda la tabla, y sería más corto; pero entonces habría en el sistema un
 * sitio que lee entradas sin filtrar por usuario, y esa es exactamente la
 * grieta que la regla de aislamiento del proyecto existe para no tener. El
 * trabajo lleva su `user_id` y aquí se construye un EntryRepository con él.
 *
 * Idempotente: si el fichero ya no está, la fila se marca igual. Volver a
 * ejecutarlo no encuentra nada que hacer.
 */
final class PurgeAudioHandler implements JobHandler
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
        private readonly ?AudioStore $store = null,
    ) {
    }

    public function type(): string
    {
        return 'purge_audio';
    }

    public function handle(array $payload, array $job): void
    {
        $userId = (int) ($job['user_id'] ?? 0);

        if ($userId <= 0) {
            throw new RuntimeException('purge_audio necesita un user_id: se purga usuario a usuario.');
        }

        $entries = new EntryRepository($this->pdo, $userId);
        $store   = $this->store ?? new AudioStore(Config::basePath((string) config('app.paths.storage')));

        // El día se toma en UTC. audio_purge_after se calculó también en UTC al
        // capturar, así que la comparación es entre iguales. La zona del usuario
        // no pinta nada aquí: son 30 días de reloj, no 30 días de calendario suyo.
        $hoy = (string) ($payload['today'] ?? gmdate('Y-m-d'));

        $vencidas = $entries->audioDuePurge($hoy);

        $borrados = 0;
        $ausentes = 0;

        foreach ($vencidas as $entrada) {
            $ruta = (string) $entrada['audio_path'];

            if ($store->delete($ruta)) {
                $borrados++;
            } else {
                // No es un fallo: puede haberse restaurado un respaldo, o haber
                // pasado ya la purga. Lo que no puede es dejar la fila diciendo
                // que el audio sigue ahí.
                $ausentes++;
            }

            $entries->markAudioPurged((string) $entrada['uid']);
        }

        if ($borrados > 0 || $ausentes > 0) {
            $this->logger->info('Audio purgado', [
                'user_id'  => $userId,
                'deleted'  => $borrados,
                'missing'  => $ausentes,
                'until'    => $hoy,
            ]);
        }
    }
}
