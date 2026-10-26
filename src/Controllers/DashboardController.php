<?php
declare(strict_types=1);

namespace Cosmo\Controllers;

use Cosmo\Core\Session;
use Cosmo\Services\Health;
use Cosmo\Services\Monitor;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $monitorConfig = (array) ($this->app->config['monitor'] ?? []);
        $snapshots = $this->app->monitor()->snapshots();
        $installations = $this->app->installations()->all();

        // Última versión de cada aplicación con alguna instalación (no se consulta GitHub por las demás)
        $latest = [];
        $githubErrors = [];
        foreach (array_unique(array_column($installations, 'app')) as $appId) {
            $error = null;
            $latest[$appId] = $this->latestVersion($appId, $error);
            if ($error !== null) {
                $githubErrors[$this->app->apps()->name($appId)] = $error;
            }
        }

        $rows = [];
        $summary = ['total' => 0, 'online' => 0, 'offline' => 0, 'outdated' => 0, 'attention' => 0, 'versions' => [], 'ai' => 0, 'ai_cost' => 0.0];
        foreach ($installations as $id => $inst) {
            $app = $this->app->apps()->get($inst['app']);
            $snap = $snapshots[$id] ?? null;
            $data = Monitor::data($snap);
            $warnings = Health::warnings($snap, $app, $latest[$app['id']], $monitorConfig);
            $version = $data['version'] ?? null;
            $outdated = $version !== null && $latest[$app['id']] !== null && version_compare($version, $latest[$app['id']], '<');
            $rows[] = [
                'inst' => $inst,
                'app' => $app,
                'latest' => $latest[$app['id']],
                'snap' => $snap,
                'data' => $data,
                'warnings' => $warnings,
                'level' => Health::worst($warnings),
                'outdated' => $outdated,
            ];
            $summary['total']++;
            if ($snap !== null) {
                $snap['ok'] ? $summary['online']++ : $summary['offline']++;
            }
            if ($outdated) {
                $summary['outdated']++;
            }
            if (in_array(Health::worst($warnings), ['danger', 'warning'], true)) {
                $summary['attention']++;
            }
            if ($version !== null) {
                $summary['versions'][$app['id']][$version] = ($summary['versions'][$app['id']][$version] ?? 0) + 1;
            }
            if (!empty($data['ai']['enabled'])) {
                $summary['ai']++;
                $summary['ai_cost'] += (float) ($data['ai']['month_cost_eur'] ?? 0);
            }
        }
        foreach ($summary['versions'] as &$byVersion) {
            uksort($byVersion, static fn ($a, $b) => version_compare((string) $b, (string) $a));
        }
        unset($byVersion);

        $this->render('dashboard', [
            'title' => 'Instalaciones',
            'icon' => 'server',
            'rows' => $rows,
            'summary' => $summary,
            'apps' => $this->app->apps()->all(),
            'latest' => $latest,
            'githubErrors' => $githubErrors,
            'lastCheck' => $snapshots ? max(array_column($snapshots, 'checked_at')) : null,
        ]);
    }

    /** POST: comprueba todas (o ids[]); responde JSON o vuelve al panel. */
    public function check(): void
    {
        $this->requirePost();
        $user = $this->user()->username;
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_values(array_filter($_POST['ids'], 'is_string')) : null;
        $this->longTask();
        $snapshots = $this->app->monitor()->check($ids, $user);
        $failed = count(array_filter($snapshots, static fn ($s) => !$s['ok']));
        if ($this->app->wantsJson()) {
            $this->app->json(['ok' => true, 'checked' => count($snapshots), 'failed' => $failed]);
            return;
        }
        Session::start($this->app->config);
        Session::flash($failed ? 'warning' : 'success', sprintf('%d comprobadas%s', count($snapshots), $failed ? ", $failed no responden" : ', todas responden'));
        $back = $this->str('back');
        $this->app->redirect(preg_match('/^r=[a-z-]+(&[a-z_]+=[a-z0-9-]*)*$/', $back) ? 'index.php?' . $back : $this->app->url('home'));
    }
}
