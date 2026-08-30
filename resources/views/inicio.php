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
     data-msg-queued="<?= e(t('capture.queued')) ?>"
     data-msg-pending-one="<?= e(t('capture.pending_one')) ?>"
     data-msg-pending-many="<?= e(t('capture.pending_many')) ?>"
     data-msg-sending-queue="<?= e(t('capture.sending_queue')) ?>"
     data-msg-session-gone="<?= e(t('capture.session_gone')) ?>"
     data-msg-queue-failed="<?= e(t('errors.queue_failed')) ?>"
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

<?php /* Lo que quedó sin enviar. Oculto mientras no haya nada: no es un aviso
         permanente, es un hecho que aparece cuando ocurre. */ ?>
<section class="panel queue" data-queue hidden>
    <p class="queue__count">
        <?= icon('clock', 18) ?>
        <span data-queue-count></span>
    </p>

    <?php /* El motivo de una que el servidor no admite. Elemento de verdad y no
             CSS generado: los lectores de pantalla no anuncian ::after. */ ?>
    <p class="queue__reason" data-queue-reason hidden></p>

    <button type="button" class="link" data-queue-retry>
        <?= e(t('capture.retry')) ?>
    </button>
</section>

<section class="panel">
    <h2><?= e(t('capture.last_entry')) ?></h2>

    <?php if ($latest === null): ?>
        <p class="empty">
            <?= icon('calendar', 22) ?>
            <?= e(t('capture.no_entries')) ?>
        </p>
    <?php else: ?>
        <a class="card card--row card--link" data-latest href="/entrada/<?= e((string) $latest['uid']) ?>">
            <?= icon('clock', 18) ?>
            <span><?= e(Format::relativeDay(
                (string) $latest['local_date'],
                (string) $latest['captured_at'],
                $user->timezone,
                $user->locale,
            )) ?></span>
            <span class="muted"><?= (int) $total ?></span>
        </a>
    <?php endif; ?>
</section>

<?php /* Módulo: capture.js importa la cola de offline.js. Los módulos ya se
         cargan diferidos, así que no hace falta `defer`. */ ?>
<script type="module" src="/assets/capture.js"></script>
