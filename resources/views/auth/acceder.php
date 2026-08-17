<?php

/**
 * @var string $csrf
 * @var string|null $error
 * @var string $email
 */

?>
<h1><?= e(t('app.name')) ?></h1>
<p class="tagline"><?= e(t('app.tagline')) ?></p>

<?php if ($error !== null): ?>
    <p class="alert" role="alert"><?= icon('x', 17) ?><span><?= e($error) ?></span></p>
<?php endif; ?>

<form method="post" action="/acceder" class="form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <label class="field">
        <span><?= e(t('auth.email')) ?></span>
        <input type="email" name="email" value="<?= e($email) ?>"
               autocomplete="email" required autofocus>
    </label>

    <label class="field">
        <span><?= e(t('auth.password')) ?></span>
        <input type="password" name="password" autocomplete="current-password" required>
    </label>

    <button type="submit" class="button"><?= e(t('auth.sign_in')) ?></button>
</form>

<p class="muted">
    <?= e(t('auth.no_account')) ?> <a href="/registro"><?= e(t('auth.sign_up')) ?></a>
</p>
