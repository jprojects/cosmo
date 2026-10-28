<?php
/** @var \Cosmo\Core\View $this */
/** @var array<string,array> $servers */
/** @var array<string,array> $apps */
/** @var list<string> $versions */
/** @var ?string $githubError */
/** @var array $values */
/** @var array<string,string> $errors */
use Cosmo\Services\Installations;

$v = static fn (string $k, mixed $default = '') => $values[$k] ?? $default;
$err = fn (string $k) => isset($errors[$k]) ? '<span class="cs-error">' . $this->e($errors[$k]) . '</span>' : '';
$cls = static fn (string $k, string $extra = '') => 'cs-field' . ($extra !== '' ? ' ' . $extra : '') . (isset($errors[$k]) ? ' has-error' : '');
$serverId = (string) $v('server', (string) array_key_first($servers));
?>
<?php if (!$servers): ?>
  <div class="cs-card"><div class="cs-empty">Primero añade un servidor. <a href="<?= $this->url('server-edit') ?>">Nuevo servidor</a></div></div>
<?php else: ?>
<form class="cs-card" method="post" action="<?= $this->url('provision-run') ?>" data-provision-form data-busy="Instalando…"
      data-browse-url="<?= $this->url('server-browse') ?>" data-confirm="Cosmo subirá la aplicación a la carpeta elegida, la instalará y la dará de alta. ¿Continuar?">
  <?= $this->csrfField() ?>
  <div class="cs-card-body">
    <p class="cs-muted" style="margin-top:0">Antes, en el panel del hosting: crea el dominio (con su SSL) y una base de datos con su usuario. Cosmo hace el resto: sube la aplicación por FTP, la instala, crea el administrador, la da de alta aquí y hace la primera copia.</p>
    <?php if ($errors): ?><div class="cs-flash cs-flash-error" style="margin-bottom:14px">Revisa los campos marcados</div><?php endif ?>
    <?php if ($githubError): ?><div class="cs-flash cs-flash-error" style="margin-bottom:14px">GitHub: <?= $this->e($githubError) ?></div><?php endif ?>
    <div class="cs-form">
      <div class="cs-form-section">Destino</div>
      <div class="<?= $cls('server', 'is-half') ?>">
        <label for="p-server">Servidor</label>
        <select class="cs-select" id="p-server" name="server">
          <?php foreach ($servers as $s): ?>
            <option value="<?= $this->e($s['id']) ?>" data-base="<?= $this->e($s['base']) ?>" <?= $serverId === $s['id'] ? 'selected' : '' ?>><?= $this->e($s['name']) ?></option>
          <?php endforeach ?>
        </select>
        <?= $err('server') ?>
      </div>
      <div class="<?= $cls('url', 'is-half') ?>">
        <label for="p-url">URL del dominio</label>
        <input class="cs-input" id="p-url" name="url" value="<?= $this->e($v('url')) ?>" placeholder="https://gestion.cliente.com" required>
        <?= $err('url') ?>
      </div>
      <div class="<?= $cls('folder') ?>">
        <label for="p-folder">Carpeta del dominio en el FTP</label>
        <div class="cs-input-group">
          <input class="cs-input cs-mono" id="p-folder" name="folder" value="<?= $this->e($v('folder')) ?>" required placeholder="/gestion.cliente.com">
          <button class="cs-btn" type="button" data-browse><?= $this->icon('folder-open') ?> Explorar</button>
        </div>
        <span class="cs-help">La carpeta raíz del dominio (donde el hosting pone su index.html). Tiene que estar vacía.</span>
        <?= $err('folder') ?>
        <div class="cs-browser" data-browser hidden></div>
      </div>

      <div class="cs-form-section">Aplicación</div>
      <div class="<?= $cls('app', 'is-third') ?>">
        <label for="p-app">Aplicación</label>
        <select class="cs-select" id="p-app" name="app">
          <?php foreach ($apps as $a): ?>
            <option value="<?= $this->e($a['id']) ?>" <?= $v('app', 'quasar') === $a['id'] ? 'selected' : '' ?>><?= $this->e($a['name']) ?></option>
          <?php endforeach ?>
        </select>
        <?= $err('app') ?>
      </div>
      <div class="<?= $cls('version', 'is-third') ?>">
        <label for="p-version">Versión</label>
        <select class="cs-select" id="p-version" name="version">
          <?php foreach ($versions as $i => $ver): ?>
            <option value="<?= $this->e($ver) ?>" <?= $v('version', $versions[0] ?? '') === $ver ? 'selected' : '' ?>><?= $this->e($ver) ?><?= $i === 0 ? ' (la última)' : '' ?></option>
          <?php endforeach ?>
        </select>
        <?= $err('version') ?>
      </div>
      <div class="<?= $cls('backup_schedule', 'is-third') ?>">
        <label for="p-schedule">Copias programadas</label>
        <select class="cs-select" id="p-schedule" name="backup_schedule">
          <?php foreach (Installations::SCHEDULES as $key => $label): ?>
            <option value="<?= $key ?>" <?= $v('backup_schedule', 'daily') === $key ? 'selected' : '' ?>><?= $this->e($label) ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div class="<?= $cls('name', 'is-half') ?>">
        <label for="p-name">Nombre</label>
        <input class="cs-input" id="p-name" name="name" value="<?= $this->e($v('name')) ?>" maxlength="100" required placeholder="Gestión de Cliente S.L.">
        <span class="cs-help">En el panel y como nombre de la aplicación.</span>
        <?= $err('name') ?>
      </div>
      <div class="<?= $cls('client', 'is-half') ?>">
        <label for="p-client">Cliente</label>
        <input class="cs-input" id="p-client" name="client" value="<?= $this->e($v('client')) ?>" maxlength="100">
      </div>

      <div class="cs-form-section">Base de datos (creada en el panel del hosting)</div>
      <div class="<?= $cls('db_host', 'is-third') ?>">
        <label for="p-dbhost">Servidor</label>
        <input class="cs-input cs-mono" id="p-dbhost" name="db_host" value="<?= $this->e($v('db_host', 'localhost')) ?>">
      </div>
      <div class="<?= $cls('db_name', 'is-third') ?>">
        <label for="p-dbname">Base de datos</label>
        <input class="cs-input cs-mono" id="p-dbname" name="db_name" value="<?= $this->e($v('db_name')) ?>" required autocomplete="off">
        <?= $err('db_name') ?>
      </div>
      <div class="<?= $cls('db_port', 'is-third') ?>">
        <label for="p-dbport">Puerto</label>
        <input class="cs-input" id="p-dbport" name="db_port" type="number" value="<?= $this->e($v('db_port', 3306)) ?>">
      </div>
      <div class="<?= $cls('db_user', 'is-half') ?>">
        <label for="p-dbuser">Usuario</label>
        <input class="cs-input cs-mono" id="p-dbuser" name="db_user" value="<?= $this->e($v('db_user')) ?>" required autocomplete="off">
        <?= $err('db_user') ?>
      </div>
      <div class="<?= $cls('db_pass', 'is-half') ?>">
        <label for="p-dbpass">Contraseña</label>
        <input class="cs-input" id="p-dbpass" name="db_pass" type="password" value="" required autocomplete="new-password">
        <span class="cs-help">Va a la instalación; Cosmo no la guarda.</span>
        <?= $err('db_pass') ?>
      </div>

      <div class="cs-form-section">Administrador</div>
      <div class="<?= $cls('admin_user', 'is-third') ?>">
        <label for="p-auser">Usuario</label>
        <input class="cs-input cs-mono" id="p-auser" name="admin_user" value="<?= $this->e($v('admin_user', 'admin')) ?>" required>
        <?= $err('admin_user') ?>
      </div>
      <div class="<?= $cls('admin_name', 'is-third') ?>">
        <label for="p-aname">Nombre</label>
        <input class="cs-input" id="p-aname" name="admin_name" value="<?= $this->e($v('admin_name', 'Administrador')) ?>">
      </div>
      <div class="<?= $cls('admin_email', 'is-third') ?>">
        <label for="p-aemail">Email</label>
        <input class="cs-input" id="p-aemail" name="admin_email" type="email" value="<?= $this->e($v('admin_email')) ?>" required>
        <?= $err('admin_email') ?>
      </div>
      <div class="cs-field"><span class="cs-help">La contraseña la genera Cosmo y se muestra una sola vez al terminar.</span></div>

      <div class="cs-form-section">Opciones</div>
      <div class="<?= $cls('ai_key') ?>">
        <label for="p-ai">Clave de la IA (Anthropic)</label>
        <input class="cs-input cs-mono" id="p-ai" name="ai_key" type="password" value="" autocomplete="off" placeholder="Vacía: sin IA (se puede añadir después en config.local.php)">
      </div>
      <div class="cs-field">
        <label class="cs-check"><input type="checkbox" name="demo" value="1" <?= $v('demo', false) ? 'checked' : '' ?>> Con datos de ejemplo (clientes, países y actividades), para una demo</label>
      </div>
      <div class="cs-field">
        <span class="cs-help">Correo, verificación en dos pasos y tablas de informes: los de la <a href="<?= $this->url('app-template', ['app' => 'quasar']) ?>">plantilla de Quasar</a>.</span>
      </div>

      <div class="cs-form-actions">
        <a class="cs-btn" href="<?= $this->url('servers') ?>">Cancelar</a>
        <button class="cs-btn cs-btn-primary" type="submit" <?= $versions ? '' : 'disabled' ?>><?= $this->icon('rocket') ?> Instalar</button>
      </div>
    </div>
  </div>
</form>
<?php endif ?>
