<?php
/** @var \Cosmo\Core\View $this */
/** @var string $appId */
/** @var array $values */
/** @var array<string,string> $errors */
/** @var bool $hasPassword */
$v = static fn (string $k, mixed $default = '') => $values[$k] ?? $default;
$err = fn (string $k) => isset($errors[$k]) ? '<span class="cs-error">' . $this->e($errors[$k]) . '</span>' : '';
$cls = static fn (string $k, string $extra = '') => 'cs-field' . ($extra !== '' ? ' ' . $extra : '') . (isset($errors[$k]) ? ' has-error' : '');
$select = function (string $name, array $options) use ($v): string {
    $html = '';
    foreach ($options as $key => $label) {
        $html .= '<option value="' . $this->e($key) . '"' . ((string) $v($name) === (string) $key ? ' selected' : '') . '>' . $this->e($label) . '</option>';
    }
    return $html;
};
?>
<form class="cs-card" method="post" action="<?= $this->url('app-template-save') ?>">
  <?= $this->csrfField() ?>
  <input type="hidden" name="app" value="<?= $this->e($appId) ?>">
  <div class="cs-card-body">
    <p class="cs-muted" style="margin-top:0">Se escribe en el <code>config.local.php</code> de cada instalación nueva. Lo propio de cada cliente (base de datos, URL, clave de la IA) se pide al instalar. Cambiar la plantilla no toca las instalaciones que ya existen.</p>
    <?php if ($errors): ?><div class="cs-flash cs-flash-error" style="margin-bottom:14px">Revisa los campos marcados</div><?php endif ?>
    <div class="cs-form">
      <div class="cs-form-section">General</div>
      <div class="<?= $cls('locale', 'is-third') ?>">
        <label for="t-locale">Idioma por defecto</label>
        <select class="cs-select" id="t-locale" name="locale"><?= $select('locale', ['ca' => 'Català', 'es' => 'Español', 'en' => 'English']) ?></select>
        <?= $err('locale') ?>
      </div>
      <div class="<?= $cls('login_field', 'is-third') ?>">
        <label for="t-login">Se entra con</label>
        <select class="cs-select" id="t-login" name="login_field"><?= $select('login_field', ['username' => 'Nombre de usuario', 'email' => 'Email', 'both' => 'Usuario o email']) ?></select>
        <?= $err('login_field') ?>
      </div>
      <div class="<?= $cls('two_factor', 'is-third') ?>">
        <label for="t-2fa">Verificación en dos pasos</label>
        <select class="cs-select" id="t-2fa" name="two_factor"><?= $select('two_factor', ['off' => 'Desactivada', 'optional' => 'Opcional (cada usuario)', 'required' => 'Obligatoria']) ?></select>
        <?= $err('two_factor') ?>
      </div>

      <div class="cs-form-section">Correo saliente (SMTP)</div>
      <div class="<?= $cls('mail_host', 'is-half') ?>">
        <label for="t-host">Servidor</label>
        <input class="cs-input cs-mono" id="t-host" name="mail_host" value="<?= $this->e($v('mail_host')) ?>" placeholder="smtp.midominio.com">
        <span class="cs-help">Vacío: las instalaciones nuevas no envían correo hasta configurarlo.</span>
      </div>
      <div class="<?= $cls('mail_port', 'is-third') ?>">
        <label for="t-port">Puerto</label>
        <input class="cs-input" id="t-port" name="mail_port" type="number" min="1" max="65535" value="<?= $this->e($v('mail_port', 587)) ?>">
        <?= $err('mail_port') ?>
      </div>
      <div class="<?= $cls('mail_encryption', 'is-third') ?>">
        <label for="t-enc">Cifrado</label>
        <select class="cs-select" id="t-enc" name="mail_encryption"><?= $select('mail_encryption', ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'Ninguno']) ?></select>
      </div>
      <div class="<?= $cls('mail_username', 'is-half') ?>">
        <label for="t-user">Usuario</label>
        <input class="cs-input cs-mono" id="t-user" name="mail_username" value="<?= $this->e($v('mail_username')) ?>" autocomplete="off">
      </div>
      <div class="<?= $cls('mail_password', 'is-half') ?>">
        <label for="t-pass">Contraseña</label>
        <input class="cs-input" id="t-pass" name="mail_password" type="password" value="" autocomplete="new-password"
               placeholder="<?= $hasPassword ? 'Guardada: déjala vacía para conservarla' : '' ?>">
        <span class="cs-help">Se guarda cifrada en Cosmo y se escribe en el config.local.php de cada instalación nueva.</span>
      </div>
      <div class="<?= $cls('mail_from', 'is-half') ?>">
        <label for="t-from">Remitente (email)</label>
        <input class="cs-input" id="t-from" name="mail_from" value="<?= $this->e($v('mail_from')) ?>" placeholder="no-reply@midominio.com">
        <?= $err('mail_from') ?>
      </div>
      <div class="<?= $cls('mail_from_name', 'is-half') ?>">
        <label for="t-fromname">Remitente (nombre)</label>
        <input class="cs-input" id="t-fromname" name="mail_from_name" value="<?= $this->e($v('mail_from_name')) ?>" placeholder="Por defecto, el nombre de la instalación">
      </div>

      <div class="cs-form-section">IA e informes</div>
      <div class="<?= $cls('ai_limit', 'is-third') ?>">
        <label for="t-ai">Límite mensual de la IA (€)</label>
        <input class="cs-input" id="t-ai" name="ai_limit" inputmode="decimal" value="<?= $this->e($v('ai_limit', 5)) ?>">
        <span class="cs-help">Solo si se pone la clave de la IA al instalar. 0 = sin límite.</span>
      </div>
      <div class="<?= $cls('reports_tables') ?>">
        <label for="t-tables">Tablas de los informes</label>
        <textarea class="cs-textarea cs-mono" id="t-tables" name="reports_tables" rows="5" placeholder="clients = Clientes de la empresa&#10;activities = Visitas, llamadas y reuniones"><?= $this->e($v('reports_tables')) ?></textarea>
        <span class="cs-help">Una por línea: <code>tabla = descripción</code>. Vacío: los informes quedan desactivados.</span>
        <?= $err('reports_tables') ?>
      </div>

      <div class="cs-form-actions">
        <a class="cs-btn" href="<?= $this->url('servers') ?>">Cancelar</a>
        <button class="cs-btn cs-btn-primary" type="submit"><?= $this->icon('save') ?> Guardar</button>
      </div>
    </div>
  </div>
</form>
