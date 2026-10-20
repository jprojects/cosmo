<?php
declare(strict_types=1);

namespace Cosmo\Controllers;

use Cosmo\Core\HttpException;

final class UpdateController extends Controller
{
    /** POST JSON: version, backup=1, force=0, downgrade=0 */
    public function update(): void
    {
        $this->requirePost();
        $inst = $this->installation();
        $version = $this->str('version');
        if ($version === 'latest') {
            $version = (string) $this->latestVersion();
        }
        if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version)) {
            throw new HttpException(400, 'Versión no válida');
        }
        $user = $this->user()->username;
        $this->longTask();
        $result = $this->app->operations()->update(
            $inst,
            $version,
            $user,
            $this->str('backup', '1') === '1',
            $this->str('force') === '1',
            $this->str('downgrade') === '1',
        );
        $this->app->json($result + ['name' => $inst['name']], $result['ok'] ? 200 : 502);
    }

    /** POST JSON: reintenta la migración (p. ej. si falló tras actualizar y quedó en mantenimiento) */
    public function migrate(): void
    {
        $this->requirePost();
        $inst = $this->installation();
        $this->longTask();
        $r = $this->app->client()->post($inst, $this->app->installations()->token($inst), 'migrate');
        $this->app->activity()->log($this->user()->username, 'migrate', $inst['id'], $r->ok, $r->ok ? implode(', ', $r->data['steps'] ?? []) : (string) $r->error);
        $this->app->monitor()->check([$inst['id']], $this->user()->username);
        if (!$r->ok) {
            throw new HttpException(502, (string) $r->error);
        }
        $this->app->json(['ok' => true, 'steps' => $r->data['steps'] ?? []]);
    }
}
