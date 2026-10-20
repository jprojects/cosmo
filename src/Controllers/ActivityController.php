<?php
declare(strict_types=1);

namespace Cosmo\Controllers;

use Cosmo\Services\Activity;

final class ActivityController extends Controller
{
    public function index(): void
    {
        $install = $this->str('install');
        $user = $this->str('user');
        $action = $this->str('action');
        $installations = $this->app->installations()->all();
        $rows = $this->app->activity()->recent(
            500,
            $install !== '' ? $install : null,
            $user !== '' ? $user : null,
            $action !== '' && isset(Activity::ACTIONS[$action]) ? $action : null,
        );
        $this->render('activity', [
            'title' => 'Actividad',
            'icon' => 'history',
            'rows' => $rows,
            'installations' => $installations,
            'users' => array_keys((array) ($this->app->config['users'] ?? [])),
            'filters' => ['install' => $install, 'user' => $user, 'action' => $action],
        ]);
    }
}
