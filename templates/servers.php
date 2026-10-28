<?php
/** @var \Cosmo\Core\View $this */
/** @var array<string,array> $servers */
/** @var array<string,array> $apps aplicaciones que se pueden instalar desde Cosmo */
?>
<?php $this->slot('actions') ?>
  <a class="cs-btn" href="<?= $this->url('server-edit') ?>"><?= $this->icon('plus') ?> Nuevo servidor</a>
  <?php if ($servers): ?>
    <a class="cs-btn cs-btn-primary" href="<?= $this->url('provision') ?>"><?= $this->icon('rocket') ?> Nueva instalación</a>
  <?php endif ?>
<?php $this->endSlot() ?>

<div class="cs-card">
  <div class="cs-card-head"><strong>Cuentas de hosting</strong><span class="cs-muted cs-small">Por FTP: Cosmo sube la aplicación y la instala en la carpeta de un dominio que ya has creado en el panel del hosting.</span></div>
  <?php if (!$servers): ?>
    <div class="cs-empty">
      Todavía no hay ningún servidor. Añade el FTP de tu cuenta de hosting para instalar aplicaciones nuevas desde Cosmo.
    </div>
  <?php else: ?>
    <div class="cs-table-wrap">
      <table class="cs-table">
        <thead><tr><th>Nombre</th><th>FTP</th><th>Carpeta inicial</th><th>Cifrado</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($servers as $s): ?>
            <tr>
              <td class="cs-row-name"><?= $this->e($s['name']) ?></td>
              <td class="cs-mono"><?= $this->e($s['user'] . '@' . $s['host'] . ($s['port'] !== 21 ? ':' . $s['port'] : '')) ?></td>
              <td class="cs-mono"><?= $this->e($s['base']) ?></td>
              <td><?= $s['ftps'] ? '<span class="cs-badge cs-badge-success">' . $this->icon('lock', 'cs-icon-sm') . ' FTPS</span>' : '<span class="cs-badge cs-badge-warning">FTP sin cifrar</span>' ?></td>
              <td class="cs-right cs-nowrap">
                <a class="cs-btn cs-btn-sm cs-btn-primary" href="<?= $this->url('provision', ['server' => $s['id']]) ?>"><?= $this->icon('rocket') ?> Instalar aquí</a>
                <a class="cs-btn cs-btn-sm" href="<?= $this->url('server-edit', ['id' => $s['id']]) ?>"><?= $this->icon('pencil') ?> Editar</a>
              </td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?php endif ?>
</div>

<div class="cs-card">
  <div class="cs-card-head"><strong>Plantillas de configuración</strong><span class="cs-muted cs-small">Lo que se repite en todas las instalaciones nuevas: correo, verificación en dos pasos, tablas de informes…</span></div>
  <div class="cs-card-body cs-actions">
    <?php foreach ($apps as $a): ?>
      <a class="cs-btn" href="<?= $this->url('app-template', ['app' => $a['id']]) ?>"><?= $this->icon('settings') ?> <?= $this->e($a['name']) ?></a>
    <?php endforeach ?>
  </div>
</div>
