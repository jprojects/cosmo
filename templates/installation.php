<?php
/** @var \Cosmo\Core\View $this */
/** @var array $inst */
/** @var ?array $snap */
/** @var ?array $data */
/** @var list<array{level:string,text:string}> $warnings */
/** @var ?string $latest */
/** @var list<array{version:string,tag:string,sha:string}> $versions */
/** @var array<string,array{date:?string,body:string}> $changes */
/** @var ?string $githubError */
/** @var array $history */
/** @var list<array> $activity */
/** @var string $endpoint */
use Cosmo\Services\Activity;
use Cosmo\Services\Installations;

$d = $data ?? [];
$online = $snap !== null && $snap['ok'];
$hasApi = isset($d['api']);
$canOperate = $this->can('operate');
$version = $d['version'] ?? null;
$outdated = $version !== null && $latest !== null && version_compare($version, $latest, '<');
$levelIcon = ['danger' => 'circle-alert', 'warning' => 'triangle-alert', 'info' => 'info'];
$yes = fn (?bool $v, string $yesText = 'Sí', string $noText = 'No') => $v === null ? '<span class="cs-muted">—</span>'
    : ($v ? '<span class="cs-c-success">' . $this->e($yesText) . '</span>' : '<span class="cs-c-danger">' . $this->e($noText) . '</span>');
$urls = [
    'backups' => $this->url('backups', ['id' => $inst['id']]),
    'backupCreate' => $this->url('backup-create'),
    'backupDelete' => $this->url('backup-delete'),
    'backupPull' => $this->url('backup-pull'),
    'backupDownload' => $this->url('backup-download', ['id' => $inst['id']]),
    'localDownload' => $this->url('local-download', ['id' => $inst['id']]),
    'localDelete' => $this->url('local-delete'),
    'logs' => $this->url('install-logs', ['id' => $inst['id']]),
    'integrity' => $this->url('install-integrity', ['id' => $inst['id']]),
    'update' => $this->url('update'),
    'migrate' => $this->url('migrate'),
    'maintenance' => $this->url('maintenance'),
];
?>
<?php $this->slot('heading') ?>
  <span class="cs-dot<?= $snap === null ? '' : ($online ? ' cs-dot-ok' : ' cs-dot-danger') ?>"></span>
  <?= $this->e($inst['name']) ?>
  <span class="cs-badge<?= $inst['environment'] === 'production' ? ' cs-badge-primary' : '' ?>"><?= $this->e(Installations::ENVIRONMENTS[$inst['environment']] ?? '') ?></span>
  <?php if ($version): ?><span class="cs-badge<?= $outdated ? ' cs-badge-warning' : ' cs-badge-success' ?>">Quasar <?= $this->e($version) ?></span><?php endif ?>
  <?php if (!empty($d['maintenance'])): ?><span class="cs-badge cs-badge-danger"><?= $this->icon('wrench', 'cs-icon-sm') ?> Mantenimiento</span><?php endif ?>
<?php $this->endSlot() ?>
<?php $this->slot('subtitle') ?>
  <a href="<?= $this->e($inst['url']) ?>" target="_blank" rel="noopener"><?= $this->e($inst['url']) ?> <?= $this->icon('external-link', 'cs-icon-sm') ?></a>
  <?php if ($inst['client'] !== ''): ?> · <?= $this->e($inst['client']) ?><?php endif ?>
  <?php if ($snap): ?> · comprobada <?= $this->time($snap['checked_at']) ?><?php endif ?>
<?php $this->endSlot() ?>
<?php $this->slot('actions') ?>
  <?php if ($canOperate): ?>
    <form method="post" action="<?= $this->url('check') ?>" data-busy="Comprobando…">
      <?= $this->csrfField() ?>
      <input type="hidden" name="ids[]" value="<?= $this->e($inst['id']) ?>">
      <input type="hidden" name="back" value="<?= $this->e('r=install&id=' . $inst['id']) ?>">
      <button class="cs-btn" type="submit"><?= $this->icon('refresh-cw') ?> Comprobar</button>
    </form>
    <?php if ($hasApi && $online): ?>
      <button class="cs-btn" type="button" data-maintenance="<?= empty($d['maintenance']) ? '1' : '0' ?>">
        <?= $this->icon('wrench') ?> <?= empty($d['maintenance']) ? 'Activar mantenimiento' : 'Quitar mantenimiento' ?>
      </button>
    <?php endif ?>
  <?php endif ?>
  <?php if ($this->can('manage')): ?>
    <a class="cs-btn" href="<?= $this->url('install-edit', ['id' => $inst['id']]) ?>"><?= $this->icon('pencil') ?> Editar</a>
    <button class="cs-btn cs-btn-danger cs-btn-icon" type="button" data-open-dialog="delete-dialog" title="Eliminar del panel"><?= $this->icon('trash-2') ?></button>
  <?php endif ?>
