<?php
declare(strict_types=1);

namespace Cosmo\Core;

use Cosmo\Controllers\ActivityController;
use Cosmo\Controllers\AuthController;
use Cosmo\Controllers\BackupController;
use Cosmo\Controllers\DashboardController;
use Cosmo\Controllers\InstallationController;
use Cosmo\Controllers\ReleaseController;
use Cosmo\Controllers\ServerController;
use Cosmo\Controllers\UpdateController;
use Cosmo\Services\Activity;
use Cosmo\Services\Apps;
use Cosmo\Services\AppTemplates;
use Cosmo\Services\Installations;
use Cosmo\Services\LocalBackups;
use Cosmo\Services\Monitor;
use Cosmo\Services\Notifier;
use Cosmo\Services\Operations;
use Cosmo\Services\QuasarClient;
use Cosmo\Services\Servers;

final class App
{
    /** ruta => [controlador, método, permiso (null = público)] */
    private const ROUTES = [
        'login' => [AuthController::class, 'login', null],
        'logout' => [AuthController::class, 'logout', null],

        'home' => [DashboardController::class, 'index', 'view'],
        'check' => [DashboardController::class, 'check', 'operate'],

        'install' => [InstallationController::class, 'show', 'view'],
        'install-edit' => [InstallationController::class, 'edit', 'manage'],
        'install-save' => [InstallationController::class, 'save', 'manage'],
        'install-delete' => [InstallationController::class, 'delete', 'manage'],
        'install-test' => [InstallationController::class, 'test', 'manage'],
        'install-logs' => [InstallationController::class, 'logs', 'view'],
        'install-integrity' => [InstallationController::class, 'integrity', 'view'],
        'maintenance' => [InstallationController::class, 'maintenance', 'operate'],

        'backups' => [BackupController::class, 'list', 'view'],
        'backup-create' => [BackupController::class, 'create', 'operate'],
        'backup-delete' => [BackupController::class, 'delete', 'operate'],
        'backup-pull' => [BackupController::class, 'pull', 'operate'],
        'backup-download' => [BackupController::class, 'download', 'operate'],
        'local-download' => [BackupController::class, 'localDownload', 'operate'],
        'local-delete' => [BackupController::class, 'localDelete', 'operate'],

        'update' => [UpdateController::class, 'update', 'operate'],
        'migrate' => [UpdateController::class, 'migrate', 'operate'],

        'releases' => [ReleaseController::class, 'index', 'view'],
        'releases-refresh' => [ReleaseController::class, 'refresh', 'operate'],

        'activity' => [ActivityController::class, 'index', 'view'],

        // Servidores e instalaciones nuevas por FTP
        'servers' => [ServerController::class, 'index', 'manage'],
        'server-edit' => [ServerController::class, 'edit', 'manage'],
        'server-save' => [ServerController::class, 'save', 'manage'],
        'server-delete' => [ServerController::class, 'delete', 'manage'],
        'server-test' => [ServerController::class, 'test', 'manage'],
        'server-browse' => [ServerController::class, 'browse', 'manage'],
        'provision' => [ServerController::class, 'provision', 'manage'],
        'provision-run' => [ServerController::class, 'provisionRun', 'manage'],
        'app-template' => [ServerController::class, 'template', 'manage'],
        'app-template-save' => [ServerController::class, 'templateSave', 'manage'],
    ];

