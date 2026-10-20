<?php
/** @var \Cosmo\Core\View $this */
/** @var list<array> $rows */
/** @var array<string,array> $installations */
/** @var list<string> $users */
/** @var array{install:string,user:string,action:string} $filters */
use Cosmo\Services\Activity;
?>
<div class="cs-card">
  <form class="cs-toolbar" method="get" action="index.php">
    <input type="hidden" name="r" value="activity">
    <select class="cs-select" name="install" style="width:auto" aria-label="Instalación">
      <option value="">Todas las instalaciones</option>
      <?php foreach ($installations as $id => $inst): ?>
        <option value="<?= $this->e($id) ?>" <?= $filters['install'] === $id ? 'selected' : '' ?>><?= $this->e($inst['name']) ?></option>
      <?php endforeach ?>
    </select>
    <select class="cs-select" name="user" style="width:auto" aria-label="Usuario">
      <option value="">Todos los usuarios</option>
      <?php foreach (array_merge($users, ['cron']) as $u): ?>
        <option value="<?= $this->e($u) ?>" <?= $filters['user'] === $u ? 'selected' : '' ?>><?= $this->e($u) ?></option>
      <?php endforeach ?>
    </select>
    <select class="cs-select" name="action" style="width:auto" aria-label="Acción">
      <option value="">Todas las acciones</option>
      <?php foreach (Activity::ACTIONS as $key => $label): ?>
        <option value="<?= $this->e($key) ?>" <?= $filters['action'] === $key ? 'selected' : '' ?>><?= $this->e($label) ?></option>
      <?php endforeach ?>
    </select>
    <button class="cs-btn" type="submit"><?= $this->icon('filter') ?> Filtrar</button>
    <span class="cs-muted cs-small"><?= count($rows) ?> entradas<?= count($rows) >= 500 ? ' (las 500 últimas)' : '' ?></span>
  </form>
  <?php if (!$rows): ?>
    <div class="cs-empty"><?= $this->icon('history') ?><div>Sin actividad</div></div>
  <?php else: ?>
    <div class="cs-table-wrap">
      <table class="cs-table">
        <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Instalación</th><th>Detalle</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $a): $inst = $installations[$a['install'] ?? ''] ?? null; ?>
          <tr>
            <td class="cs-nowrap" title="<?= $this->e($this->datetime($a['t'])) ?>"><?= $this->e($this->datetime($a['t'])) ?></td>
            <td class="cs-nowrap"><?= $this->e($a['user']) ?></td>
            <td class="cs-nowrap"><?= $a['ok'] ? '<span class="cs-c-success">' . $this->icon('circle-check', 'cs-icon-sm') . '</span>' : '<span class="cs-c-danger">' . $this->icon('circle-x', 'cs-icon-sm') . '</span>' ?>
              <?= $this->e(Activity::label($a['action'])) ?></td>
            <td class="cs-nowrap"><?php if ($inst): ?><a href="<?= $this->url('install', ['id' => $inst['id']]) ?>"><?= $this->e($inst['name']) ?></a><?php else: ?><span class="cs-muted"><?= $this->e($a['install'] ?? '—') ?></span><?php endif ?></td>
            <td class="cs-small"><?= $this->e($a['detail']) ?></td>
            <td class="cs-muted cs-small cs-mono"><?= $this->e($a['ip']) ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?php endif ?>
</div>
