<?php
declare(strict_types=1);

namespace Cosmo\Controllers;

use Cosmo\Core\HttpException;
use Cosmo\Core\Log;
use Cosmo\Core\User;
use Cosmo\Services\Monitor;

/**
 * API con token para el MCP (bin/mcp.php): ?r=api&a=…, con «Authorization: Bearer …» (o X-Cosmo-Token).
 * El token es de un usuario de config 'users' ('api_token' = su sha256, bin/api-token.php) y tiene sus permisos.
 * Sin sesión ni CSRF; los POST llevan JSON. Las respuestas: JSON con ok, o {ok:false,error} y el código HTTP.
 *
 *   GET  me                                    usuario del token
 *   GET  installations [app] [check] [refresh] instalaciones con versión y última versión de su aplicación
 *   GET  releases app [refresh]                versiones publicadas, CHANGELOG e instalaciones de cada versión
 *   POST check {ids?}                          comprueba el estado ahora (operate)
 *   POST update {id, version|latest, backup, force}   actualización completa, como en el panel (operate)
 */
final class ApiController extends Controller
{
    private const ACTIONS = [
        'me' => ['GET', 'view'],
        'installations' => ['GET', 'view'],
        'releases' => ['GET', 'view'],
        'check' => ['POST', 'operate'],
        'update' => ['POST', 'operate'],
    ];

    private ?User $apiUser = null;