    private const ASSET_TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
    ];

    public readonly Store $store;
    public readonly Auth $auth;
    public readonly View $view;
    public string $route = 'home';

    private ?Installations $installations = null;
    private ?QuasarClient $client = null;
    private ?Apps $apps = null;
    private ?Activity $activity = null;
    private ?Monitor $monitor = null;
    private ?Notifier $notifier = null;

    public function __construct(public readonly array $config)
    {
        error_reporting(E_ALL);
        ini_set('display_errors', !empty($config['debug']) ? '1' : '0');
        $this->store = new Store($this->storageDir('data'));
        $this->auth = new Auth($config, $this->store);
        $this->view = new View($this);
    }

    public function run(): void
    {
        $route = is_string($_GET['r'] ?? null) ? $_GET['r'] : 'home';
        if ($route === 'asset') {
            $this->serveAsset(is_string($_GET['f'] ?? null) ? $_GET['f'] : '');
            return;
        }

        $this->securityHeaders();
        Session::start($this->config);

        try {
            if (!isset(self::ROUTES[$route])) {
                throw new HttpException(404);
            }
            $this->route = $route;
            [$class, $method, $permission] = self::ROUTES[$route];
            if ($permission !== null) {
                $user = $this->auth->user();
                if ($user === null) {
                    if ($this->wantsJson()) {
                        throw new HttpException(401);
                    }
                    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                        Session::set('intended', (string) ($_SERVER['QUERY_STRING'] ?? ''));
                    }
                    $this->redirect($this->url('login'));
                }
                if (!$user->can($permission)) {
                    throw new HttpException(403);
                }
            }
            (new $class($this))->$method();
        } catch (HttpException $e) {
            $this->renderError($e->status, $e->getMessage());
        } catch (ConfigException $e) {
            Log::error('Configuración: ' . $e->getMessage());
            $this->renderError(500, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Excepción no controlada: ' . $e->getMessage(), ['url' => $_SERVER['REQUEST_URI'] ?? null, 'exception' => $e]);
            $this->renderError(500, !empty($this->config['debug']) ? $e->getMessage() : 'Se ha producido un error interno', $e);
        }
    }

    // ------------------------------------------------------------------ servicios

    public function installations(): Installations
    {
        return $this->installations ??= new Installations($this->store, new Crypto($this->config['key'] ?? null), $this->apps());
    }

    public function servers(): Servers
    {
        return new Servers($this->store, new Crypto($this->config['key'] ?? null));
    }

    public function appTemplates(): AppTemplates
    {
        return new AppTemplates($this->store, new Crypto($this->config['key'] ?? null));
    }

    public function client(): QuasarClient
    {
        return $this->client ??= new QuasarClient((array) ($this->config['http'] ?? []), $this->version());
    }

    public function apps(): Apps
    {
        if ($this->apps === null) {
            $apps = (array) ($this->config['apps'] ?? []);
            // Configuraciones de antes de haber varias aplicaciones: github.repo era el de Quasar
            if (!empty($this->config['github']['repo']) && isset($apps['quasar'])) {
                $apps['quasar']['repo'] = $this->config['github']['repo'];
            }
            $this->apps = new Apps($apps, (array) ($this->config['github'] ?? []), $this->storageDir('cache'), $this->storageDir('packages'));
        }
        return $this->apps;
    }

    public function activity(): Activity
    {
        return $this->activity ??= new Activity($this->store);
    }

    public function monitor(): Monitor
    {
        return $this->monitor ??= new Monitor(
            $this->store,
            $this->installations(),
            $this->client(),
            $this->activity(),
            $this->notifier(),
            (array) ($this->config['monitor'] ?? []),
        );
    }

    public function notifier(): Notifier
    {
        return $this->notifier ??= new Notifier((array) ($this->config['notify'] ?? []), (string) ($this->config['app_name'] ?? 'Cosmo'));
    }

    public function localBackups(): LocalBackups
    {
        return new LocalBackups($this->storageDir('backups'), (int) ($this->config['backups']['local_keep'] ?? 10));
    }

    public function operations(): Operations
    {
        return new Operations(
            $this->installations(),
            $this->client(),
            $this->apps(),
            $this->monitor(),
            $this->activity(),
            $this->localBackups(),
            $this->notifier(),
        );
    }

    public function storageDir(string $sub): string
    {
        $dir = rtrim((string) ($this->config['storage_dir'] ?? CS_ROOT . '/storage'), '/') . '/' . $sub;
        Store::ensureDir($dir);
        return $dir;
    }

    public function version(): string
    {
        $v = is_file(CS_ROOT . '/VERSION') ? trim((string) file_get_contents(CS_ROOT . '/VERSION')) : '';
        return $v !== '' ? $v : '0.0.0';
    }

    // ------------------------------------------------------------------ HTTP

    public function url(string $route, array $params = []): string
    {
        return 'index.php?' . http_build_query(['r' => $route] + $params);
    }

    public function redirect(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    public function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || isset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    public function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    public function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private function renderError(int $status, string $message, ?\Throwable $e = null): void
    {
        if ($this->wantsJson()) {
            $this->json(['ok' => false, 'error' => $message], $status);
            return;
        }
        http_response_code($status);
        try {
            $user = $status === 500 ? null : $this->auth->user();
            echo $this->view->render('error', [
                'title' => 'Error ' . $status,
                'status' => $status,
                'message' => $message,
                'debug' => !empty($this->config['debug']) && $e !== null ? (string) $e : null,
            ], $user ? 'layout' : 'blank');
        } catch (\Throwable $inner) {
            Log::error('Fallo al mostrar la página de error', ['exception' => $inner]);
            header('Content-Type: text/plain; charset=utf-8');
            echo $status . ' ' . $message;
        }
    }

    private function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    private function serveAsset(string $file): void
    {
        $base = realpath(CS_ROOT . '/templates/assets');
        $path = $base ? realpath($base . '/' . $file) : false;
        $ext = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
        if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path) || !isset(self::ASSET_TYPES[$ext])) {
            http_response_code(404);
            return;
        }
        $etag = '"' . md5($path . filemtime($path) . filesize($path)) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=604800');
        header('X-Content-Type-Options: nosniff');
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }
        header('Content-Type: ' . self::ASSET_TYPES[$ext]);
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }
}
