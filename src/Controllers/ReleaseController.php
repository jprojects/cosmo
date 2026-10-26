<?php
declare(strict_types=1);

namespace Cosmo\Controllers;

use Cosmo\Core\HttpException;
use Cosmo\Core\Session;
use Cosmo\Services\Monitor;

final class ReleaseController extends Controller
{
    public function index(): void
    {
        $app = $this->selectedApp();
        $github = $this->app->apps()->github($app['id']);
        $error = null;
        $versions = $changelog = [];
        try {
            $versions = $github->versions();
            $changelog = $github->changelog();
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        // Qué instalaciones de esta aplicación hay en cada versión
        $byVersion = [];
        $snapshots = $this->app->monitor()->snapshots();
        foreach ($this->app->installations()->all() as $id => $inst) {
            $v = Monitor::data($snapshots[$id] ?? null)['version'] ?? null;
            if ($v !== null && $inst['app'] === $app['id']) {
                $byVersion[$v][] = $inst;
            }
        }

        $this->render('releases', [
            'title' => 'Versiones de ' . $app['name'],
            'icon' => 'tag',
            'breadcrumbs' => [['Versiones', $this->app->url('releases')], [$app['name'], null]],
            'app' => $app,
            'apps' => $this->app->apps()->all(),
            'versions' => $versions,
            'changelog' => $changelog,
            'byVersion' => $byVersion,
            'error' => $error,
            'repo' => $app['repo'],
            'hasToken' => !empty($this->app->config['github']['token']),
            'packages' => $github->cachedPackages(),
            'cacheAge' => $github->cacheAge(),
        ]);
    }

    public function refresh(): void
    {
        $this->requirePost();
        $app = $this->selectedApp();
        $github = $this->app->apps()->github($app['id']);
        try {
            $versions = $github->versions(true);
            $github->changelog(true);
            Session::flash('success', $app['name'] . ': ' . count($versions) . ' versiones publicadas; la última es la ' . ($github->latest() ?? '—'));
            $this->app->activity()->log($this->user()->username, 'releases.refresh', null, true, $app['name']);
        } catch (\RuntimeException $e) {
            Session::flash('error', $app['name'] . ': ' . $e->getMessage());
        }
        $this->app->redirect($this->app->url('releases', ['app' => $app['id']]));
    }

    /** ?app= (o POST app); por defecto la primera de la configuración. */
    private function selectedApp(): array
    {
        $apps = $this->app->apps()->all();
        $id = $this->str('app');
        if ($id === '') {
            return reset($apps) ?: throw new HttpException(500, 'No hay ninguna aplicación en la configuración (apps)');
        }
        return $apps[$id] ?? throw new HttpException(404, 'Esa aplicación no existe');
    }
}
