<?php
/** @var \Cosmo\Core\View $this */
/** @var int $status */
/** @var string $message */
/** @var ?string $debug */
?>
<div class="cs-card">
  <div class="cs-empty">
    <?= $this->icon($status === 404 ? 'search' : 'triangle-alert') ?>
    <h2><?= $this->e($message) ?></h2>
    <p class="cs-muted">Error <?= (int) $status ?></p>
    <p><a class="cs-btn" href="<?= $this->url('home') ?>">Volver al panel</a></p>
  </div>
  <?php if ($debug): ?><pre class="cs-card-body cs-mono"><?= $this->e($debug) ?></pre><?php endif ?>
</div>
