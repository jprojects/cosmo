<?php
declare(strict_types=1);

namespace Cosmo\Controllers;

use Cosmo\Core\HttpException;
use Cosmo\Core\Session;
use Cosmo\Services\AppTemplates;
use Cosmo\Services\Ftp;
use Cosmo\Services\Provisioner;

/**
 * Servidores (FTP de las cuentas de hosting), plantilla de configuración de cada aplicación e
 * instalaciones nuevas desde Cosmo (Provisioner).
 */
final class ServerController extends Controller
{
    public function index(): void
    {
        $this->render('servers', [
            'title' => 'Servidores',
            'icon' => 'server-cog',
            'servers' => $this->app->servers()->all(),
            'apps' => array_intersect_key($this->app->apps()->all(), array_flip(AppTemplates::PROVISIONABLE)),
        ]);
    }

    public function edit(): void
    {
        $server = isset($_GET['id']) ? $this->server() : null;
        $this->render('server_form', $this->formChrome($server) + ['server' => $server, 'values' => $server ?? [], 'errors' => []]);
    }

    public function save(): void
    {
        $this->requirePost();
        $current = $this->str('id') !== '' ? $this->server() : null;
        [$saved, $errors] = $this->app->servers()->save($current['id'] ?? null, $_POST);
        if ($errors) {
            $this->render('server_form', $this->formChrome($current) + [
                'server' => $current,
                'values' => array_merge($current ?? [], array_map(static fn ($v) => is_string($v) ? $v : '', $_POST), [
                    'ftps' => !empty($_POST['ftps']),
                    'verify_ssl' => !empty($_POST['verify_ssl']),
                ]),
                'errors' => $errors,
            ], 'layout', 422);
            return;
        }
        $this->app->activity()->log($this->user()->username, $current ? 'server.update' : 'server.create', null, true, $saved['name'] . ' · ' . $saved['host']);
        Session::flash('success', 'Servidor «' . $saved['name'] . '» guardado');
        $this->app->redirect($this->app->url('servers'));
    }

    public function delete(): void
    {
        $this->requirePost();
        $server = $this->server();
        $this->app->servers()->delete($server['id']);
        $this->app->activity()->log($this->user()->username, 'server.delete', null, true, $server['name']);
        Session::flash('success', 'Servidor «' . $server['name'] . '» eliminado');
        $this->app->redirect($this->app->url('servers'));
    }

