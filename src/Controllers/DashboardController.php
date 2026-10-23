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
        $githubError = null;
        $latest = $this->latestVersion($githubError);
        $monitorConfig = (array) ($this->app->config['monitor'] ?? []);
        $snapshots = $this->app->monitor()->snapshots();

        $rows = [];
        $summary = ['total' => 0, 'online' => 0, 'offline' => 0, 'outdated' => 0, 'attention' => 0, 'versions' => [], 'ai' => 0, 'ai_cost' => 0.0];
        foreach ($this->app->installations()->all() as $id => $inst) {
            $snap = $snapshots[$id] ?? null;
            $data = Monitor::data($snap);
            $warnings = Health::warnings($snap, $latest, $monitorConfig);
            $version = $data['version'] ?? null;
            $rows[] = [
                'inst' => $inst,
                'snap' => $snap,
                'data' => $data,
                'warnings' => $warnings,
                'level' => Health::worst($warnings),
                'outdated' => $version !== null && $latest !== null && version_compare($version, $latest, '<'),
            ];
            $summary['total']++;
            if ($snap !== null) {
                $snap['ok'] ? $summary['online']++ : $summary['offline']++;
            }
            if ($version !== null && $latest !== null && version_compare($version, $latest, '<')) {
                $summary['outdated']++;
            }
            if (in_array(Health::worst($warnings), ['danger', 'warning'], true)) {
                $summary['attention']++;
            }
            if ($version !== null) {
                $summary['versions'][$version] = ($summary['versions'][$version] ?? 0) + 1;
            }
            if (!empty($data['ai']['enabled'])) {
                $summary['ai']++;
                $summary['ai_cost'] += (float) ($data['ai']['month_cost_eur'] ?? 0);
            }
        }
        uksort($summary['versions'], static fn ($a, $b) => version_compare((string) $b, (string) $a));

        $this->render('dashboard', [
            'title' => 'Instalaciones',
            'icon' => 'server',
            'rows' => $rows,
            'summary' => $summary,
            'latest' => $latest,
            'githubError' => $githubError,
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
