<?php
/** @var \Cosmo\Core\View $this */
/** @var ?string $error */
/** @var string $username */
/** @var bool $noUsers */
?>
<div class="cs-login">
  <form class="cs-login-box" method="post" action="<?= $this->url('login') ?>">
    <div class="cs-login-brand">
      <img src="<?= $this->asset('logo.svg') ?>" alt="">
      <div><h1><?= $this->e($this->appName()) ?></h1><p>Panel de instalaciones</p></div>
    </div>
    <?php if ($noUsers): ?>
      <div class="cs-flash cs-flash-warning">No hay usuarios configurados. Ejecuta <code>php bin/setup.php</code> en el servidor.</div>
    <?php endif ?>
    <?php if ($error): ?>
      <div class="cs-flash cs-flash-error" role="alert"><?= $this->e($error) ?></div>
    <?php endif ?>
    <?= $this->csrfField() ?>
    <div class="cs-form">
      <div class="cs-field">
        <label for="username">Usuario</label>
        <input class="cs-input" id="username" name="username" value="<?= $this->e($username) ?>" autocomplete="username" autofocus required>
      </div>
      <div class="cs-field">
        <label for="password">Contraseña</label>
        <input class="cs-input" id="password" name="password" type="password" autocomplete="current-password" required>
      </div>
      <div class="cs-field"><button class="cs-btn cs-btn-primary" type="submit"><?= $this->icon('lock') ?> Entrar</button></div>
    </div>
  </form>
</div>
