<?php

/**
 * Una grabación: escucharla, leer lo que se entendió y corregirlo.
 *
 * El orden importa. La grabación va primero porque es el original; el texto,
 * por bueno que sea, es una interpretación suya. Y si al audio le falta un
 * trozo, se avisa **antes** del texto: quien lo lea tiene que saber que está
 * incompleto antes de leerlo, no después.
 *
 * @var \MaiMind\Domain\User $user
 * @var array<string,mixed> $entrada
 * @var array<string,mixed>|null $transcripcion
 * @var string|null $modeloOriginal
 * @var bool $guardado
 * @var string $csrf
 * @var string $volverA
 */

use MaiMind\Repository\TranscriptRepository;
use MaiMind\Support\Format;

$zona     = (string) ($entrada['client_timezone'] ?: $user->timezone);
$estado   = (string) $entrada['pipeline_state'];
$hayAudio = $entrada['audio_state'] === 'present';
$manual   = TranscriptRepository::isManual($transcripcion);

// Los huecos se avisan mientras el texto sea el que dio la máquina. En cuanto
// una persona lo ha leído y corregido, el aviso ha cumplido: seguir
// enseñándolo es insistir sobre algo ya atendido (06-diseno-y-tono.md §3). El
// dato se conserva en la fila; lo que deja de hacerse es dar la matraca.
$huecos = $transcripcion === null || $manual
    ? []
    : (json_decode((string) ($transcripcion['coverage_gaps'] ?? '[]'), true) ?: []);

// Alto de partida del área de texto. Es una estimación para móvil —unos 32
// caracteres por línea a 390 px— porque el ajuste fino lo hace entrada.js con
// el ancho real; esto es solo para que no salga cortada antes de que corra el
// JavaScript, ni con el JavaScript desactivado.
$lineas = $transcripcion === null
    ? 6
    : max(6, min(24, (int) ceil(mb_strlen((string) $transcripcion['text']) / 32)));

?>
<header class="entry__head">
    <h1 class="entry__when"><?= e(Format::relativeDay(
        (string) $entrada['local_date'],
        (string) $entrada['captured_at'],
        $zona,
        $user->locale,
    )) ?></h1>

    <p class="entry__meta">
        <?php if ($entrada['audio_duration_ms'] !== null): ?>
            <?= e(Format::clock((int) $entrada['audio_duration_ms'])) ?>
        <?php endif; ?>
        <?php if ($entrada['mood_hint'] !== null): ?>
            · <?= e(t('entry.mood_was', ['value' => (int) $entrada['mood_hint']])) ?>
        <?php endif; ?>
    </p>
</header>

<?php if ($guardado): ?>
    <p class="notice" role="status"><?= icon('check', 16) ?><?= e(t('entry.saved')) ?></p>
<?php endif; ?>

<?php if ($hayAudio): ?>
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

        <?php if ($estado === 'failed' && $entrada['error_message'] !== null): ?>
            <p class="muted"><?= e((string) $entrada['error_message']) ?></p>
        <?php endif; ?>

        <?php if (! $hayAudio): ?>
            <p class="muted"><?= e(t('entry.audio_gone', [
                'days' => (int) config('services.audio.retention_days'),
            ])) ?></p>
        <?php endif; ?>

    <?php else: ?>

        <?php /* Quién escribió esto, arriba y no perdido debajo del botón.
                 Y si lo corregiste tú, también qué motor hizo el original:
                 esconderlo dejaba sin forma de saber qué modelo produjo qué. */ ?>
        <p class="entry__source">
            <?php if ($manual): ?>
                <?= e($modeloOriginal === null
                    ? t('entry.edited_by_you')
                    : t('entry.edited_over', ['model' => $modeloOriginal])) ?>
            <?php else: ?>
                <?= e(t('entry.machine_said', ['model' => (string) $transcripcion['model']])) ?>
            <?php endif; ?>
            · <?= e(t('entry.words', ['count' => (int) $transcripcion['word_count']])) ?>
        </p>

        <?php if ($huecos !== []): ?>
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
                                    <?= icon('caret-right', 13) ?><?= e(Format::clock((int) $hueco['start_ms'])) ?> – <?= e(Format::clock((int) $hueco['end_ms'])) ?>
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

            <textarea name="text" class="transcript" rows="<?= $lineas ?>"
                      aria-label="<?= e(t('entry.transcript')) ?>"><?= e((string) $transcripcion['text']) ?></textarea>

            <p class="transcript__hint"><?= e(t('entry.edit_hint')) ?></p>

            <button type="submit" class="button"><?= e(t('entry.save')) ?></button>
        </form>

    <?php endif; ?>
</section>

<p class="entry__back">
    <a class="link" href="<?= e($volverA) ?>"><?= icon('caret-left', 15) ?><?= e(t('entry.back')) ?></a>
</p>

<script type="module" src="/assets/entrada.js"></script>
