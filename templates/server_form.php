<?php
/** @var \Cosmo\Core\View $this */
/** @var ?array $server */
/** @var array $values */
/** @var array<string,string> $errors */
$v = static fn (string $k, mixed $default = '') => $values[$k] ?? $default;
$err = fn (string $k) => isset($errors[$k]) ? '<span class="cs-error">' . $this->e($errors[$k]) . '</span>' : '';
$cls = static fn (string $k, string $extra = '') => 'cs-field' . ($extra !== '' ? ' ' . $extra : '') . (isset($errors[$k]) ? ' has-error' : '');
?>
<form class="cs-card" method="post" action="<?= $this->url('server-save') ?>" data-server-form data-test-url="<?= $this->url('server-test') ?>">
  <?= $this->csrfField() ?>
  <?php if ($server): ?><input type="hidden" name="id" value="<?= $this->e($server['id']) ?>"><?php endif ?>
  <div class="cs-card-body">
    <?php if ($errors): ?><div class="cs-flash cs-flash-error" style="margin-bottom:14px">Revisa los campos marcados</div><?php endif ?>
    <div class="cs-form">
      <div class="<?= $cls('name') ?>">
        <label for="s-name">Nombre</label>
        <input class="cs-input" id="s-name" name="name" value="<?= $this->e($v('name')) ?>" maxlength="100" required autofocus placeholder="Webempresa · cuenta principal">
        <?= $err('name') ?>
      </div>
      <div class="<?= $cls('host', 'is-half') ?>">
        <label for="s-host">Servidor FTP</label>
        <input class="cs-input cs-mono" id="s-host" name="host" value="<?= $this->e($v('host')) ?>" required placeholder="ftp.midominio.com">
        <?= $err('host') ?>
      </div>
      <div class="<?= $cls('port', 'is-third') ?>">
        <label for="s-port">Puerto</label>
        <input class="cs-input" id="s-port" name="port" type="number" min="1" max="65535" value="<?= $this->e($v('port', 21)) ?>">
        <?= $err('port') ?>
      </div>
      <div class="<?= $cls('user', 'is-half') ?>">
        <label for="s-user">Usuario</label>
        <input class="cs-input cs-mono" id="s-user" name="user" value="<?= $this->e($v('user')) ?>" required autocomplete="off">
        <?= $err('user') ?>
      </div>
      <div class="<?= $cls('password', 'is-half') ?>">
        <label for="s-pass">Contraseña</label>
        <input class="cs-input" id="s-pass" name="password" type="password" value="" autocomplete="new-password"
               placeholder="<?= $server ? 'Déjala vacía para conservar la actual' : '' ?>" <?= $server ? '' : 'required' ?>>
        <span class="cs-help">Se guarda cifrada.</span>
        <?= $err('password') ?>
      </div>
      <div class="<?= $cls('base') ?>">
        <label for="s-base">Carpeta inicial</label>
        <input class="cs-input cs-mono" id="s-base" name="base" value="<?= $this->e($v('base', '/')) ?>" placeholder="/">
        <span class="cs-help">Dónde empieza el explorador de carpetas al elegir la del dominio (p. ej. la que contiene las carpetas de los dominios).</span>
        <?= $err('base') ?>
      </div>
      <div class="cs-field">
        <label class="cs-check"><input type="checkbox" name="ftps" value="1" <?= $v('ftps', true) ? 'checked' : '' ?>> FTPS (FTP con TLS): la contraseña y los ficheros no viajan en claro</label>
        <label class="cs-check"><input type="checkbox" name="verify_ssl" value="1" <?= $v('verify_ssl', true) ? 'checked' : '' ?>> Verificar el certificado del FTP</label>
      </div>
      <div class="cs-field">
        <div class="cs-actions">
          <button class="cs-btn" type="button" data-test-ftp><?= $this->icon('play') ?> Probar conexión</button>
          <span data-test-result></span>
        </div>
      </div>
      <div class="cs-form-actions">
        <?php if ($server): ?>
          <button class="cs-btn cs-btn-danger" type="submit" form="server-delete" data-confirm="¿Eliminar el servidor «<?= $this->e($server['name']) ?>»? Las instalaciones no se tocan."><?= $this->icon('trash-2') ?> Eliminar</button>
        <?php endif ?>
        <span style="flex:1"></span>
        <a class="cs-btn" href="<?= $this->url('servers') ?>">Cancelar</a>
        <button class="cs-btn cs-btn-primary" type="submit"><?= $this->icon('save') ?> Guardar</button>
      </div>
    </div>
  </div>
</form>
<?php if ($server): ?>
  <form id="server-delete" method="post" action="<?= $this->url('server-delete') ?>" hidden>
    <?= $this->csrfField() ?>
    <input type="hidden" name="id" value="<?= $this->e($server['id']) ?>">
  </form>
<?php endif ?>
