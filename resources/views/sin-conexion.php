<?php

/**
 * Lo que se ve al abrir la aplicación sin red y sin copia de la página pedida.
 *
 * Dice lo que pasa y lo que NO se ha perdido, que es lo único que de verdad
 * preocupa a alguien que acaba de grabar: sus grabaciones en cola siguen en el
 * teléfono. Sin botón de reintentar —recargar es el botón del navegador— y sin
 * disculpas.
 *
 * @var \MaiMind\Domain\User|null $user
 */

?>
<h1><?= e(t('offline.title')) ?></h1>

<p class="tagline"><?= e(t('offline.body')) ?></p>

<p class="muted"><?= e(t('offline.queue_safe')) ?></p>
