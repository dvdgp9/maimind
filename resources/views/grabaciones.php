<?php

/**
 * Todas las grabaciones, de la más reciente a la más antigua.
 *
 * Sin rachas, sin contadores de días seguidos y sin celebrar nada: dejar de
 * grabar un martes no es un fracaso, y un sistema que lo trate como tal cambia
 * lo que la gente graba. Ver `06-diseno-y-tono.md` §3.
 *
 * Los días sin grabar tampoco se rellenan aquí. El silencio es un dato —de eso
 * va `day_coverage`— pero se analiza, no se le reprocha a nadie.
 *
 * @var \MaiMind\Domain\User $user
 * @var list<array<string,mixed>> $entradas
 * @var int $total
 */

use MaiMind\Repository\TranscriptRepository;
use MaiMind\Support\Format;

?>
<h1><?= e(t('list.title')) ?></h1>

<?php if ($entradas === []): ?>

    <p class="empty">
        <?= icon('calendar', 22) ?>
        <?= e(t('capture.no_entries')) ?>
    </p>

<?php else: ?>

    <p class="muted"><?= e(t('list.count', ['count' => $total])) ?></p>

    <?php
    // Agrupadas por día local, no por fecha UTC: si alguien graba a la una de
    // la madrugada, esa grabación es del día que esa persona está viviendo.
    $porDia = [];

    foreach ($entradas as $entrada) {
        $porDia[(string) $entrada['local_date']][] = $entrada;
    }
    ?>

    <?php foreach ($porDia as $dia => $delDia): ?>
        <?php $zona = (string) ($delDia[0]['client_timezone'] ?: $user->timezone); ?>

        <section class="day">
            <h2 class="day__title">
                <?= e(Format::relativeDay(
                    $dia,
                    (string) $delDia[0]['captured_at'],
                    $zona,
                    $user->locale,
                    withTime: false,
                )) ?>
            </h2>

            <ul class="list">
                <?php foreach ($delDia as $entrada): ?>
                    <li>
                        <a class="list__item" href="/entrada/<?= e((string) $entrada['uid']) ?>">
                            <span class="list__time"><?= e(Format::time(
                                (string) $entrada['captured_at'],
                                (string) ($entrada['client_timezone'] ?: $user->timezone),
                                $user->locale,
                            )) ?></span>

                            <span class="list__what">
                                <?php if ($entrada['word_count'] !== null): ?>
                                    <?= e(t('entry.words', ['count' => (int) $entrada['word_count']])) ?>
                                    <?php if ($entrada['transcript_provider'] === TranscriptRepository::PROVIDER_MANUAL): ?>
                                        · <?= e(t('entry.edited_by_you')) ?>
                                    <?php endif; ?>
                                <?php elseif ($entrada['pipeline_state'] === 'failed'): ?>
                                    <?= e(t('entry.failed')) ?>
                                <?php elseif ($entrada['pipeline_state'] === 'transcribing'): ?>
                                    <?= e(t('entry.in_progress')) ?>
                                <?php else: ?>
                                    <?= e(t('entry.not_yet')) ?>
                                <?php endif; ?>
                            </span>

                            <?php if ($entrada['mood_hint'] !== null): ?>
                                <span class="list__mood"><?= (int) $entrada['mood_hint'] ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>

<?php endif; ?>

<p><a class="link" href="/"><?= icon('caret-left', 15) ?><?= e(t('list.back_to_record')) ?></a></p>
