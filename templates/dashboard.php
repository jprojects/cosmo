<?php
/** @var \Cosmo\Core\View $this */
/** @var list<array{inst:array,app:array,latest:?string,snap:?array,data:?array,warnings:list<array>,level:string,outdated:bool}> $rows */
/** @var array $summary */
/** @var array<string,array> $apps */
/** @var array<string,?string> $latest última versión por aplicación (solo las que tienen instalaciones) */
/** @var array<string,string> $githubErrors nombre de la aplicación => error */
/** @var ?string $lastCheck */
use Cosmo\Services\Installations;

$levelIcon = ['danger' => 'circle-alert', 'warning' => 'triangle-alert', 'info' => 'info'];
$canOperate = $this->can('operate');
$appsInUse = array_keys($latest);
$showAi = (bool) array_filter($rows, static fn ($r) => isset($r['data']['ai']));
?>
<?php $this->slot('actions') ?>
  <?php if ($canOperate && $rows): ?>
    <form method="post" action="<?= $this->url('check') ?>" data-busy="Comprobando…">
      <?= $this->csrfField() ?>
      <button class="cs-btn" type="submit"><?= $this->icon('refresh-cw') ?> Comprobar todas</button>
    </form>
  <?php endif ?>
  <?php if ($this->can('manage')): ?>
    <a class="cs-btn cs-btn-primary" href="<?= $this->url('install-edit') ?>"><?= $this->icon('plus') ?> Nueva instalación</a>
  <?php endif ?>
<?php $this->endSlot() ?>
<?php if ($rows): $this->slot('subtitle') ?>
  <?php $known = array_filter($latest); ?>
  <?php if ($known): ?>Última versión:
    <?= implode(' · ', array_map(fn ($appId, $v) => '<a href="' . $this->url('releases', ['app' => $appId]) . '">'
        . $this->e($apps[$appId]['name'] ?? $appId) . ' <strong>' . $this->e($v) . '</strong></a>', array_keys($known), $known)) ?>
  <?php else: ?>Sin datos de GitHub<?php endif ?>
  · comprobadas <?= $this->time($lastCheck) ?>
<?php $this->endSlot(); endif ?>

<?php foreach ($githubErrors as $appName => $githubError): ?>
  <div class="cs-flash cs-flash-warning"><?= $this->icon('triangle-alert') ?> <span>No se pueden consultar las versiones de <?= $this->e($appName) ?>: <?= $this->e($githubError) ?></span></div>
<?php endforeach ?>

<?php if (!$rows): ?>
  <div class="cs-card">
    <div class="cs-empty">
      <?= $this->icon('server') ?>
      <h2>Aún no hay instalaciones</h2>
      <p>Activa <code>remote.token</code> en el <code>config.local.php</code> de una instalación (<?= $this->e(implode(', ', array_column($apps, 'name'))) ?>) y dala de alta aquí.</p>
      <?php if ($this->can('manage')): ?><p><a class="cs-btn cs-btn-primary" href="<?= $this->url('install-edit') ?>"><?= $this->icon('plus') ?> Nueva instalación</a></p><?php endif ?>
    </div>
  </div>
<?php return; endif ?>

<div class="cs-stats">
  <div class="cs-stat"><div class="cs-stat-label"><?= $this->icon('server', 'cs-icon-sm') ?> Instalaciones</div><div class="cs-stat-value"><?= $summary['total'] ?></div></div>
  <div class="cs-stat is-success"><div class="cs-stat-label"><?= $this->icon('circle-check', 'cs-icon-sm') ?> Responden</div><div class="cs-stat-value"><?= $summary['online'] ?></div></div>
  <div class="cs-stat<?= $summary['offline'] ? ' is-danger' : '' ?>"><div class="cs-stat-label"><?= $this->icon('wifi-off', 'cs-icon-sm') ?> No responden</div><div class="cs-stat-value"><?= $summary['offline'] ?></div></div>
  <div class="cs-stat<?= $summary['outdated'] ? ' is-warning' : '' ?>"><div class="cs-stat-label"><?= $this->icon('circle-arrow-up', 'cs-icon-sm') ?> Por actualizar</div><div class="cs-stat-value"><?= $summary['outdated'] ?></div></div>
  <div class="cs-stat<?= $summary['attention'] ? ' is-warning' : '' ?>"><div class="cs-stat-label"><?= $this->icon('triangle-alert', 'cs-icon-sm') ?> Requieren atención</div><div class="cs-stat-value"><?= $summary['attention'] ?></div></div>
  <?php if ($showAi && $summary['ai']): ?>
    <div class="cs-stat" title="Suma del gasto de este mes de las <?= (int) $summary['ai'] ?> instalaciones con IA, según su última comprobación">
      <div class="cs-stat-label"><?= $this->icon('sparkles', 'cs-icon-sm') ?> IA este mes · <?= (int) $summary['ai'] ?> con IA</div>
      <div class="cs-stat-value"><?= $this->eur($summary['ai_cost']) ?></div>
    </div>
  <?php endif ?>
</div>

