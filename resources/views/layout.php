<?php

/**
 * @var string $content
 * @var \MaiMind\Domain\User|null $user
 * @var string|null $csrf
 */

use MaiMind\Support\Lang;

?>
<!doctype html>
<html lang="<?= e(Lang::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= e(t('app.name')) ?></title>
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
<?php if (($user ?? null) !== null): ?>
    <header class="bar">
        <span class="bar__brand"><?= e(t('app.name')) ?></span>
        <span class="bar__spacer"></span>
        <span class="bar__user"><?= e($user->name()) ?></span>
        <form method="post" action="/salir" class="bar__form">
            <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
            <button type="submit" class="link">
                <?= icon('sign-out', 16) ?><?= e(t('auth.sign_out')) ?>
            </button>
        </form>
    </header>
<?php endif; ?>

<main class="shell">
    <?= $content ?>
</main>
</body>
</html>
