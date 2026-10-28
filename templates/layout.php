<?php
/** @var \Cosmo\Core\View $this */
/** @var string $content */
/**
 * Variables opcionales de cada página:
 * @var ?string $heading      título grande (por defecto $title)
 * @var ?string $subtitle
 * @var ?string $icon
 * @var ?array  $breadcrumbs  [[texto, url|null], …]
 */
$user = $this->user();
$heading = $heading ?? $title ?? '';
$headActions = $this->slotHtml('actions');
$headingHtml = $this->slotHtml('heading');
$subtitleHtml = $this->slotHtml('subtitle');
$breadcrumbs = $breadcrumbs ?? [[$heading, null]];
$nav = [
    ['home', 'Instalaciones', 'server', ['home', 'install', 'install-edit', 'install-save']],
    ['releases', 'Versiones', 'tag', ['releases']],
    ...($this->can('manage') ? [['servers', 'Servidores', 'server-cog', ['servers', 'server-edit', 'server-save', 'provision', 'provision-run', 'app-template', 'app-template-save']]] : []),
    ['activity', 'Actividad', 'history', ['activity']],
];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $this->e($this->csrfToken()) ?>">
<title><?= $this->e(($title ?? '') !== '' ? $title . ' · ' . $this->appName() : $this->appName()) ?></title>
<link rel="stylesheet" href="<?= $this->asset('style.css') ?>">
<link rel="icon" href="<?= $this->asset('logo.svg') ?>">
<script src="<?= $this->asset('app.js') ?>" defer></script>
</head>
<body>
<div class="cs-app">
  <aside class="cs-sidebar">
    <a class="cs-brand" href="<?= $this->url('home') ?>"><img src="<?= $this->asset('logo.svg') ?>" alt=""><span><?= $this->e($this->appName()) ?></span></a>
    <nav class="cs-nav">
      <?php foreach ($nav as [$route, $label, $navIcon, $active]): ?>
        <a href="<?= $this->url($route) ?>" class="<?= in_array($this->route(), $active, true) ? 'is-active' : '' ?>" title="<?= $this->e($label) ?>">
          <?= $this->icon($navIcon) ?><span><?= $this->e($label) ?></span>
        </a>
      <?php endforeach ?>
    </nav>
    <div class="cs-sidebar-foot">Cosmo v<?= $this->e($this->version()) ?></div>
  </aside>

  <div class="cs-main">
    <header class="cs-topbar">
      <nav class="cs-crumbs" aria-label="Ruta">
        <?php foreach ($breadcrumbs as $i => [$label, $url]): ?>
          <?php if ($i > 0): ?><?= $this->icon('chevron-right', 'cs-icon-sm') ?><?php endif ?>
          <?php if ($url !== null): ?><a href="<?= $this->e($url) ?>"><?= $this->e($label) ?></a><?php else: ?><strong><?= $this->e($label) ?></strong><?php endif ?>
        <?php endforeach ?>
      </nav>
      <?php if ($user): ?>
        <div class="cs-user">
          <span class="cs-avatar" aria-hidden="true"><?= $this->e(mb_strtoupper(mb_substr($user->name, 0, 1))) ?></span>
          <span class="cs-user-meta"><?= $this->e($user->name) ?><small><?= $this->e($user->roleLabel()) ?></small></span>
          <form method="post" action="<?= $this->url('logout') ?>">
            <?= $this->csrfField() ?>
            <button type="submit" class="cs-btn cs-btn-ghost cs-btn-sm" title="Cerrar sesión"><?= $this->icon('log-out') ?></button>
          </form>
        </div>
      <?php endif ?>
    </header>

    <main class="cs-content">
      <?php if ($heading !== ''): ?>
        <header class="cs-pagehead">
          <?php if (!empty($icon)): ?><span class="cs-pagehead-icon"><?= $this->icon($icon) ?></span><?php endif ?>
          <div class="cs-pagehead-text">
            <h1><?= $headingHtml !== '' ? $headingHtml : $this->e($heading) ?></h1>
            <?php if ($subtitleHtml !== '' || !empty($subtitle)): ?><p><?= $subtitleHtml !== '' ? $subtitleHtml : $this->e($subtitle) ?></p><?php endif ?>
          </div>
          <?php if ($headActions !== ''): ?><div class="cs-actions"><?= $headActions ?></div><?php endif ?>
        </header>
      <?php endif ?>

      <div class="cs-flashes" id="cs-flashes">
        <?php foreach ($this->flashes() as [$type, $message]): ?>
          <div class="cs-flash cs-flash-<?= $this->e($type) ?>" role="status"><?= $this->e($message) ?></div>
        <?php endforeach ?>
      </div>

      <?= $content ?>
    </main>
  </div>
</div>
<div class="cs-toasts" id="cs-toasts" aria-live="polite"></div>
<?= $this->iconSprite(['circle-check', 'circle-x', 'triangle-alert', 'info', 'loader-circle', 'x', 'download', 'trash-2', 'archive', 'database', 'hard-drive', 'cloud-download', 'folder', 'corner-left-up', 'check']) ?>
</body>
</html>
