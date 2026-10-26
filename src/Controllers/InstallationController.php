<?php
declare(strict_types=1);

namespace Cosmo\Controllers;

use Cosmo\Core\HttpException;
use Cosmo\Core\Session;
use Cosmo\Services\Health;
use Cosmo\Services\Installations;
use Cosmo\Services\Monitor;
use Cosmo\Services\QuasarClient;

final class InstallationController extends Controller
{
    public function show(): void
    {
        $inst = $this->installation();
        $app = $this->app->apps()->get($inst['app']);
        $githubError = null;
        $latest = $this->latestVersion($app['id'], $githubError);
        $snap = $this->app->monitor()->snapshot($inst['id']);
        $data = Monitor::data($snap);
        $version = $data['version'] ?? null;

        $versions = [];
        $changes = [];
        try {
            $versions = $this->app->apps()->github($app['id'])->versions();
            if ($version !== null && $latest !== null && version_compare($version, $latest, '<')) {
                $changes = $this->app->apps()->github($app['id'])->changesBetween($version, $latest);
            }
        } catch (\RuntimeException $e) {
            $githubError ??= $e->getMessage();
        }

        $this->render('installation', [
            'title' => $inst['name'],
            'icon' => 'server',
            'breadcrumbs' => [['Instalaciones', $this->app->url('home')], [$inst['name'], null]],
            'inst' => $inst,
            'app' => $app,
            'snap' => $snap,
            'data' => $data,
            'warnings' => Health::warnings($snap, $app, $latest, (array) ($this->app->config['monitor'] ?? [])),
            'latest' => $latest,
            'versions' => $versions,
            'changes' => $changes,
            'githubError' => $githubError,
            'history' => $this->app->monitor()->history($inst['id'], 7),
            'activity' => $this->app->activity()->recent(30, $inst['id']),
            'endpoint' => QuasarClient::endpoint($inst, 'status'),
        ]);
    }

    public function edit(): void
    {
        $inst = isset($_GET['id']) ? $this->installation() : null;
        $this->render('installation_form', $this->formChrome($inst) + [
            'inst' => $inst,
            'values' => $inst ?? [],
            'errors' => [],
        ]);
    }

    public function save(): void
    {
        $this->requirePost();
        $current = $this->str('id') !== '' ? $this->installation() : null;
        [$saved, $errors] = $this->app->installations()->save($current['id'] ?? null, $_POST);
        if ($errors) {
            $this->render('installation_form', $this->formChrome($current) + [
                'inst' => $current,
                'values' => array_merge($current ?? [], array_map(static fn ($v) => is_string($v) ? $v : '', $_POST), [
                    'verify_ssl' => !empty($_POST['verify_ssl']),
                    'backup_pull' => !empty($_POST['backup_pull']),
                ]),
                'errors' => $errors,
            ], 'layout', 422);
            return;
        }
        $this->app->activity()->log($this->user()->username, $current ? 'install.update' : 'install.create', $saved['id'], true, $saved['name'] . ' · ' . $saved['url']);
        // Primera comprobación al momento
        $snap = $this->app->monitor()->check([$saved['id']], $this->user()->username)[$saved['id']] ?? null;
        Session::flash($snap && $snap['ok'] ? 'success' : 'warning', $snap && $snap['ok']
            ? 'Guardada. Responde con la versión ' . ($snap['data']['version'] ?? '?')
            : 'Guardada, pero no responde: ' . ($snap['error'] ?? 'sin comprobar'));
        $this->app->redirect($this->app->url('install', ['id' => $saved['id']]));
    }

    private function formChrome(?array $inst): array
    {
        $crumbs = [['Instalaciones', $this->app->url('home')]];
        if ($inst) {
            $crumbs[] = [$inst['name'], $this->app->url('install', ['id' => $inst['id']])];
        }
        $crumbs[] = [$inst ? 'Editar' : 'Nueva', null];
        return [
            'title' => $inst ? 'Editar ' . $inst['name'] : 'Nueva instalación',
            'icon' => $inst ? 'pencil' : 'plus',
            'breadcrumbs' => $crumbs,
            'apps' => $this->app->apps()->all(),
        ];
    }

