<?php

/**
 * Una grabación: lo que se entendió de ella, y la posibilidad de corregirlo.
 *
 * La corrección a mano no es un extra. El sistema entero se apoya en citas
 * literales de este texto, así que si el transcriptor oyó mal, todo lo que
 * venga después hereda el error. Aquí es donde se corta.
 *
 * Y si al audio le falta un trozo, se dice. Un texto que se lee con fluidez
 * pero al que le falta una frase es peor que un texto con un agujero visible.
 *
 * @var \MaiMind\Domain\User $user
 * @var array<string,mixed> $entrada
 * @var array<string,mixed>|null $transcripcion
 * @var bool $guardado
 * @var string $csrf
 */

use MaiMind\Repository\TranscriptRepository;
use MaiMind\Support\Format;

$zona   = (string) ($entrada['client_timezone'] ?: $user->timezone);
$estado = (string) $entrada['pipeline_state'];

// Los huecos se avisan mientras el texto sea el que dio la máquina. En cuanto
// una persona lo ha leído y corregido, el aviso ha cumplido: seguir enseñándolo
// es insistir sobre algo que ya se ha atendido, y eso lo prohíbe el §3 del
// documento de tono. El dato se conserva igual en la fila; lo que deja de
// hacerse es dar la matraca con él.
$hayAudio = $entrada['audio_state'] === 'present';

$huecos = $transcripcion === null || TranscriptRepository::isManual($transcripcion)
    ? []
    : (json_decode((string) ($transcripcion['coverage_gaps'] ?? '[]'), true) ?: []);

?>
<p class="date">
    <?= e(Format::longDate((string) $entrada['captured_at'], $zona, $user->locale)) ?>
    · <?= e(Format::time((string) $entrada['captured_at'], $zona, $user->locale)) ?>
</p>

<?php if ($entrada['mood_hint'] !== null): ?>
    <p class="muted"><?= e(t('entry.mood_was', ['value' => (int) $entrada['mood_hint']])) ?></p>
<?php endif; ?>

<?php if ($guardado): ?>
    <p class="notice" role="status"><?= e(t('entry.saved')) ?></p>
<?php endif; ?>

<?php if ($hayAudio): ?>
    <?php /* Antes de la transcripción: la grabación es el original y el texto
             es una interpretación suya, por buena que sea. */ ?>
    <section class="panel">
        <h2><?= e(t('entry.audio')) ?></h2>
        <audio class="audio" controls preload="metadata" data-audio
               src="/entrada/<?= e((string) $entrada['uid']) ?>/audio"></audio>
        <p class="muted"><?= e(t('entry.audio_expires', [
            'days' => (int) config('services.audio.retention_days'),
        ])) ?></p>
    </section>
<?php endif; ?>

<section class="panel">
    <h2><?= e(t('entry.transcript')) ?></h2>

    <?php if ($transcripcion === null): ?>

        <p class="empty">
            <?= icon('clock', 22) ?>
            <?php if ($estado === 'failed'): ?>
                <?= e(t('entry.failed')) ?>
            <?php elseif ($estado === 'transcribing'): ?>
                <?= e(t('entry.in_progress')) ?>
            <?php else: ?>
                <?= e(t('entry.not_yet')) ?>
            <?php endif; ?>
        </p>

        <?php if ($entrada['audio_state'] !== 'present'): ?>
            <p class="muted"><?= e(t('entry.audio_gone', [
                'days' => (int) config('services.audio.retention_days'),
            ])) ?></p>
        <?php endif; ?>

    <?php else: ?>

        <?php if ($huecos !== []): ?>
            <?php /* El aviso va ANTES del texto: quien lo lea tiene que saber
                     que está incompleto antes de leerlo, no después. */ ?>
            <div class="gap" role="note">
                <p class="gap__title"><?= e(t('entry.gap_notice', [
                    'seconds' => (int) round(((int) $transcripcion['gap_total_ms']) / 1000),
                ])) ?></p>
                <p class="gap__body"><?= e(t('entry.gap_explain')) ?></p>
                <ul class="gap__list">
                    <?php foreach ($huecos as $hueco): ?>
                        <li>
                            <?php if ($hayAudio): ?>
                                <button type="button" class="link" data-seek="<?= (int) $hueco['start_ms'] / 1000 ?>">
                                    <?= e(Format::clock((int) $hueco['start_ms'])) ?> – <?= e(Format::clock((int) $hueco['end_ms'])) ?>
                                </button>
                            <?php else: ?>
                                <?= e(Format::clock((int) $hueco['start_ms'])) ?> – <?= e(Format::clock((int) $hueco['end_ms'])) ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="/entrada/<?= e((string) $entrada['uid']) ?>/transcripcion">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <label class="field">
                <span class="field__label"><?= e(t('entry.edit_hint')) ?></span>
                <textarea name="text" class="transcript" rows="12"><?= e((string) $transcripcion['text']) ?></textarea>
            </label>

            <button type="submit" class="button"><?= e(t('entry.save')) ?></button>
        </form>

        <p class="muted transcript__meta">
            <?= e(TranscriptRepository::isManual($transcripcion)
                ? t('entry.edited_by_you')
                : t('entry.machine_said', ['model' => (string) $transcripcion['model']])) ?>
            · <?= e(t('entry.words', ['count' => (int) $transcripcion['word_count']])) ?>
        </p>

    <?php endif; ?>
</section>

<p><a class="link" href="/grabaciones"><?= icon('caret-left', 15) ?><?= e(t('entry.back')) ?></a></p>

<script type="module" src="/assets/entrada.js"></script>
