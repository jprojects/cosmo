<?php
/** @var \Cosmo\Core\View $this */
/** @var ?array $campaign */
/** @var array $values */
/** @var array<string,string> $errors */
/** @var array<string,array{installation:array,version:?string,supported:bool}> $targets */
use Cosmo\Services\Campaigns;

$v = static fn (string $k, mixed $default = '') => $values[$k] ?? $default;
$err = fn (string $k) => isset($errors[$k]) ? '<span class="cs-error">' . $this->e($errors[$k]) . '</span>' : '';
$cls = static fn (string $k, string $extra = '') => 'cs-field' . ($extra !== '' ? ' ' . $extra : '') . (isset($errors[$k]) ? ' has-error' : '');
$chosen = (array) $v('installations', []);
?>
<form class="cs-card" method="post" action="<?= $this->url('campaign-save') ?>">
  <?= $this->csrfField() ?>
  <?php if ($campaign): ?><input type="hidden" name="id" value="<?= $this->e($campaign['id']) ?>"><?php endif ?>
  <div class="cs-card-body">
    <?php if ($errors): ?><div class="cs-flash cs-flash-error" style="margin-bottom:14px">Revisa los campos marcados</div><?php endif ?>
    <div class="cs-form">
      <div class="<?= $cls('title') ?>">
        <label for="c-title">Título</label>
        <input class="cs-input" id="c-title" name="title" value="<?= $this->e($v('title')) ?>" maxlength="120" required autofocus placeholder="Prepara la teva web per al Nadal">
        <span class="cs-help">En el idioma de los clientes: el panel no lo traduce.</span>
        <?= $err('title') ?>
      </div>
      <div class="<?= $cls('text') ?>">
        <label for="c-text">Texto</label>
        <textarea class="cs-input cs-textarea" id="c-text" name="text" maxlength="600" rows="3"><?= $this->e($v('text')) ?></textarea>
        <?= $err('text') ?>
      </div>
      <div class="<?= $cls('button', 'is-third') ?>">
        <label for="c-button">Texto del botón</label>
        <input class="cs-input" id="c-button" name="button" value="<?= $this->e($v('button')) ?>" maxlength="40" placeholder="Més informació">
        <?= $err('button') ?>
      </div>
      <div class="<?= $cls('url', 'is-half') ?>">
        <label for="c-url">Enlace del botón</label>
        <input class="cs-input cs-mono" id="c-url" name="url" value="<?= $this->e($v('url')) ?>" maxlength="500" placeholder="https://www.aficat.com/…">
        <span class="cs-help">Sin enlace, el aviso sale sin botón.</span>
        <?= $err('url') ?>
      </div>
      <div class="<?= $cls('until', 'is-third') ?>">
        <label for="c-until">Hasta</label>
        <input class="cs-input" id="c-until" name="until" type="date" value="<?= $this->e($v('until')) ?>">
        <span class="cs-help">Vacío: hasta que la borres.</span>
        <?= $err('until') ?>
      </div>
      <div class="<?= $cls('installations') ?>">
        <label>Webs</label>
        <label class="cs-check"><input type="radio" name="target" value="all" <?= $v('target', 'all') === 'all' ? 'checked' : '' ?>> Todas las webs Neutron (también las que se den de alta después)</label>
        <label class="cs-check"><input type="radio" name="target" value="some" <?= $v('target') === 'some' ? 'checked' : '' ?>> Solo estas:</label>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:4px 16px;padding-left:24px">
          <?php foreach ($targets as $id => $t): ?>
            <label class="cs-check"><input type="checkbox" name="installations[]" value="<?= $this->e($id) ?>" <?= in_array($id, $chosen, true) ? 'checked' : '' ?>>
              <span><?= $this->e($t['installation']['name']) ?>
                <?php if (!$t['supported']): ?><br><span class="cs-muted cs-small"><?= $this->e($t['version'] ?? 'Sin estado') ?>: no lo mostrará hasta la <?= $this->e(Campaigns::MIN_NEUTRON) ?></span><?php endif ?></span>
            </label>
          <?php endforeach ?>
          <?php if (!$targets): ?><span class="cs-muted">No hay ninguna web Neutron dada de alta.</span><?php endif ?>
        </div>
        <?= $err('installations') ?>
      </div>
      <div class="cs-form-actions">
        <?php if ($campaign): ?>
          <button class="cs-btn cs-btn-danger" type="submit" form="campaign-delete" data-confirm="¿Eliminar la campaña «<?= $this->e($campaign['title']) ?>»? Se quitará del panel de las webs."><?= $this->icon('trash-2') ?> Eliminar</button>
        <?php endif ?>
        <span style="flex:1"></span>
        <a class="cs-btn" href="<?= $this->url('campaigns') ?>">Cancelar</a>
        <button class="cs-btn cs-btn-primary" type="submit"><?= $this->icon('save') ?> Guardar y enviar</button>
      </div>
    </div>
  </div>
</form>
<?php if ($campaign): ?>
  <form id="campaign-delete" method="post" action="<?= $this->url('campaign-delete') ?>" hidden>
    <?= $this->csrfField() ?>
    <input type="hidden" name="id" value="<?= $this->e($campaign['id']) ?>">
  </form>
<?php endif ?>