    /** POST JSON: prueba el FTP guardado (o el del formulario sin guardar). */
    public function test(): void
    {
        $this->requirePost();
        $server = $this->str('id') !== '' ? $this->server() : null;
        $probe = [
            'host' => strtolower($this->str('host', (string) ($server['host'] ?? ''))),
            'port' => (int) $this->str('port', (string) ($server['port'] ?? 21)),
            'user' => $this->str('user', (string) ($server['user'] ?? '')),
            'ftps' => isset($_POST['ftps']) ? $_POST['ftps'] === '1' : !empty($server['ftps']),
            'verify_ssl' => isset($_POST['verify_ssl']) ? $_POST['verify_ssl'] === '1' : !empty($server['verify_ssl']),
        ];
        $password = (string) ($_POST['password'] ?? '');
        try {
            $ftp = $password !== '' || $server === null ? new Ftp($probe, $password, 20) : $this->app->servers()->ftp($probe + ['password' => $server['password']], 20);
            $list = $ftp->list($this->str('base', (string) ($server['base'] ?? '/')));
            $this->app->json(['ok' => true, 'dirs' => count($list['dirs']), 'files' => count($list['files'])]);
        } catch (\Throwable $e) {
            $this->app->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    /** GET JSON: subcarpetas de una carpeta del servidor (explorador del formulario de instalación). */
    public function browse(): void
    {
        $server = $this->server();
        try {
            $path = Ftp::normalize($this->str('path', (string) $server['base']));
            $list = $this->app->servers()->ftp($server, 20)->list($path);
            $this->app->json(['ok' => true, 'path' => $path, 'parent' => $path === '/' ? null : dirname($path), 'dirs' => $list['dirs'], 'files' => count($list['files'])]);
        } catch (\Throwable $e) {
            $this->app->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    // ---------------------------------------------------------------- plantilla de configuración

    public function template(): void
    {
        $app = $this->provisionableApp();
        $this->renderTemplate($app, $this->app->appTemplates()->get($app), []);
    }

    public function templateSave(): void
    {
        $this->requirePost();
        $app = $this->provisionableApp();
        $errors = $this->app->appTemplates()->save($app, $_POST);
        if ($errors) {
            $this->renderTemplate($app, array_merge($this->app->appTemplates()->get($app), array_map(static fn ($v) => is_string($v) ? $v : '', $_POST)), $errors, 422);
            return;
        }
        $this->app->activity()->log($this->user()->username, 'template.update', null, true, $this->app->apps()->name($app));
        Session::flash('success', 'Plantilla de ' . $this->app->apps()->name($app) . ' guardada');
        $this->app->redirect($this->app->url('servers'));
    }

    private function renderTemplate(string $app, array $values, array $errors, int $status = 200): void
    {
        $name = $this->app->apps()->name($app);
        $this->render('app_template', [
            'title' => 'Plantilla de ' . $name,
            'icon' => 'settings',
            'breadcrumbs' => [['Servidores', $this->app->url('servers')], ['Plantilla de ' . $name, null]],
            'appId' => $app,
            'values' => $values,
            'errors' => $errors,
            'hasPassword' => (string) ($this->app->appTemplates()->get($app)['mail_password'] ?? '') !== '',
        ], 'layout', $status);
    }

    // ---------------------------------------------------------------- instalación nueva

    public function provision(): void
    {
        $this->renderProvision(['server' => $this->str('server'), 'app' => $this->str('app', 'quasar')], []);
    }

    public function provisionRun(): void
    {
        $this->requirePost();
        $provisioner = new Provisioner($this->app);
        [$data, $errors] = $provisioner->validate($_POST);
        if ($errors) {
            $values = array_map(static fn ($v) => is_string($v) ? $v : '', $_POST);
            unset($values['db_pass'], $values['ai_key']);
            $this->renderProvision($values + ['demo' => !empty($_POST['demo'])], $errors, 422);
            return;
        }
        $this->longTask();
        $result = $provisioner->run($data, $this->user()->username);
        $this->render('provision_result', [
            'title' => $result['ok'] ? 'Instalación creada' : 'La instalación no se ha completado',
            'icon' => $result['ok'] ? 'rocket' : 'triangle-alert',
            'breadcrumbs' => [['Servidores', $this->app->url('servers')], ['Nueva instalación', $this->app->url('provision')], [$data['name'], null]],
            'result' => $result,
            'data' => $data,
        ]);
    }

    private function renderProvision(array $values, array $errors, int $status = 200): void
    {
        $versions = [];
        $githubError = null;
        try {
            $versions = array_values(array_filter(
                array_column($this->app->apps()->github('quasar')->versions(), 'version'),
                static fn ($v) => version_compare($v, AppTemplates::PROVISION_MIN['quasar'], '>=')
            ));
        } catch (\RuntimeException $e) {
            $githubError = $e->getMessage();
        }
        $this->render('provision', [
            'title' => 'Nueva instalación',
            'icon' => 'rocket',
            'breadcrumbs' => [['Servidores', $this->app->url('servers')], ['Nueva instalación', null]],
            'servers' => $this->app->servers()->all(),
            'apps' => array_intersect_key($this->app->apps()->all(), array_flip(AppTemplates::PROVISIONABLE)),
            'versions' => $versions,
            'githubError' => $githubError,
            'values' => $values,
            'errors' => $errors,
        ], 'layout', $status);
    }

    // ---------------------------------------------------------------- piezas

    private function server(): array
    {
        $id = $_POST['id'] ?? $_GET['id'] ?? $_GET['server'] ?? null;
        $server = is_string($id) && preg_match('/^[a-z0-9-]{1,64}$/', $id) ? $this->app->servers()->get($id) : null;
        return $server ?? throw new HttpException(404, 'Ese servidor no existe');
    }

    private function provisionableApp(): string
    {
        $app = $this->str('app', 'quasar');
        return in_array($app, AppTemplates::PROVISIONABLE, true) ? $app : throw new HttpException(404, 'Esa aplicación no se puede instalar desde Cosmo');
    }

    private function formChrome(?array $server): array
    {
        return [
            'title' => $server ? 'Editar ' . $server['name'] : 'Nuevo servidor',
            'icon' => $server ? 'pencil' : 'plus',
            'breadcrumbs' => [['Servidores', $this->app->url('servers')], [$server ? $server['name'] : 'Nuevo', null]],
        ];
    }
}
