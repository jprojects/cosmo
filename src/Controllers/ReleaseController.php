<?php
declare(strict_types=1);

namespace Cosmo\Controllers;

use Cosmo\Core\Session;
use Cosmo\Services\Monitor;

final class ReleaseController extends Controller
{
    public function index(): void
    {
        $error = null;
        $versions = $changelog = [];
        try {
            $versions = $this->app->github()->versions();
            $changelog = $this->app->github()->changelog();
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        // Qué instalaciones hay en cada versión
        $byVersion = [];
        $snapshots = $this->app->monitor()->snapshots();
        foreach ($this->app->installations()->all() as $id => $inst) {
            $v = Monitor::data($snapshots[$id] ?? null)['version'] ?? null;
            if ($v !== null) {
                $byVersion[$v][] = $inst;
            }
        }

        $this->render('releases', [
            'title' => 'Versiones de Quasar',
            'icon' => 'tag',
            'versions' => $versions,
            'changelog' => $changelog,
            'byVersion' => $byVersion,
            'error' => $error,
            'repo' => (string) ($this->app->config['github']['repo'] ?? ''),
            'hasToken' => !empty($this->app->config['github']['token']),
            'packages' => $this->app->github()->cachedPackages(),
            'cacheAge' => $this->app->github()->cacheAge(),
        ]);
    }

    public function refresh(): void
    {
        $this->requirePost();
        try {
            $versions = $this->app->github()->versions(true);
            $this->app->github()->changelog(true);
            Session::flash('success', count($versions) . ' versiones publicadas; la última es la ' . ($this->app->github()->latest() ?? '—'));
            $this->app->activity()->log($this->user()->username, 'releases.refresh', null);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->app->redirect($this->app->url('releases'));
    }
}
