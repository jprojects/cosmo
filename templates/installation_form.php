<?php
/** @var \Cosmo\Core\View $this */
/** @var ?array $inst */
/** @var array $values */
/** @var array<string,string> $errors */
/** @var array<string,array> $apps */
use Cosmo\Services\Installations;

$v = static fn (string $k, mixed $default = '') => $values[$k] ?? $default;
$err = fn (string $k) => isset($errors[$k]) ? '<span class="cs-error">' . $this->e($errors[$k]) . '</span>' : '';
$cls = static fn (string $k, string $extra = '') => 'cs-field' . ($extra !== '' ? ' ' . $extra : '') . (isset($errors[$k]) ? ' has-error' : '');
$appId = (string) $v('app', (string) array_key_first($apps));
$backupTypes = $apps[$appId]['backups'] ?? reset($apps)['backups'];
?>
<form class="cs-card" method="post" action="<?= $this->url('install-save') ?>" data-install-form data-test-url="<?= $this->url('install-test') ?>">
  <?= $this->csrfField() ?>
  <?php if ($inst): ?><input type="hidden" name="id" value="<?= $this->e($inst['id']) ?>"><?php endif ?>
  <div class="cs-card-body">
    <?php if ($errors): ?><div class="cs-flash cs-flash-error" style="margin-bottom:14px">Revisa los campos marcados</div><?php endif ?>
    <div class="cs-form">
      <div class="<?= $cls('name', 'is-half') ?>">
        <label for="f-name">Nombre</label>
        <input class="cs-input" id="f-name" name="name" value="<?= $this->e($v('name')) ?>" maxlength="100" required autofocus>
        <?= $err('name') ?>
      </div>
      <div class="<?= $cls('client', 'is-half') ?>">
        <label for="f-client">Cliente</label>
        <input class="cs-input" id="f-client" name="client" value="<?= $this->e($v('client')) ?>" maxlength="100">
      </div>
      <div class="<?= $cls('app', 'is-half') ?>">
        <label for="f-app">Aplicación</label>
        <select class="cs-select" id="f-app" name="app" data-backup-types="<?= $this->json(array_map(static fn ($a) => $a['backups'], $apps)) ?>">
          <?php foreach ($apps as $a): ?>
            <option value="<?= $this->e($a['id']) ?>" <?= $appId === $a['id'] ? 'selected' : '' ?>><?= $this->e($a['name']) ?></option>
          <?php endforeach ?>
          <?php if (!isset($apps[$appId])): ?><option value="<?= $this->e($appId) ?>" selected><?= $this->e($appId) ?> (ya no está en la configuración)</option><?php endif ?>
        </select>
        <span class="cs-help">«Probar conexión» la detecta.</span>
        <?= $err('app') ?>
      </div>
      <div class="<?= $cls('environment', 'is-half') ?>">
        <label for="f-env">Entorno</label>
        <select class="cs-select" id="f-env" name="environment">
          <?php foreach (Installations::ENVIRONMENTS as $key => $label): ?>
            <option value="<?= $key ?>" <?= $v('environment', 'production') === $key ? 'selected' : '' ?>><?= $this->e($label) ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div class="<?= $cls('url') ?>">
        <label for="f-url">URL</label>
        <input class="cs-input" id="f-url" name="url" value="<?= $this->e($v('url')) ?>" placeholder="https://gestion.cliente.com" required>
        <span class="cs-help">La dirección donde se abre la aplicación (la carpeta de <code>index.php</code>).</span>
        <?= $err('url') ?>
      </div>
      <div class="<?= $cls('token') ?>">
        <label for="f-token">Token</label>
        <div class="cs-input-group">
          <input class="cs-input cs-mono" id="f-token" name="token" value="" autocomplete="off" spellcheck="false"
                 placeholder="<?= $inst ? 'Déjalo vacío para conservar el actual' : 'mínimo 32 caracteres' ?>" <?= $inst ? '' : 'required' ?>>
          <button class="cs-btn" type="button" data-generate-token title="Generar uno nuevo"><?= $this->icon('key-round') ?> Generar</button>
          <button class="cs-btn" type="button" data-test-connection><?= $this->icon('play') ?> Probar conexión</button>
        </div>
        <span class="cs-help">El mismo valor que <code>remote.token</code> del <code>config.local.php</code> de esa instalación (en Quasar, <code>config/config.local.php</code>). Se guarda cifrado.</span>
        <?= $err('token') ?>
        <div class="cs-test-result" data-test-result></div>
        <div class="cs-snippet" data-token-snippet hidden>
          <p class="cs-help">Añade esto al <code>config.local.php</code> de la instalación:</p>
          <pre data-snippet-text></pre>
        </div>
      </div>
      <div class="cs-field">
        <label class="cs-check"><input type="checkbox" name="verify_ssl" value="1" <?= $v('verify_ssl', true) ? 'checked' : '' ?>> Verificar el certificado SSL</label>
        <span class="cs-help">Desmárcalo solo para servidores internos con certificado autofirmado.</span>
      </div>

      <div class="cs-form-section">Copias programadas (php bin/cron.php)</div>
      <div class="<?= $cls('backup_schedule', 'is-third') ?>">
        <label for="f-schedule">Periodicidad</label>
        <select class="cs-select" id="f-schedule" name="backup_schedule">
          <?php foreach (Installations::SCHEDULES as $key => $label): ?>
            <option value="<?= $key ?>" <?= $v('backup_schedule', 'off') === $key ? 'selected' : '' ?>><?= $this->e($label) ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div class="<?= $cls('backup_type', 'is-third') ?>">
        <label for="f-btype">Tipo</label>
        <select class="cs-select" id="f-btype" name="backup_type">
          <?php foreach ($backupTypes as $key => $label): ?>
            <option value="<?= $key ?>" <?= $v('backup_type', 'full') === $key ? 'selected' : '' ?>><?= $this->e($label) ?></option>
          <?php endforeach ?>
        </select>
        <?= $err('backup_type') ?>
      </div>
      <div class="<?= $cls('backup_hour', 'is-third') ?>">
        <label for="f-hour">A partir de las</label>
        <select class="cs-select" id="f-hour" name="backup_hour">
          <?php for ($h = 0; $h < 24; $h++): ?>
            <option value="<?= $h ?>" <?= (int) $v('backup_hour', 3) === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
          <?php endfor ?>
        </select>
        <?= $err('backup_hour') ?>
      </div>
      <div class="cs-field">
        <label class="cs-check"><input type="checkbox" name="backup_pull" value="1" <?= $v('backup_pull', true) ? 'checked' : '' ?>> Descargar cada copia a Cosmo (segunda ubicación, fuera del hosting)</label>
      </div>

      <div class="cs-field">
        <label for="f-notes">Notas</label>
        <textarea class="cs-textarea" id="f-notes" name="notes" rows="3" placeholder="Acceso FTP, contacto, particularidades…"><?= $this->e($v('notes')) ?></textarea>
        <span class="cs-help">No pongas contraseñas: las notas no se cifran.</span>
      </div>

      <div class="cs-form-actions">
        <a class="cs-btn" href="<?= $inst ? $this->url('install', ['id' => $inst['id']]) : $this->url('home') ?>">Cancelar</a>
        <button class="cs-btn cs-btn-primary" type="submit"><?= $this->icon('save') ?> Guardar</button>
      </div>
    </div>
  </div>
</form>
