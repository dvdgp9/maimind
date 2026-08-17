<?php

/**
 * Pantalla de captura. Es la más importante del producto y tiene tres
 * elementos: saludo, toque opcional y botón de grabar.
 *
 * La regla no es "hazla bonita", es resistir la tentación de añadirle cosas.
 * Ver docs/design/06-diseno-y-tono.md §4.
 *
 * @var \MaiMind\Domain\User $user
 * @var array<string,mixed>|null $latest
 * @var int $total
 * @var string $csrf
 */

use MaiMind\Support\Format;

?>
<p class="date"><?= e(Format::longDate('now', $user->timezone, $user->locale)) ?></p>

<h1><?= e(t('capture.greeting')) ?></h1>

<div class="capture"
     data-csrf="<?= e($csrf) ?>"
     data-msg-recording="<?= e(t('capture.recording')) ?>"
     data-msg-saving="<?= e(t('capture.saving')) ?>"
     data-msg-saved="<?= e(t('capture.saved')) ?>"
     data-msg-offline="<?= e(t('capture.offline_queued')) ?>"
     data-msg-generic="<?= e(t('errors.generic')) ?>"
     data-msg-mic-denied="<?= e(t('errors.mic_denied')) ?>"
     data-msg-mic-missing="<?= e(t('errors.mic_missing')) ?>"
     data-msg-insecure="<?= e(t('errors.insecure')) ?>">

    <fieldset class="mood" data-mood>
        <legend class="mood__legend"><?= e(t('capture.mood_hint')) ?></legend>
        <?php for ($n = 1; $n <= 5; $n++): ?>
            <button type="button" class="mood__dot" data-mood-value="<?= $n ?>"
                    aria-pressed="false" aria-label="<?= $n ?> de 5"></button>
        <?php endfor; ?>
        <p class="mood__note"><?= e(t('capture.mood_skip')) ?></p>
    </fieldset>

    <button type="button" class="record" data-record
            aria-label="<?= e(t('capture.record')) ?>">
        <span data-icon-idle><?= icon('microphone', 34) ?></span>
        <span data-icon-busy hidden><?= icon('stop', 30) ?></span>
    </button>

    <p class="capture__status" role="status" aria-live="polite" data-status></p>

    <button type="button" class="link capture__cancel" data-cancel hidden>
        <?= icon('x', 15) ?><?= e(t('capture.cancel')) ?>
    </button>
</div>

<section class="panel">
    <h2><?= e(t('capture.last_entry')) ?></h2>

    <?php if ($latest === null): ?>
        <p class="empty">
            <?= icon('calendar', 22) ?>
            <?= e(t('capture.no_entries')) ?>
        </p>
    <?php else: ?>
        <div class="card card--row" data-latest>
            <?= icon('clock', 18) ?>
            <span><?= e(Format::relativeDay(
                (string) $latest['local_date'],
                (string) $latest['captured_at'],
                $user->timezone,
                $user->locale,
            )) ?></span>
            <span class="muted"><?= (int) $total ?></span>
        </div>
    <?php endif; ?>
</section>

<script src="/assets/capture.js" defer></script>