    public function handle(): void
    {
        try {
            $this->apiUser = $this->app->auth->tokenUser(self::token());
            if ($this->apiUser === null) {
                Log::warning('API: token no válido', ['ip' => $this->app->clientIp()]);
                throw new HttpException(401, 'Token no válido (bin/api-token.php)');
            }
            $action = is_string($_GET['a'] ?? null) ? $_GET['a'] : '';
            [$method, $permission] = self::ACTIONS[$action] ?? throw new HttpException(404, 'Acción desconocida');
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
                throw new HttpException(405, "Esta acción va por $method");
            }
            if (!$this->apiUser->can($permission)) {
                throw new HttpException(403, 'Tu rol (' . $this->apiUser->roleLabel() . ') no lo permite');
            }
            if ($method === 'POST') {
                $body = json_decode((string) file_get_contents('php://input'), true);
                // Controller::str() e installation() leen de $_POST
                $_POST = is_array($body) ? array_map(static fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v, $body) : [];
            }
            $this->$action();
        } catch (HttpException $e) {
            $this->app->json(['ok' => false, 'error' => $e->getMessage() ?: 'Error ' . $e->status], $e->status);
        } catch (\Throwable $e) {
            Log::error('API: ' . $e->getMessage(), ['action' => $_GET['a'] ?? null, 'exception' => $e]);
            $this->app->json(['ok' => false, 'error' => !empty($this->app->config['debug']) ? $e->getMessage() : 'Error interno de Cosmo'], 500);
        }
    }

    protected function user(): User
    {
        return $this->apiUser ?? throw new HttpException(401);
    }

    private function me(): void
    {
        $u = $this->user();
        $this->app->json(['ok' => true, 'user' => $u->username, 'name' => $u->name, 'role' => $u->role, 'cosmo' => $this->app->version()]);
    }

    private function installations(): void
    {
        $appFilter = $this->str('app');
        if ($appFilter !== '' && !$this->app->apps()->has($appFilter)) {
            throw new HttpException(404, 'Esa aplicación no existe: ' . implode(', ', array_keys($this->app->apps()->all())));
        }
        $installations = array_filter(
            $this->app->installations()->all(),
            static fn (array $i) => $appFilter === '' || $i['app'] === $appFilter,
        );
        if ($this->str('check') === '1' && $installations) {
            if (!$this->user()->can('operate')) {
                throw new HttpException(403, 'Comprobar requiere el rol de operador');
            }
            @set_time_limit(0);
            $this->app->monitor()->check(array_keys($installations), $this->user()->username);
        }

        $latest = $errors = [];
        foreach (array_unique(array_column($installations, 'app')) as $appId) {
            [$latest[$appId], $error] = $this->latest($appId, $this->str('refresh') === '1');
            if ($error !== null) {
                $errors[$appId] = $error;
            }
        }

        $snapshots = $this->app->monitor()->snapshots();
        $rows = [];
        foreach ($installations as $id => $inst) {
            $snap = $snapshots[$id] ?? null;
            $version = Monitor::data($snap)['version'] ?? null;
            $last = $latest[$inst['app']] ?? null;
            $rows[] = [
                'id' => $id,
                'name' => $inst['name'],
                'url' => $inst['url'],
                'app' => $inst['app'],
                'version' => $version,
                'latest' => $last,
                'outdated' => $version !== null && $last !== null && version_compare($version, $last, '<'),
                'online' => $snap['ok'] ?? null,
                'maintenance' => (bool) (Monitor::data($snap)['maintenance'] ?? false),
                'checked_at' => $snap['checked_at'] ?? null,
                'error' => $snap['error'] ?? null,
            ];
        }
        $this->app->json(['ok' => true, 'latest' => $latest, 'github_errors' => $errors, 'installations' => $rows]);
    }

    private function releases(): void
    {
        $appId = $this->str('app');
        if (!$this->app->apps()->has($appId)) {
            throw new HttpException(404, 'Esa aplicación no existe: ' . implode(', ', array_keys($this->app->apps()->all())));
        }
        $github = $this->app->apps()->github($appId);
        $refresh = $this->str('refresh') === '1';
        try {
            $versions = $github->versions($refresh);
            $changelog = $github->changelog($refresh);
        } catch (\RuntimeException $e) {
            throw new HttpException(502, 'GitHub: ' . $e->getMessage());
        }
        $byVersion = [];
        $snapshots = $this->app->monitor()->snapshots();
        foreach ($this->app->installations()->all() as $id => $inst) {
            $v = Monitor::data($snapshots[$id] ?? null)['version'] ?? null;
            if ($inst['app'] === $appId && $v !== null) {
                $byVersion[$v][] = $id;
            }
        }
        $this->app->json([
            'ok' => true,
            'app' => $appId,
            'repo' => $github->repo(),
            'latest' => $github->latest(),
            'versions' => array_slice(array_column($versions, 'version'), 0, 20),
            'changelog' => array_slice($changelog, 0, 5, true),
            'installations_by_version' => $byVersion,
        ]);
    }

    private function check(): void
    {
        $ids = $_POST['ids'] ?? null;
        $ids = is_array($ids) ? array_values(array_filter($ids, 'is_string')) : null;
        @set_time_limit(0);
        $snapshots = $this->app->monitor()->check($ids, $this->user()->username);
        $this->app->activity()->log($this->user()->username, 'check', count($snapshots) === 1 ? (string) array_key_first($snapshots) : null, true, count($snapshots) . ' instalaciones (MCP)');
        $out = [];
        foreach ($snapshots as $id => $s) {
            $out[$id] = ['online' => $s['ok'], 'version' => Monitor::data($s)['version'] ?? null, 'error' => $s['error']];
        }
        $this->app->json(['ok' => true, 'installations' => $out]);
    }

    private function update(): void
    {
        $inst = $this->installation();
        $version = $this->str('version', 'latest');
        if ($version === 'latest' || $version === '') {
            // Recién publicada la etiqueta, la caché de 10 minutos aún no la tendría
            [$latest, $error] = $this->latest($inst['app'], true);
            if ($latest === null) {
                throw new HttpException(409, $error ?? 'No hay ninguna versión publicada de ' . $this->app->apps()->name($inst['app']));
            }
            $version = $latest;
        } else {
            $version = ltrim($version, 'v');
            try {
                // Que una etiqueta recién subida se encuentre
                $this->app->apps()->github($inst['app'])->versions(true);
            } catch (\RuntimeException) {
            }
        }
        if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version)) {
            throw new HttpException(400, 'Versión no válida');
        }
        $this->longTask();
        $result = $this->app->operations()->update(
            $inst,
            $version,
            $this->user()->username,
            $this->str('backup', '1') === '1',
            $this->str('force') === '1',
        );
        $this->app->json($result + ['id' => $inst['id'], 'name' => $inst['name']], $result['ok'] ? 200 : 502);
    }

    /** @return array{0:?string,1:?string} [última versión, error] */
    private function latest(string $appId, bool $refresh): array
    {
        try {
            $github = $this->app->apps()->github($appId);
            if ($refresh) {
                $github->versions(true);
                $github->changelog(true);
            }
            return [$github->latest(), null];
        } catch (\RuntimeException $e) {
            return [null, $e->getMessage()];
        }
    }

    private static function token(): string
    {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) {
            return $m[1];
        }
        return trim((string) ($_SERVER['HTTP_X_COSMO_TOKEN'] ?? ''));
    }
}
