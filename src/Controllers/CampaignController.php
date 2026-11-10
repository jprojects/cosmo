<?php
declare(strict_types=1);

namespace Cosmo\Controllers;

use Cosmo\Core\HttpException;
use Cosmo\Core\Session;
use Cosmo\Services\Campaigns;

/**
 * Campañas: avisos de AFI en el panel de las webs Neutron (ver Services\Campaigns). Guardar o borrar una campaña la
 * envía en el momento a las webs; «Enviar ahora» vuelve a enviar y trae las vistas y los clics.
 */
final class CampaignController extends Controller
{
    public function index(): void
    {
        $this->render('campaigns', [
            'title' => 'Campañas',
            'icon' => 'megaphone',
            'campaigns' => $this->app->campaigns()->all(),
            'targets' => $this->app->campaigns()->targets(),
        ]);
    }

    public function edit(): void
    {
        $campaign = isset($_GET['id']) ? $this->campaign() : null;
        $this->form($campaign, $campaign ?? ['target' => 'all', 'installations' => []], []);
    }

    public function save(): void
    {
        $this->requirePost();
        $current = $this->str('id') !== '' ? $this->campaign() : null;
        [$saved, $errors] = $this->app->campaigns()->save($current['id'] ?? null, $_POST, $this->user()->username);
        if ($errors) {
            $values = array_merge($current ?? [], array_map(static fn ($v) => is_string($v) ? $v : '', $_POST));
            $values['installations'] = array_map('strval', (array) ($_POST['installations'] ?? []));
            $this->form($current, $values, $errors, 422);
            return;
        }
        $this->app->activity()->log($this->user()->username, $current ? 'campaign.update' : 'campaign.create', null, true, $saved['title']);
        $this->syncAndFlash('Campaña «' . $saved['title'] . '» guardada');
        $this->app->redirect($this->app->url('campaigns'));
    }

    public function delete(): void
    {
        $this->requirePost();
        $campaign = $this->campaign();
        $this->app->campaigns()->delete($campaign['id']);
        $this->app->activity()->log($this->user()->username, 'campaign.delete', null, true, $campaign['title']);
        $this->syncAndFlash('Campaña «' . $campaign['title'] . '» eliminada');
        $this->app->redirect($this->app->url('campaigns'));
    }

    /** POST: vuelve a enviar todas las campañas y trae vistas y clics. */
    public function sync(): void
    {
        $this->requirePost();
        $this->syncAndFlash('Campañas enviadas');
        $this->app->redirect($this->app->url('campaigns'));
    }

    private function syncAndFlash(string $message): void
    {
        $this->longTask();
        $results = $this->app->campaigns()->sync();
        $sent = count(array_filter($results, static fn ($r) => $r['ok']));
        $failed = array_filter($results, static fn ($r) => !$r['ok'] && !$r['skipped']);
        $skipped = count(array_filter($results, static fn ($r) => $r['skipped']));
        $this->app->activity()->log($this->user()->username, 'campaign.sync', null, !$failed, "$sent webs" . ($failed ? ', ' . count($failed) . ' con error' : ''));
        $text = $message . ': ' . $sent . ($sent === 1 ? ' web actualizada' : ' webs actualizadas');
        if ($skipped) {
            $text .= ', ' . $skipped . ' sin la versión ' . Campaigns::MIN_NEUTRON;
        }
        if ($failed) {
            $names = [];
            foreach (array_keys($failed) as $id) {
                $names[] = ($this->app->installations()->get($id)['name'] ?? $id) . ' (' . $failed[$id]['error'] . ')';
            }
            Session::flash('error', $text . '. No responden: ' . implode(', ', $names));
            return;
        }
        Session::flash('success', $text);
    }

    private function form(?array $campaign, array $values, array $errors, int $status = 200): void
    {
        $this->render('campaign_form', [
            'title' => $campaign ? 'Editar campaña' : 'Nueva campaña',
            'icon' => 'megaphone',
            'breadcrumbs' => [['Campañas', $this->app->url('campaigns')], [$campaign ? $campaign['title'] : 'Nueva campaña', null]],
            'campaign' => $campaign,
            'values' => $values,
            'errors' => $errors,
            'targets' => $this->app->campaigns()->targets(),
        ], 'layout', $status);
    }

    private function campaign(): array
    {
        $id = $_POST['id'] ?? $_GET['id'] ?? null;
        $c = is_string($id) && preg_match('/^[a-z0-9-]{1,40}$/', $id) ? $this->app->campaigns()->get($id) : null;
        return $c ?? throw new HttpException(404, 'Esa campaña no existe');
    }
}