<?php $this->endSlot() ?>

<div data-install="<?= $this->e($inst['id']) ?>" data-urls="<?= $this->json($urls) ?>" data-can-operate="<?= $canOperate ? '1' : '0' ?>">

<?php if ($githubError): ?>
  <div class="cs-flash cs-flash-warning"><?= $this->icon('triangle-alert') ?> <span>GitHub: <?= $this->e($githubError) ?></span></div>
<?php endif ?>

<div class="cs-grid">
  <div>
    <section class="cs-card">
      <div class="cs-card-head"><h2><?= $this->icon('shield-check') ?> Avisos</h2>
        <span class="cs-badge<?= $warnings ? '' : ' cs-badge-success' ?>"><?= $warnings ? count($warnings) : 'Todo correcto' ?></span></div>
      <?php if ($warnings): ?>
        <ul class="cs-warnings">
          <?php foreach ($warnings as $w): ?>
            <li><span class="cs-c-<?= $this->e($w['level']) ?>"><?= $this->icon($levelIcon[$w['level']] ?? 'info') ?></span><span><?= $this->e($w['text']) ?></span></li>
          <?php endforeach ?>
        </ul>
      <?php else: ?>
        <div class="cs-card-body cs-muted">Sin avisos: responde, está al día, tiene copias recientes y ningún error en las últimas 24 h.</div>
      <?php endif ?>
    </section>

    <section class="cs-card">
      <div class="cs-card-head"><h2><?= $this->icon('server-cog') ?> Estado</h2>
        <?php if ($snap && !$online && $d): ?><span class="cs-badge cs-badge-warning">datos de <?= $this->e($this->datetime($snap['last_ok'])) ?></span><?php endif ?></div>
      <div class="cs-card-body">
        <?php if (!$d): ?>
          <p class="cs-muted">Sin datos todavía. Endpoint: <code><?= $this->e($endpoint) ?></code></p>
        <?php else: ?>
          <dl class="cs-kv">
            <dt>Aplicación</dt><dd><?= $this->e($d['name'] ?? '—') ?> · Quasar <?= $this->e($version ?? '?') ?><?= $hasApi ? ' · API ' . (int) $d['api'] : ' · <span class="cs-c-warning">sin API remota</span>' ?></dd>
            <dt>PHP</dt><dd><?= $this->e($d['php'] ?? '—') ?><?= isset($d['server']['sapi']) ? ' <span class="cs-muted">(' . $this->e($d['server']['sapi']) . ')</span>' : '' ?></dd>
            <dt>Base de datos</dt><dd>
              <?php if (isset($d['db'])): ?>
                <?= $yes($d['db']['ok'] ?? null, 'Conecta', 'No conecta') ?>
                <?= $this->e($d['db']['version'] ?? '') ?>
                <?php if (isset($d['db']['size'])): ?> · <?= $this->bytes((int) $d['db']['size']) ?> en <?= (int) $d['db']['tables'] ?> tablas<?php endif ?>
              <?php else: ?>—<?php endif ?>
            </dd>
            <?php if (isset($d['server'])): ?>
              <dt>Servidor</dt><dd><?= $this->e($d['server']['software'] ?: '—') ?> · <?= $this->e($d['server']['os'] ?? '') ?></dd>
              <dt>Disco</dt><dd><?= $this->bytes($d['server']['disk_free'] ?? null) ?> libres de <?= $this->bytes($d['server']['disk_total'] ?? null) ?></dd>
              <dt>Límites PHP</dt><dd class="cs-small">memory_limit <?= $this->e($d['server']['memory_limit']) ?> · upload <?= $this->e($d['server']['upload_max_filesize']) ?> · post <?= $this->e($d['server']['post_max_size']) ?> · max_execution_time <?= (int) $d['server']['max_execution_time'] ?> s</dd>
              <dt>Escritura</dt><dd><?= $yes($d['server']['writable'] ?? null, 'src/ y public/ con permiso de escritura', 'sin permiso de escritura en src/ o public/') ?></dd>
            <?php endif ?>
            <?php if (isset($d['extensions'])): ?>
              <dt>Extensiones</dt><dd>
                <?php foreach ($d['extensions'] as $ext => $loaded): ?>
                  <span class="cs-badge<?= $loaded ? ' cs-badge-success' : ' cs-badge-danger' ?>"><?= $this->e($ext) ?></span>
                <?php endforeach ?>
              </dd>
            <?php endif ?>
            <?php if (isset($d['config'])): ?>
              <dt>Configuración</dt><dd class="cs-small">
                debug <?= $yes(!$d['config']['debug'], 'off', 'ON') ?> ·
                HTTPS <?= $yes($d['config']['https']) ?> ·
                idioma <?= $this->e($d['config']['locale'] ?? '—') ?> ·
                2FA <?= $this->e($d['config']['two_factor'] ?? '—') ?> ·
                correo <?= $this->e($d['config']['mail_transport'] ?? '—') ?> ·
                plantilla <?= $this->e($d['config']['template'] ?? '—') ?> ·
                copias que guarda <?= (int) ($d['config']['backups_keep'] ?? 0) ?>
                <?= !empty($d['config']['allow_ips']) ? ' · IPs restringidas' : '' ?>
              </dd>
            <?php endif ?>
            <?php if (!empty($d['users'])): ?>
              <dt>Usuarios</dt><dd><?= (int) $d['users']['total'] ?> (<?= (int) $d['users']['active'] ?> activos, <?= (int) $d['users']['admins'] ?> administradores) · último acceso <?= $this->time($d['users']['last_login']) ?></dd>
            <?php endif ?>
            <?php if (isset($d['modules'])): ?><dt>Módulos</dt><dd><?= (int) $d['modules'] ?></dd><?php endif ?>
            <dt>Hora del servidor</dt><dd><?= $this->e($this->datetime($d['time'] ?? null)) ?> <span class="cs-muted"><?= $this->e($d['timezone'] ?? '') ?></span></dd>
            <?php if (!empty($d['last_update'])): ?>
              <dt>Última actualización</dt><dd><?= $this->e($d['last_update']['from']) ?> → <?= $this->e($d['last_update']['to']) ?> · <?= $this->time($d['last_update']['installed_at']) ?></dd>
            <?php endif ?>
          </dl>
        <?php endif ?>
      </div>
    </section>

    <?php $ai = $d['ai'] ?? null; ?>
    <?php if (is_array($ai)): ?>
      <section class="cs-card">
        <div class="cs-card-head"><h2><?= $this->icon('sparkles') ?> Filtrado con IA</h2>
          <?php if (!empty($ai['enabled'])): ?><span class="cs-badge cs-badge-success">activada</span><?php else: ?><span class="cs-badge">desactivada</span><?php endif ?></div>
        <div class="cs-card-body">
          <?php if (empty($ai['enabled'])): ?>
            <p class="cs-muted cs-small">Se activa con <code>ai.enabled</code> y <code>ai.api_key</code> en el <code>config.local.php</code> de la instalación, y <code>"list": { "ai": … }</code> en cada módulo.</p>
          <?php elseif (isset($ai['error'])): ?>
            <p class="cs-c-warning">Sin datos de consumo: <?= $this->e($ai['error']) ?></p>
          <?php else:
              $limit = (float) ($ai['monthly_limit_eur'] ?? 0);
              $used = (float) ($ai['month_cost_eur'] ?? 0);
              $pct = $limit > 0 ? min(100, $used / $limit * 100) : null;
              $tone = ['blocked' => 'danger', 'warn' => 'warning'][$ai['limit_state'] ?? ''] ?? 'success';
          ?>
            <dl class="cs-kv">
              <dt>Modelo</dt><dd><?= $this->e($ai['model'] ?? '—') ?> <span class="cs-muted">(<?= $this->e($ai['provider'] ?? '') ?>)</span></dd>
              <dt>Gasto del mes</dt><dd>
                <strong><?= $this->eur($used) ?></strong>
                <?php if ($pct !== null): ?>
                  de <?= $this->eur($limit) ?> (<?= (int) floor($pct) ?> %)
                  <div class="cs-meter cs-meter-<?= $tone ?>" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $pct ?>"><span style="width:<?= round($pct, 1) ?>%"></span></div>
                <?php else: ?><span class="cs-muted">· sin límite mensual</span><?php endif ?>
              </dd>
              <dt>Consultas</dt><dd><?= (int) ($ai['queries'] ?? 0) ?> este mes<?= (int) ($ai['errors'] ?? 0) ? ' · <span class="cs-c-warning">' . (int) $ai['errors'] . ' con error</span>' : '' ?></dd>
              <dt>Tokens</dt><dd class="cs-small"><?= number_format((int) ($ai['input_tokens'] ?? 0), 0, ',', '.') ?> de entrada · <?= number_format((int) ($ai['output_tokens'] ?? 0), 0, ',', '.') ?> de salida</dd>
            </dl>
            <p class="cs-muted cs-small" style="margin:8px 0 0">Datos de la última comprobación (<?= $this->time($snap['checked_at'] ?? null) ?>). El detalle de cada consulta está en «Consumo de IA» de la instalación.</p>
          <?php endif ?>
        </div>
      </section>
    <?php endif ?>

    <section class="cs-card">
      <div class="cs-card-head"><h2><?= $this->icon('activity') ?> Disponibilidad · 7 días</h2>
        <?php if ($history['uptime'] !== null): ?>
          <span class="cs-badge<?= $history['uptime'] >= 99.5 ? ' cs-badge-success' : ($history['uptime'] >= 95 ? ' cs-badge-warning' : ' cs-badge-danger') ?>"><?= $this->e(number_format($history['uptime'], 2, ',', '.')) ?> %</span>
        <?php endif ?></div>
      <div class="cs-card-body">
        <?php if (!$history['checks']): ?>
          <p class="cs-muted">Sin comprobaciones. Programa <code>php bin/cron.php</code> para comprobar cada pocos minutos.</p>
        <?php else:
            $points = array_slice($history['points'], -240);
            $max = max(1, ...array_map(static fn ($p) => $p['ms'], $points));
        ?>
          <div class="cs-uptime" aria-label="Historial de comprobaciones">
            <?php foreach ($points as $p): ?>
              <span class="<?= $p['ok'] ? '' : 'is-fail' ?>" style="height:<?= $p['ok'] ? max(8, (int) round($p['ms'] * 100 / $max)) : 100 ?>%"
                    title="<?= $this->e($this->datetime($p['t']) . ' · ' . ($p['ok'] ? $p['ms'] . ' ms' : 'sin respuesta')) ?>"></span>
            <?php endforeach ?>
          </div>
          <div class="cs-muted cs-small"><?= (int) $history['checks'] ?> comprobaciones · respuesta media <?= $history['avg_ms'] !== null ? (int) $history['avg_ms'] . ' ms' : '—' ?></div>
        <?php endif ?>
      </div>
    </section>

    <section class="cs-card">
      <div class="cs-card-head"><h2><?= $this->icon('history') ?> Actividad reciente</h2>
        <a class="cs-btn cs-btn-sm cs-btn-ghost" href="<?= $this->url('activity', ['install' => $inst['id']]) ?>">Ver toda</a></div>
      <div class="cs-card-body is-flush">
        <?php if (!$activity): ?>
          <div class="cs-empty">Sin actividad</div>
        <?php else: ?>
          <table class="cs-table">
            <tbody>
            <?php foreach (array_slice($activity, 0, 12) as $a): ?>
              <tr>
                <td class="cs-nowrap cs-muted cs-small"><?= $this->time($a['t']) ?></td>
                <td><?= $a['ok'] ? '' : '<span class="cs-c-danger">' . $this->icon('circle-x', 'cs-icon-sm') . '</span> ' ?><?= $this->e(Activity::label($a['action'])) ?>
                  <?php if ($a['detail'] !== ''): ?><div class="cs-row-sub"><?= $this->e(mb_strimwidth($a['detail'], 0, 140, '…')) ?></div><?php endif ?></td>
                <td class="cs-muted cs-small cs-nowrap"><?= $this->e($a['user']) ?></td>
              </tr>
            <?php endforeach ?>
            </tbody>
          </table>
        <?php endif ?>
      </div>
    </section>
  </div>

  <div>
    <section class="cs-card">
      <div class="cs-card-head"><h2><?= $this->icon('circle-arrow-up') ?> Actualización</h2>
        <?php if ($outdated): ?><span class="cs-badge cs-badge-warning"><?= $this->e($version) ?> → <?= $this->e($latest) ?></span>
        <?php elseif ($version && $latest): ?><span class="cs-badge cs-badge-success">Al día</span><?php endif ?></div>
      <div class="cs-card-body">
        <?php if (!$hasApi && $d): ?>
          <p>Esta versión no tiene API remota. Actualízala a mano a la 0.2.0 o superior (subir los ficheros y ejecutar <code>php bin/install.php</code>) y a partir de ahí se actualiza desde aquí.</p>
        <?php elseif (!$d): ?>
          <p class="cs-muted">Sin datos de la instalación.</p>
        <?php else: ?>
          <?php if ($changes): ?>
            <div class="cs-changelog">
              <?php foreach ($changes as $v => $c): ?>
                <h3><span class="cs-badge cs-badge-primary"><?= $this->e($v) ?></span> <span class="cs-muted cs-small"><?= $this->e($c['date'] ?? '') ?></span></h3>
                <?= $this->markdown($c['body']) ?>
              <?php endforeach ?>
            </div>
          <?php elseif (!$outdated && $version): ?>
            <p class="cs-muted">Tiene la última versión publicada.</p>
          <?php endif ?>

          <?php if ($canOperate && $versions): ?>
            <form class="cs-form" data-update-form style="margin-top:12px">
              <div class="cs-field is-half">
                <label for="up-version">Versión</label>
                <select class="cs-select" id="up-version" name="version">
                  <?php foreach ($versions as $v): ?>
                    <option value="<?= $this->e($v['version']) ?>" <?= $v['version'] === $latest && $outdated ? 'selected' : '' ?> <?= $v['version'] === $version ? 'disabled' : '' ?>>
                      <?= $this->e($v['version']) ?><?= $v['version'] === $version ? ' (instalada)' : '' ?><?= $v['version'] === $latest ? ' · última' : '' ?>
                    </option>
                  <?php endforeach ?>
                </select>
              </div>
              <div class="cs-field is-half" style="justify-content:flex-end">
                <button class="cs-btn cs-btn-primary" type="submit" <?= $online ? '' : 'disabled' ?>><?= $this->icon('rocket') ?> Actualizar</button>
              </div>
              <div class="cs-field">
                <label class="cs-check"><input type="checkbox" name="backup" value="1" checked> Copia completa antes de actualizar (recomendado)</label>
                <label class="cs-check"><input type="checkbox" name="force" value="1"> Sobrescribir ficheros del núcleo modificados a mano</label>
                <label class="cs-check"><input type="checkbox" name="downgrade" value="1"> Permitir volver a una versión anterior</label>
              </div>
            </form>
            <ul class="cs-steps" data-update-log hidden></ul>
            <?php if (!empty($d['maintenance'])): ?>
              <p class="cs-small" style="margin-top:10px">
                <?= $this->icon('triangle-alert', 'cs-c-warning') ?> Sigue en mantenimiento. Si una actualización se quedó a medias, reintenta la migración:
                <button class="cs-btn cs-btn-sm" type="button" data-migrate><?= $this->icon('database') ?> Reintentar migración</button>
              </p>
            <?php endif ?>
          <?php endif ?>
        <?php endif ?>
      </div>
    </section>

    <section class="cs-card" data-backups-card>
      <div class="cs-card-head"><h2><?= $this->icon('archive') ?> Copias de seguridad</h2>
        <button class="cs-btn cs-btn-sm cs-btn-ghost" type="button" data-backups-reload title="Recargar"><?= $this->icon('refresh-cw') ?></button></div>
      <?php if ($canOperate && $hasApi): ?>
        <div class="cs-toolbar">
          <button class="cs-btn cs-btn-sm cs-btn-primary" type="button" data-backup="full"><?= $this->icon('archive') ?> Completa</button>
          <button class="cs-btn cs-btn-sm" type="button" data-backup="db"><?= $this->icon('database') ?> Base de datos</button>
          <button class="cs-btn cs-btn-sm" type="button" data-backup="files"><?= $this->icon('hard-drive') ?> Ficheros</button>
          <label class="cs-check cs-small"><input type="checkbox" data-backup-pull checked> Guardar también en Cosmo</label>
        </div>
      <?php endif ?>
      <div class="cs-card-body cs-small cs-muted">
        Programadas: <strong><?= $this->e(Installations::SCHEDULES[$inst['backup_schedule']] ?? '') ?></strong>
        <?php if ($inst['backup_schedule'] !== 'off'): ?>
          (<?= $this->e(['full' => 'completa', 'db' => 'base de datos', 'files' => 'ficheros'][$inst['backup_type']] ?? '') ?>, a partir de las <?= (int) $inst['backup_hour'] ?>:00<?= $inst['backup_pull'] ? ', se guarda en Cosmo' : '' ?>)
        <?php endif ?>
      </div>
      <div class="cs-card-body is-flush" data-backups>
        <?php if ($hasApi): ?><div class="cs-empty"><?= $this->icon('loader-circle', 'cs-spin') ?></div>
        <?php else: ?><div class="cs-empty">Necesita la API remota (Quasar 0.2.0 o superior)</div><?php endif ?>
      </div>
    </section>

    <?php if ($hasApi): ?>
      <section class="cs-card" data-logs-card>
        <div class="cs-card-head"><h2><?= $this->icon('file-text') ?> Log</h2>
          <select class="cs-select" style="height:26px" data-log-level aria-label="Nivel mínimo">
            <option value="error">Errores</option>
            <option value="warning" selected>Avisos y errores</option>
            <option value="info">Info y superior</option>
            <option value="debug">Todo</option>
          </select>
          <button class="cs-btn cs-btn-sm" type="button" data-logs-load><?= $this->icon('eye') ?> Ver</button></div>
        <div class="cs-card-body is-flush" data-logs><div class="cs-empty cs-small">Últimos 7 días de <code>storage/logs</code>. Pulsa «Ver».</div></div>
      </section>

      <section class="cs-card" data-integrity-card>
        <div class="cs-card-head"><h2><?= $this->icon('shield-alert') ?> Integridad del núcleo</h2>
          <button class="cs-btn cs-btn-sm" type="button" data-integrity-load><?= $this->icon('search') ?> Revisar</button></div>
        <div class="cs-card-body" data-integrity>
          <?php if (($d['integrity'] ?? null) === null): ?>
            <p class="cs-muted cs-small">Sin manifiesto: se crea en la primera actualización desde Cosmo. A partir de ahí se detectan los ficheros del núcleo cambiados a mano.</p>
          <?php else: ?>
            <p class="cs-small"><?= (int) $d['integrity']['modified'] ?> ficheros modificados, <?= (int) $d['integrity']['missing'] ?> que faltan.</p>
          <?php endif ?>
        </div>
      </section>
    <?php endif ?>

    <?php if ($inst['notes'] !== ''): ?>
      <section class="cs-card">
        <div class="cs-card-head"><h2><?= $this->icon('file-text') ?> Notas</h2></div>
        <div class="cs-card-body"><?= nl2br($this->e($inst['notes'])) ?></div>
      </section>
    <?php endif ?>
  </div>
</div>
</div>

<?php if ($this->can('manage')): ?>
  <dialog class="cs-dialog" id="delete-dialog">
    <form method="post" action="<?= $this->url('install-delete') ?>">
      <?= $this->csrfField() ?>
      <input type="hidden" name="id" value="<?= $this->e($inst['id']) ?>">
      <div class="cs-dialog-head"><h2>¿Eliminar «<?= $this->e($inst['name']) ?>» del panel?</h2></div>
      <div class="cs-dialog-body">
        <p>Se borran sus datos de Cosmo (configuración, token, historial). La instalación de Quasar no se toca.</p>
        <label class="cs-check"><input type="checkbox" name="delete_local" value="1"> Borrar también las copias guardadas en Cosmo</label>
      </div>
      <div class="cs-dialog-foot">
        <button class="cs-btn" type="button" data-close-dialog>Cancelar</button>
        <button class="cs-btn cs-btn-primary" type="submit"><?= $this->icon('trash-2') ?> Eliminar</button>
      </div>
    </form>
  </dialog>
<?php endif ?>
