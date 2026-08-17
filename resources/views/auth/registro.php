<?php

/**
 * @var string $csrf
 * @var string|null $error
 * @var string|null $field
 * @var string $email
 * @var string $displayName
 */

?>
<h1><?= e(t('auth.sign_up')) ?></h1>
<p class="tagline"><?= e(t('app.tagline')) ?></p>

<?php if ($error !== null): ?>
    <p class="alert" role="alert"><?= icon('x', 17) ?><span><?= e($error) ?></span></p>
<?php endif; ?>

<form method="post" action="/registro" class="form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="timezone" id="timezone" value="Europe/Madrid">

    <label class="field">
        <span><?= e(t('auth.display_name')) ?></span>
        <input type="text" name="display_name" value="<?= e($displayName) ?>"
               autocomplete="nickname" maxlength="120">
    </label>

    <label class="field<?= $field === 'email' ? ' field--error' : '' ?>">
        <span><?= e(t('auth.email')) ?></span>
        <input type="email" name="email" value="<?= e($email) ?>"
               autocomplete="email" required>
    </label>

    <label class="field<?= $field === 'password' ? ' field--error' : '' ?>">
        <span><?= e(t('auth.password')) ?></span>
        <input type="password" name="password" autocomplete="new-password"
               minlength="10" required>
        <small class="hint"><?= e(t('auth.min_chars')) ?></small>
    </label>

    <button type="submit" class="button"><?= e(t('auth.sign_up')) ?></button>
</form>

<p class="muted">
    <?= e(t('auth.have_account')) ?> <a href="/acceder"><?= e(t('auth.sign_in')) ?></a>
</p>

<script src="/assets/app.js" defer></script>
