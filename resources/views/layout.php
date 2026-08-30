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

    <?php /* El color de la barra del sistema en cada tema. Sin esto, en oscuro
             la barra se queda clara y la aplicación instalada parece rota. */ ?>
    <meta name="theme-color" content="#FAF3EA" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#151311" media="(prefers-color-scheme: dark)">

    <link rel="manifest" href="/manifest.webmanifest">

    <?php /* iOS no lee el manifest para el icono ni para el modo pantalla
             completa: necesita sus propias etiquetas. */ ?>
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?= e(t('app.name')) ?>">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

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

    <?php /* La invitación a instalar. Nace oculta y la enseña pwa.js solo si
             procede: en Android cuando el navegador avisa de que se puede, en
             iOS siempre —allí no hay aviso—, y nunca si ya está instalada o si
             se cerró una vez. */ ?>
    <?php if (($user ?? null) !== null): ?>
        <section class="panel install" data-install hidden>
            <p class="install__title"><?= e(t('install.title')) ?></p>
            <p class="muted"><?= e(t('install.why')) ?></p>

            <p class="install__steps" data-install-ios hidden><?= e(t('install.ios_steps')) ?></p>

            <button type="button" class="button" data-install-button hidden>
                <?= e(t('install.action')) ?>
            </button>

            <button type="button" class="link" data-install-close>
                <?= e(t('install.dismiss')) ?>
            </button>
        </section>
    <?php endif; ?>
</main>

<script src="/assets/pwa.js" type="module"></script>
</body>
</html>
