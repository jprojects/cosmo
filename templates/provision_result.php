<?php
/** @var \Cosmo\Core\View $this */
/** @var array{ok:bool,steps:list<array{ok:bool,text:string}>,installation:?array,password:?string,error:?string} $result */
/** @var array $data */
?>
<?php if ($result['password'] !== null): ?>
  <div class="cs-card cs-provision-done">
    <div class="cs-card-head"><?= $this->icon('key-round', 'cs-c-success') ?><strong>Acceso del administrador</strong>
      <span class="cs-muted cs-small">Cópialo ahora: no se vuelve a mostrar ni se guarda en Cosmo.</span></div>
    <div class="cs-card-body">
      <dl class="cs-kv">
        <dt>Dirección</dt><dd><a href="<?= $this->e($data['url']) ?>" target="_blank" rel="noopener"><?= $this->e($data['url']) ?></a></dd>
        <dt>Usuario</dt><dd class="cs-mono"><?= $this->e($data['admin_user']) ?> <span class="cs-muted">(email <?= $this->e($data['admin_email']) ?>)</span></dd>
        <dt>Contraseña</dt><dd><span class="cs-mono cs-secret" data-copy-text><?= $this->e($result['password']) ?></span>
          <button class="cs-btn cs-btn-sm" type="button" data-copy><?= $this->icon('copy') ?> Copiar</button></dd>
      </dl>
    </div>
  </div>
<?php endif ?>

<div class="cs-card">
  <div class="cs-card-head"><?= $this->icon($result['ok'] ? 'circle-check' : 'circle-x', $result['ok'] ? 'cs-c-success' : 'cs-c-danger') ?>
    <strong><?= $result['ok'] ? $this->e($data['name']) . ' instalada' : 'No se ha completado' ?></strong></div>
  <div class="cs-card-body">
    <ul class="cs-steps">
      <?php foreach ($result['steps'] as $s): ?>
        <li><?= $this->icon($s['ok'] ? 'circle-check' : 'circle-x', $s['ok'] ? 'cs-c-success' : 'cs-c-danger') ?><span><?= $this->e($s['text']) ?></span></li>
      <?php endforeach ?>
    </ul>
    <?php if (!$result['ok'] && $result['installation'] === null && $result['error'] !== 'alta'): ?>
      <p class="cs-muted">No ha quedado nada a medias en el servidor: puedes corregirlo y volver a intentarlo.</p>
    <?php endif ?>
  </div>
  <div class="cs-card-body cs-actions" style="border-top:1px solid var(--border)">
    <?php if ($result['installation'] !== null): ?>
      <a class="cs-btn cs-btn-primary" href="<?= $this->url('install', ['id' => $result['installation']['id']]) ?>"><?= $this->icon('server') ?> Ver la instalación</a>
    <?php else: ?>
      <a class="cs-btn cs-btn-primary" href="<?= $this->url('provision', ['server' => $data['server']]) ?>"><?= $this->icon('rotate-ccw') ?> Volver a intentarlo</a>
    <?php endif ?>
    <a class="cs-btn" href="<?= $this->url('servers') ?>">Servidores</a>
  </div>
</div>
