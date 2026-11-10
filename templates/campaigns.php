<?php
/** @var \Cosmo\Core\View $this */
/** @var array<string,array> $campaigns */
/** @var array<string,array{installation:array,version:?string,supported:bool}> $targets webs Neutron */
use Cosmo\Services\Campaigns;

$supported = count(array_filter($targets, static fn ($t) => $t['supported']));
$today = date('Y-m-d');
?>
<?php $this->slot('actions') ?>
  <?php if ($campaigns && $this->can('operate')): ?>
    <form method="post" action="<?= $this->url('campaign-sync') ?>" style="display:inline">
      <?= $this->csrfField() ?>
      <button class="cs-btn" type="submit" title="Vuelve a enviar las campañas a las webs y trae las vistas y los clics"><?= $this->icon('refresh-cw') ?> Enviar ahora</button>
    </form>
  <?php endif ?>
  <?php if ($this->can('manage')): ?>
    <a class="cs-btn cs-btn-primary" href="<?= $this->url('campaign-edit') ?>"><?= $this->icon('plus') ?> Nueva campaña</a>
  <?php endif ?>
<?php $this->endSlot() ?>

<div class="cs-card">
  <div class="cs-card-head">
    <strong>Avisos en el panel de las webs</strong>
    <span class="cs-muted cs-small">Salen en el inicio de <code>/admin/</code> de las webs Neutron (<?= $this->e(Campaigns::MIN_NEUTRON) ?> o posterior): <?= $supported ?> de <?= count($targets) ?> webs. Vistas: una por sesión del panel.</span>
  </div>
  <?php if (!$campaigns): ?>
    <div class="cs-empty">Todavía no hay campañas. Crea una para anunciar un servicio o una promoción a los clientes en su panel.</div>
  <?php else: ?>
    <div class="cs-table-wrap">
      <table class="cs-table">
        <thead><tr><th>Campaña</th><th>Webs</th><th>Hasta</th><th class="cs-right">Vistas</th><th class="cs-right">Clics</th><th>Último envío</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($campaigns as $c):
              $tot = Campaigns::totals($c);
              $expired = $c['until'] !== '' && $c['until'] < $today;
              $last = null;
              $errors = 0;
              foreach ((array) $c['sync'] as $s) {
                  $last = max($last ?? '', (string) $s['at']);
                  $errors += $s['ok'] ? 0 : 1;
              } ?>
            <tr>
              <td>
                <div class="cs-row-name"><?= $this->e($c['title']) ?></div>
                <?php if ($c['text'] !== ''): ?><div class="cs-row-sub cs-muted cs-small"><?= $this->e(mb_strimwidth($c['text'], 0, 90, '…')) ?></div><?php endif ?>
              </td>
              <td><?= $c['target'] === 'all' ? 'Todas' : count($c['installations']) ?></td>
              <td class="cs-nowrap"><?= $c['until'] === '' ? '<span class="cs-muted">—</span>' : $this->e(date('d/m/Y', strtotime($c['until']))) ?>
                <?php if ($expired): ?> <span class="cs-badge cs-badge-warning">Caducada</span><?php endif ?></td>
              <td class="cs-right"><?= $tot['views'] ?></td>
              <td class="cs-right"><?= $tot['clicks'] ?><?php if ($tot['views']): ?> <span class="cs-muted cs-small">(<?= round($tot['clicks'] * 100 / $tot['views']) ?> %)</span><?php endif ?></td>
              <td class="cs-nowrap cs-small"><?= $last ? $this->e($this->ago($last)) : '<span class="cs-muted">Nunca</span>' ?>
                <?php if ($errors): ?> <span class="cs-badge cs-badge-danger" title="Webs que no respondieron en el último envío"><?= $errors ?> con error</span><?php endif ?></td>
              <td class="cs-right cs-nowrap">
                <?php if ($this->can('manage')): ?>
                  <a class="cs-btn cs-btn-sm" href="<?= $this->url('campaign-edit', ['id' => $c['id']]) ?>"><?= $this->icon('pencil') ?> Editar</a>
                <?php endif ?>
              </td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?php endif ?>
</div>

<?php $old = array_filter($targets, static fn ($t) => !$t['supported']); ?>
<?php if ($old): ?>
  <div class="cs-card">
    <div class="cs-card-head"><strong>Webs que aún no muestran campañas</strong><span class="cs-muted cs-small">Actualízalas a Neutron <?= $this->e(Campaigns::MIN_NEUTRON) ?> o posterior.</span></div>
    <div class="cs-card-body cs-actions">
      <?php foreach ($old as $id => $t): ?>
        <a class="cs-badge" href="<?= $this->url('install', ['id' => $id]) ?>"><?= $this->e($t['installation']['name']) ?> · <?= $this->e($t['version'] ?? 'sin estado') ?></a>
      <?php endforeach ?>
    </div>
  </div>
<?php endif ?>
