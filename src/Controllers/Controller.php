<?php
declare(strict_types=1);

namespace Cosmo\Controllers;

use Cosmo\Core\App;
use Cosmo\Core\HttpException;
use Cosmo\Core\Session;
use Cosmo\Core\User;

abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    protected function user(): User
    {
        return $this->app->auth->user() ?? throw new HttpException(401);
    }

    protected function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new HttpException(405);
        }
        if (!Session::checkCsrf($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            throw new HttpException(419);
        }
    }

    /** Instalación de ?id= (o POST id). */
    protected function installation(): array
    {
        $id = $_POST['id'] ?? $_GET['id'] ?? null;
        $inst = is_string($id) && preg_match('/^[a-z0-9-]{1,64}$/', $id) ? $this->app->installations()->get($id) : null;
        return $inst ?? throw new HttpException(404, 'Esa instalación no existe');
    }

    protected function str(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    /** Última versión publicada de la aplicación, o null si GitHub no responde (el error va en $error). */
    protected function latestVersion(string $app, ?string &$error = null): ?string
    {
        try {
            return $this->app->apps()->github($app)->latest();
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
            return null;
        }
    }

    protected function render(string $template, array $vars = [], ?string $layout = 'layout', int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $this->app->view->render($template, $vars, $layout);
    }

    /** Tareas largas: no bloquear la sesión ni cortarse si el navegador se va. */
    protected function longTask(): void
    {
        Session::release();
        ignore_user_abort(true);
        @set_time_limit(0);
    }
}
