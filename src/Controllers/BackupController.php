<?php
declare(strict_types=1);

namespace Cosmo\Controllers;

use Cosmo\Core\HttpException;
use Cosmo\Services\LocalBackups;

final class BackupController extends Controller
{
    /** GET JSON: copias en la instalación y en Cosmo */
    public function list(): void
    {
        $inst = $this->installation();
        $r = $this->app->client()->get($inst, $this->app->installations()->token($inst), 'backups');
        $local = $this->app->localBackups()->list($inst['id']);
        $this->app->json([
            'ok' => true,
            'remote' => $r->ok ? $r->data['backups'] : null,
            'remote_error' => $r->ok ? null : $r->error,
            'disk_free' => $r->data['disk_free'] ?? null,
            'local' => $local,
            'local_size' => array_sum(array_column($local, 'size')),
        ]);
    }

    /** POST JSON: type (uno de los 'backups' de su aplicación), pull=1 para guardarla también en Cosmo */
    public function create(): void
    {
        $this->requirePost();
        $inst = $this->installation();
        $app = $this->app->apps()->get($inst['app']);
        $type = $this->str('type', 'full');
        if (!isset($app['backups'][$type])) {
            throw new HttpException(400, $app['name'] . ' no tiene copias de tipo ' . $type);
        }
        $user = $this->user();
        $this->longTask();
        $result = $this->app->operations()->backup($inst, $type, $this->str('pull') === '1', $user->username, 'Manual · ' . $user->name);
        $this->app->json($result, $result['ok'] ? 200 : 502);
    }

    public function delete(): void
    {
        $this->requirePost();
        $inst = $this->installation();
        $file = $this->backupName();
        $r = $this->app->client()->post($inst, $this->app->installations()->token($inst), 'backup-delete', ['file' => $file], false);
        $this->app->activity()->log($this->user()->username, 'backup.delete', $inst['id'], $r->ok, $r->ok ? $file : (string) $r->error);
        if (!$r->ok) {
            throw new HttpException(502, (string) $r->error);
        }
        $this->app->monitor()->check([$inst['id']], $this->user()->username);
        $this->app->json(['ok' => true]);
    }

    public function pull(): void
    {
        $this->requirePost();
        $inst = $this->installation();
        $file = $this->backupName();
        $this->longTask();
        $step = $this->app->operations()->pull($inst, $file, $this->user()->username);
        $this->app->json(['ok' => $step['ok'], 'message' => $step['text'], 'error' => $step['ok'] ? null : $step['text']], $step['ok'] ? 200 : 502);
    }

    /** GET: la copia pasa de la instalación al navegador sin quedarse en Cosmo */
    public function download(): void
    {
        $inst = $this->installation();
        $file = $this->backupName();
        $this->longTask();
        $this->app->activity()->log($this->user()->username, 'backup.download', $inst['id'], true, $file);
        $error = $this->app->client()->stream($inst, $this->app->installations()->token($inst), $file);
        if ($error !== null) {
            throw new HttpException(502, 'No se puede descargar: ' . $error);
        }
    }

    public function localDownload(): void
    {
        $inst = $this->installation();
        $path = $this->app->localBackups()->path($inst['id'], $this->backupName());
        if ($path === null) {
            throw new HttpException(404);
        }
        $this->app->activity()->log($this->user()->username, 'backup.download', $inst['id'], true, basename($path) . ' (desde Cosmo)');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $inst['id'] . '-' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
    }

    public function localDelete(): void
    {
        $this->requirePost();
        $inst = $this->installation();
        $file = $this->backupName();
        $ok = $this->app->localBackups()->delete($inst['id'], $file);
        $this->app->activity()->log($this->user()->username, 'backup.local-delete', $inst['id'], $ok, $file);
        $this->app->json(['ok' => $ok], $ok ? 200 : 404);
    }

    private function backupName(): string
    {
        $file = $this->str('file');
        if (!LocalBackups::validName($file)) {
            throw new HttpException(400, 'Nombre de copia no válido');
        }
        return $file;
    }
}