    public function delete(): void
    {
        $this->requirePost();
        $inst = $this->installation();
        $this->app->installations()->delete($inst['id']);
        $this->app->monitor()->forget($inst['id']);
        $keptLocal = count($this->app->localBackups()->list($inst['id']));
        if (!empty($_POST['delete_local'])) {
            $this->app->localBackups()->forget($inst['id']);
            $keptLocal = 0;
        }
        $this->app->activity()->log($this->user()->username, 'install.delete', $inst['id'], true, $inst['name'] . ' · ' . $inst['url']);
        Session::flash('success', 'Instalación «' . $inst['name'] . '» eliminada del panel'
            . ($keptLocal ? " (se conservan sus $keptLocal copias en storage/backups/{$inst['id']})" : ''));
        $this->app->redirect($this->app->url('home'));
    }

    /** POST JSON: prueba URL + token sin guardar (formulario). Token vacío = el guardado. */
    public function test(): void
    {
        $this->requirePost();
        $url = Installations::normalizeUrl($this->str('url'));
        if ($url === '') {
            $this->app->json(['ok' => false, 'error' => 'URL no válida'], 422);
            return;
        }
        $token = $this->str('token');
        if ($token === '' && $this->str('id') !== '') {
            $token = $this->app->installations()->token($this->installation());
        }
        if ($token === '') {
            $this->app->json(['ok' => false, 'error' => 'Escribe el token'], 422);
            return;
        }
        $probe = ['id' => 'test', 'url' => $url, 'verify_ssl' => !empty($_POST['verify_ssl'])];
        $r = $this->app->client()->get($probe, $token, 'status');
        if (!$r->ok && $r->status === 404 && ($r->data['error'] ?? null) !== 'Not Found') {
            $legacy = $this->app->client()->get($probe, $token, 'status-legacy');
            if ($legacy->ok) {
                // Solo Quasar 0.1.x responde a ?r=status sin tener API remota
                $this->app->json(['ok' => true, 'app' => 'quasar', 'known' => true, 'app_name' => $this->app->apps()->name('quasar'), 'version' => $legacy->data['version'] ?? '?', 'name' => $legacy->data['name'] ?? '', 'ms' => $legacy->ms,
                    'warning' => 'Versión sin API remota: solo se podrá consultar el estado']);
                return;
            }
        }
        $this->app->json($r->ok
            ? ['ok' => true, 'app' => $r->data['app'] ?? null, 'app_name' => isset($r->data['app']) ? $this->app->apps()->name((string) $r->data['app']) : null,
                'known' => isset($r->data['app']) && $this->app->apps()->has((string) $r->data['app']),
                'version' => $r->data['version'] ?? '?', 'name' => $r->data['name'] ?? '', 'ms' => $r->ms]
            : ['ok' => false, 'error' => $r->error, 'ms' => $r->ms]);
    }

    /** GET JSON: últimas entradas del log de la instalación. */
    public function logs(): void
    {
        $inst = $this->installation();
        $level = in_array($this->str('level'), ['debug', 'info', 'notice', 'warning', 'error', 'critical'], true) ? $this->str('level') : 'warning';
        $r = $this->app->client()->get($inst, $this->app->installations()->token($inst), 'logs', ['level' => $level, 'limit' => 200, 'days' => 7]);
        $this->app->json($r->ok ? ['ok' => true, 'entries' => $r->data['entries']] : ['ok' => false, 'error' => $r->error], $r->ok ? 200 : 502);
    }

    /** GET JSON: ficheros del núcleo modificados e historial de actualizaciones. */
    public function integrity(): void
    {
        $inst = $this->installation();
        $r = $this->app->client()->get($inst, $this->app->installations()->token($inst), 'integrity');
        $this->app->json($r->ok ? $r->data : ['ok' => false, 'error' => $r->error], $r->ok ? 200 : 502);
    }

    /** POST JSON: on=1|0 */
    public function maintenance(): void
    {
        $this->requirePost();
        $inst = $this->installation();
        $on = $this->str('on') === '1';
        $r = $this->app->client()->post($inst, $this->app->installations()->token($inst), 'maintenance', [
            'on' => $on ? '1' : '0',
            'reason' => $on ? 'Activado desde Cosmo por ' . $this->user()->name : '',
        ], false);
        $this->app->activity()->log($this->user()->username, $on ? 'maintenance.on' : 'maintenance.off', $inst['id'], $r->ok, (string) $r->error);
        $this->app->monitor()->check([$inst['id']], $this->user()->username);
        if (!$r->ok) {
            throw new HttpException(502, (string) $r->error);
        }
        $this->app->json(['ok' => true, 'maintenance' => $r->data['maintenance']]);
    }
}
