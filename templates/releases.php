<?php
/** @var \Cosmo\Core\View $this */
/** @var list<array{version:string,tag:string,sha:string}> $versions */
/** @var array<string,array{date:?string,body:string}> $changelog */
/** @var array<string,list<array>> $byVersion */
/** @var ?string $error */
/** @var string $repo */
/** @var bool $hasToken */
/** @var list<array{version:string,size:int}> $packages */
/** @var ?int $cacheAge */
$cached = array_column($packages, 'size', 'version');
?>
<?php $this->slot('subtitle') ?>
  <a href="https://github.com/<?= $this->e($repo) ?>/tags" target="_blank" rel="noopener"><?= $this->e($repo) ?> <?= $this->icon('external-link', 'cs-icon-sm') ?></a>
  · <?= $hasToken ? 'con token' : 'sin token (solo repositorios públicos)' ?>
  <?php if ($cacheAge !== null): ?> · consultado hace <?= $cacheAge < 120 ? $cacheAge . ' s' : intdiv($cacheAge, 60) . ' min' ?><?php endif ?>
<?php $this->endSlot() ?>
<?php $this->slot('actions') ?>
  <?php if ($this->can('operate')): ?>
    <form method="post" action="<?= $this->url('releases-refresh') ?>" data-busy="Consultando GitHub…">
      <?= $this->csrfField() ?>
      <button class="cs-btn" type="submit"><?= $this->icon('refresh-cw') ?> Consultar GitHub</button>
    </form>
  <?php endif ?>
<?php $this->endSlot() ?>

<?php if ($error): ?>
  <div class="cs-flash cs-flash-error"><?= $this->icon('circle-alert') ?> <span><?= $this->e($error) ?></span></div>
<?php endif ?>

<?php if (!$versions && !$error): ?>
  <div class="cs-card"><div class="cs-empty"><?= $this->icon('tag') ?><h2>No hay versiones publicadas</h2>
    <p>Cosmo busca etiquetas <code>vX.Y.Z</code> en el repositorio. Ver «Versiones» en el README de Quasar.</p></div></div>
<?php endif ?>

<?php foreach ($versions as $i => $v):
    $c = $changelog[$v['version']] ?? null;
    $installs = $byVersion[$v['version']] ?? [];
?>
  <section class="cs-card">
    <div class="cs-card-head">
      <h2>
        <span class="cs-badge<?= $i === 0 ? ' cs-badge-success' : ' cs-badge-primary' ?>"><?= $this->e($v['version']) ?></span>
        <?php if ($i === 0): ?><span class="cs-muted cs-small">última</span><?php endif ?>
        <?php if ($c && $c['date']): ?><span class="cs-muted cs-small"><?= $this->e(date('d/m/Y', strtotime($c['date']))) ?></span><?php endif ?>
      </h2>
      <?php if (isset($cached[$v['version']])): ?><span class="cs-badge" title="Paquete descargado en storage/packages"><?= $this->icon('package', 'cs-icon-sm') ?> <?= $this->bytes($cached[$v['version']]) ?></span><?php endif ?>
      <a class="cs-btn cs-btn-sm cs-btn-ghost" href="https://github.com/<?= $this->e($repo) ?>/tree/<?= $this->e($v['tag']) ?>" target="_blank" rel="noopener"><?= $this->icon('git-branch') ?> <?= $this->e(substr($v['sha'], 0, 7)) ?></a>
    </div>
    <div class="cs-card-body">
      <?= $c ? $this->markdown($c['body']) : '<p class="cs-muted">Sin entrada en el CHANGELOG.</p>' ?>
      <?php if ($installs): ?>
        <p class="cs-small" style="margin-top:10px"><span class="cs-muted"><?= count($installs) ?> en esta versión:</span>
          <?php foreach ($installs as $inst): ?>
            <a class="cs-badge" href="<?= $this->url('install', ['id' => $inst['id']]) ?>"><?= $this->e($inst['name']) ?></a>
          <?php endforeach ?>
        </p>
      <?php endif ?>
    </div>
  </section>
<?php endforeach ?>
