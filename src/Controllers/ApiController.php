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
 *
 * Guía de edición de una web Neutron (1.29.0+), para el MCP (cosmo_guia), que la regenera en el ordenador de quien lo usa:
 *   POST site-backup {id}                      copia del contenido (site/) en la instalación (operate)
 *   GET  backup-file id file                   la descarga, tal cual (ZIP) (operate)
 *   POST backup-delete {id, file}              la borra de la instalación (operate)
 *   POST guide-upload {id, pdf (base64), meta} pone la guía en la web (acción remota guide-set) (operate)
 */
final class ApiController extends Controller
{
    private const ACTIONS = [
        'me' => ['GET', 'view'],
        'installations' => ['GET', 'view'],
        'releases' => ['GET', 'view'],
        'check' => ['POST', 'operate'],
        'update' => ['POST', 'operate'],
        'site-backup' => ['POST', 'operate'],
        'backup-file' => ['GET', 'operate'],
        'backup-delete' => ['POST', 'operate'],
        'guide-upload' => ['POST', 'operate'],
    ];

    /** Primera versión de Neutron con guide-set y la huella de la guía */
    public const GUIDE_MIN_NEUTRON = '1.29.0';

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
            // site-backup → siteBackup()
            $this->{lcfirst(str_replace('-', '', ucwords($action, '-')))}();
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
                // Neutron 1.28.0+: la guía de edición ({…} o null); no está en las demás aplicaciones ni versiones
                'guide' => array_key_exists('guide', (array) (Monitor::data($snap)['site'] ?? [])) ? Monitor::data($snap)['site']['guide'] : false,
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

    /** Instalación Neutron que ya admite guide-set (si no, 409). */
    private function guideInstallation(): array
    {
        $inst = $this->installation();
        $version = Monitor::data($this->app->monitor()->snapshot($inst['id']))['version'] ?? null;
        if ($inst['app'] !== 'neutron' || $version === null || version_compare($version, self::GUIDE_MIN_NEUTRON, '<')) {
            throw new HttpException(409, $inst['name'] . ': hace falta Neutron ' . self::GUIDE_MIN_NEUTRON . ' o posterior (tiene '
                . ($inst['app'] === 'neutron' ? ($version ?? '?') : $inst['app']) . '); actualízala antes');
        }
        return $inst;
    }

    private function siteBackup(): void
    {
        $inst = $this->guideInstallation();
        $this->longTask();
        $r = $this->app->operations()->backup($inst, 'db', false, $this->user()->username, 'guía de edición (MCP)');
        $this->app->json(['ok' => $r['ok'], 'backup' => $r['backup'], 'steps' => $r['steps']], $r['ok'] ? 200 : 502);
    }

    private function backupFile(): void
    {
        $inst = $this->guideInstallation();
        $file = $this->str('file');
        if (!preg_match('/^backup-\d{8}-\d{6}-[a-z]+-[0-9a-f]{6}\.zip$/', $file)) {
            throw new HttpException(400, 'Nombre de copia no válido');
        }
        $this->longTask();
        $this->app->activity()->log($this->user()->username, 'backup.download', $inst['id'], true, $file . ' (MCP)');
        $error = $this->app->client()->stream($inst, $this->app->installations()->token($inst), $file);
        if ($error !== null) {
            throw new HttpException(502, 'No se puede descargar la copia: ' . $error);
        }
    }

    private function backupDelete(): void
    {
        $inst = $this->guideInstallation();
        $file = $this->str('file');
        $r = $this->app->client()->post($inst, $this->app->installations()->token($inst), 'backup-delete', ['file' => $file], false);
        $this->app->activity()->log($this->user()->username, 'backup.delete', $inst['id'], $r->ok, $file . ' (MCP)');
        $this->app->json(['ok' => $r->ok, 'error' => $r->error], $r->ok ? 200 : 502);
    }

    private function guideUpload(): void
    {
        $inst = $this->guideInstallation();
        $pdf = base64_decode((string) ($_POST['pdf'] ?? ''), true);
        if ($pdf === false || !str_starts_with($pdf, '%PDF-')) {
            throw new HttpException(400, 'pdf tiene que ser un PDF en base64');
        }
        $meta = is_array($_POST['meta'] ?? null) ? $_POST['meta'] : [];
        $tmp = tempnam(sys_get_temp_dir(), 'cosmo-guia-');
        file_put_contents($tmp, $pdf);
        try {
            $r = $this->app->client()->post($inst, $this->app->installations()->token($inst), 'guide-set', [
                'file' => new \CURLFile($tmp, 'application/pdf', 'guia-edicio.pdf'),
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ], false);
        } finally {
            @unlink($tmp);
        }
        $this->app->activity()->log($this->user()->username, 'guide.upload', $inst['id'], $r->ok, $r->ok ? strlen($pdf) . ' bytes' : (string) $r->error);
        if ($r->ok) {
            $this->app->monitor()->check([$inst['id']], $this->user()->username);   // el aviso de guía vieja se va
        }
        $this->app->json(['ok' => $r->ok, 'guide' => $r->data['guide'] ?? null, 'error' => $r->error], $r->ok ? 200 : 502);
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
