<?php

/**
 * @var string $title
 * @var string $message
 */

?>
<h1><?= e($title) ?></h1>

<?php if (($message ?? '') !== ''): ?>
    <pre class="alert"><?= e($message) ?></pre>
<?php endif; ?>

<p class="muted"><a href="/" class="link"><?= icon('caret-left', 15) ?><?= e(t('app.name')) ?></a></p>
