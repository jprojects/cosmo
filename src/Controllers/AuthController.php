<?php
declare(strict_types=1);

namespace Cosmo\Controllers;

use Cosmo\Core\Session;

final class AuthController extends Controller
{
    public function login(): void
    {
        if ($this->app->auth->user() !== null) {
            $this->app->redirect($this->app->url('home'));
        }
        $error = null;
        $username = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $username = mb_substr($this->str('username'), 0, 64);
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            if (!Session::checkCsrf($_POST['_csrf'] ?? null)) {
                $error = 'El formulario ha caducado. Vuelve a intentarlo.';
            } elseif ($username === '' || $password === '') {
                $error = 'Escribe el usuario y la contraseña';
            } else {
                $intended = (string) Session::get('intended', '');
                $error = $this->app->auth->attempt($username, $password, $this->app->clientIp());
                if ($error === null) {
                    $this->app->activity()->log($username, 'login', null);
                    // Solo rutas internas (index.php?…)
                    $this->app->redirect($intended !== '' && preg_match('/^r=[a-z-]+(&|$)/', $intended) ? 'index.php?' . $intended : $this->app->url('home'));
                }
            }
        }
        $this->render('login', [
            'title' => 'Iniciar sesión',
            'error' => $error,
            'username' => $username,
            'noUsers' => empty($this->app->config['users']),
        ], 'blank', $error !== null ? 401 : 200);
    }

    public function logout(): void
    {
        $this->requirePost();
        $user = $this->app->auth->user();
        if ($user !== null) {
            $this->app->activity()->log($user->username, 'logout', null);
        }
        $this->app->auth->logout();
        $this->app->redirect($this->app->url('login'));
    }
}