<div class="cs-card" data-installations>
  <div class="cs-toolbar">
    <input class="cs-input" type="search" placeholder="Buscar por nombre, cliente, URL…" data-filter-text aria-label="Buscar">
    <select class="cs-select" data-filter-kind aria-label="Filtrar">
      <option value="">Todas</option>
      <option value="attention">Requieren atención</option>
      <option value="outdated">Por actualizar</option>
      <option value="offline">No responden</option>
      <?php if ($showAi): ?><option value="ai">Con IA</option><?php endif ?>
      <?php if (count($appsInUse) > 1): foreach ($appsInUse as $appId): ?>
        <option value="app:<?= $this->e($appId) ?>"><?= $this->e($apps[$appId]['name'] ?? $appId) ?></option>
      <?php endforeach; endif ?>
      <?php foreach (Installations::ENVIRONMENTS as $env => $label): ?>
        <option value="env:<?= $env ?>"><?= $this->e($label) ?></option>
      <?php endforeach ?>
    </select>
    <span class="cs-muted cs-small" data-filter-count></span>
    <span class="cs-muted cs-small" style="margin-left:auto">
      <?php foreach ($summary['versions'] as $appId => $versions): foreach ($versions as $v => $n): ?>
        <span class="cs-badge<?= ($latest[$appId] ?? null) && version_compare((string) $v, $latest[$appId], '<') ? ' cs-badge-warning' : ' cs-badge-success' ?>"><?= $this->e(($apps[$appId]['name'] ?? $appId) . ' ' . $v) ?> × <?= $n ?></span>
      <?php endforeach; endforeach ?>
    </span>
  </div>

  <?php if ($canOperate): ?>
    <div class="cs-bulkbar" data-bulkbar hidden>
      <strong data-bulk-count></strong>
      <?php if (array_filter($latest)): ?>
        <button class="cs-btn cs-btn-sm cs-btn-primary" type="button" data-bulk="update" data-version="latest" title="Cada una a la última versión de su aplicación"><?= $this->icon('circle-arrow-up') ?> Actualizar a la última</button>
      <?php endif ?>
      <button class="cs-btn cs-btn-sm" type="button" data-bulk="backup" data-type="db"
              title="<?= $this->e(implode(' · ', array_map(static fn ($a) => $a['name'] . ': ' . ($a['backups']['db'] ?? 'sin este tipo'), $apps))) ?>"><?= $this->icon('database') ?> Copia de datos</button>
      <button class="cs-btn cs-btn-sm" type="button" data-bulk="backup" data-type="full"><?= $this->icon('archive') ?> Copia completa</button>
      <button class="cs-btn cs-btn-sm" type="button" data-bulk="check"><?= $this->icon('refresh-cw') ?> Comprobar</button>
      <button class="cs-btn cs-btn-sm cs-btn-ghost" type="button" data-bulk="clear">Quitar selección</button>
    </div>
  <?php endif ?>

  <div class="cs-table-wrap">
    <table class="cs-table">
      <thead>
        <tr>
          <?php if ($canOperate): ?><th class="cs-col-check"><input type="checkbox" data-check-all aria-label="Seleccionar todas"></th><?php endif ?>
          <th>Instalación</th>
          <th>Entorno</th>
          <th>Versión</th>
          <th>PHP</th>
          <th>Última copia</th>
          <th class="cs-right">Errores 24 h</th>
          <?php if ($showAi): ?><th class="cs-right" title="Gasto de este mes del filtrado con IA (y límite mensual)">IA</th><?php endif ?>
          <th>Avisos</th>
          <th>Comprobada</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row):
          $inst = $row['inst'];
          $app = $row['app'];
          $snap = $row['snap'];
          $d = $row['data'];
          $online = $snap !== null && $snap['ok'];
          // Rojo = no responde; naranja = responde pero hay avisos
          $dot = $snap === null ? '' : (!$online ? 'danger' : (in_array($row['level'], ['danger', 'warning'], true) ? 'warning' : 'ok'));
          $search = mb_strtolower($inst['name'] . ' ' . $inst['client'] . ' ' . $inst['url'] . ' ' . $app['name'] . ' ' . ($d['name'] ?? '') . ' ' . ($d['version'] ?? ''));
          $kinds = array_filter([
              in_array($row['level'], ['danger', 'warning'], true) ? 'attention' : null,
              $row['outdated'] ? 'outdated' : null,
              $snap !== null && !$online ? 'offline' : null,
              !empty($d['ai']['enabled']) ? 'ai' : null,
              'env:' . $inst['environment'],
              'app:' . $app['id'],
          ]);
          $lastBackup = $d['backups']['last']['created_at'] ?? null;
      ?>
        <tr data-row data-id="<?= $this->e($inst['id']) ?>" data-name="<?= $this->e($inst['name']) ?>"
            data-search="<?= $this->e($search) ?>" data-kinds="<?= $this->e(implode(' ', $kinds)) ?>" data-api="<?= isset($d['api']) ? '1' : '0' ?>" data-outdated="<?= $row['outdated'] ? '1' : '0' ?>">
          <?php if ($canOperate): ?><td class="cs-col-check"><input type="checkbox" data-check aria-label="Seleccionar <?= $this->e($inst['name']) ?>"></td><?php endif ?>
          <td>
            <div style="display:flex;gap:10px;align-items:center">
              <span class="cs-dot<?= $dot ? ' cs-dot-' . $dot : '' ?>" title="<?= $this->e($snap === null ? 'Sin comprobar' : ($online ? ($dot === 'ok' ? 'Responde' : 'Responde, con avisos') : 'No responde')) ?>"></span>
              <div>
                <a class="cs-row-name" href="<?= $this->url('install', ['id' => $inst['id']]) ?>"><?= $this->e($inst['name']) ?></a>
                <div class="cs-row-sub"><?= $this->e($inst['client'] !== '' ? $inst['client'] . ' · ' : '') ?><?= $this->e(preg_replace('~^https?://~', '', $inst['url'])) ?></div>
              </div>
            </div>
          </td>
          <td><span class="cs-badge<?= $inst['environment'] === 'production' ? ' cs-badge-primary' : '' ?>"><?= $this->e(Installations::ENVIRONMENTS[$inst['environment']] ?? $inst['environment']) ?></span></td>
          <td class="cs-nowrap">
            <?php if ($d): ?>
              <span class="cs-badge<?= $row['outdated'] ? ' cs-badge-warning' : ' cs-badge-success' ?>"><?= $this->e($app['name'] . ' ' . ($d['version'] ?? '?')) ?></span>
              <?php if ($row['outdated']): ?><span class="cs-muted cs-small">→ <?= $this->e($row['latest']) ?></span><?php endif ?>
              <?php if (!empty($d['maintenance'])): ?><span class="cs-badge cs-badge-danger" title="Modo mantenimiento"><?= $this->icon('wrench', 'cs-icon-sm') ?></span><?php endif ?>
            <?php else: ?><span class="cs-muted"><?= $this->e($app['name']) ?></span><?php endif ?>
          </td>
          <td class="cs-nowrap"><?= $this->e($d['php'] ?? '—') ?></td>
          <td class="cs-nowrap"><?= isset($d['backups']) ? $this->time($lastBackup) : '<span class="cs-muted">—</span>' ?></td>
          <td class="cs-right">
            <?php $errors = $d['logs']['error'] ?? null; ?>
            <?php if ($errors === null): ?><span class="cs-muted">—</span>
            <?php elseif ($errors > 0): ?><span class="cs-badge cs-badge-danger"><?= (int) $errors ?></span>
            <?php else: ?><span class="cs-muted">0</span><?php endif ?>
          </td>
          <?php if ($showAi): ?><td class="cs-right cs-nowrap">
            <?php $ai = $d['ai'] ?? null; ?>
            <?php if (empty($ai['enabled'])): ?><span class="cs-muted" title="<?= $ai === null ? 'Sin filtrado con IA (Quasar anterior a la 0.5.0, u otra aplicación)' : 'IA desactivada' ?>">—</span>
            <?php else:
                $tone = ['blocked' => ' cs-badge-danger', 'warn' => ' cs-badge-warning'][$ai['limit_state'] ?? ''] ?? ' cs-badge-primary';
                $limit = (float) ($ai['monthly_limit_eur'] ?? 0);
            ?>
              <span class="cs-badge<?= $tone ?>" title="<?= $this->e(($ai['model'] ?? '') . ' · ' . (int) ($ai['queries'] ?? 0) . ' consultas este mes' . ($limit > 0 ? ' · límite ' . $this->eur($limit) : ' · sin límite')) ?>">
                <?= $this->icon('sparkles', 'cs-icon-sm') ?> <?= $this->eur((float) ($ai['month_cost_eur'] ?? 0)) ?><?= $limit > 0 ? ' / ' . $this->eur($limit) : '' ?>
              </span>
            <?php endif ?>
          </td><?php endif ?>
          <td>
            <span class="cs-warn-icons">
              <?php foreach ($row['warnings'] as $w): ?>
                <span class="cs-c-<?= $this->e($w['level']) ?>" title="<?= $this->e($w['text']) ?>"><?= $this->icon($levelIcon[$w['level']] ?? 'info') ?></span>
              <?php endforeach ?>
              <?php if (!$row['warnings']): ?><span class="cs-c-success" title="Todo correcto"><?= $this->icon('circle-check') ?></span><?php endif ?>
            </span>
          </td>
          <td class="cs-nowrap cs-muted cs-small"><?= $snap ? $this->time($snap['checked_at']) . ($online ? ' · ' . (int) $snap['ms'] . ' ms' : '') : 'nunca' ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>

<dialog class="cs-dialog" data-bulk-dialog>
  <div class="cs-dialog-head"><h2 data-bulk-title>Operación</h2></div>
  <div class="cs-dialog-body">
    <div data-bulk-options></div>
    <ul class="cs-steps" data-bulk-log></ul>
  </div>
  <div class="cs-dialog-foot">
    <button class="cs-btn" type="button" data-bulk-close>Cerrar</button>
    <button class="cs-btn cs-btn-primary" type="button" data-bulk-run>Empezar</button>
  </div>
</dialog>
