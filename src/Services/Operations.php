<?php
declare(strict_types=1);

namespace Cosmo\Services;

/**
 * Operaciones de varios pasos sobre una instalación, compartidas por el panel y bin/cron.php:
 * copia (y descarga a Cosmo) y actualización completa.
 *
 * Cada una devuelve un informe con la lista de pasos (texto + ok) para enseñarlo tal cual.
 */
final class Operations
{
    public function __construct(
        private readonly Installations $installations,
        private readonly QuasarClient $client,
        private readonly Apps $apps,
        private readonly Monitor $monitor,
        private readonly Activity $activity,
        private readonly LocalBackups $local,
        private readonly Notifier $notifier,
    ) {
    }

    /** @return array{ok:bool,steps:list<array{ok:bool,text:string}>,backup:?array} */
    public function backup(array $inst, string $type, bool $pull, string $user, string $label = ''): array
    {
        $steps = [];
        $token = $this->installations->token($inst);
        $r = $this->client->post($inst, $token, 'backup', ['type' => $type, 'label' => $label]);
        if (!$r->ok) {
            $steps[] = ['ok' => false, 'text' => 'Copia: ' . $r->error];
            $this->activity->log($user, 'backup.create', $inst['id'], false, (string) $r->error, ['type' => $type]);
            $this->notifier->send('error', 'Falla la copia de ' . $inst['name'], (string) $r->error);
            return ['ok' => false, 'steps' => $steps, 'backup' => null];
        }
        $backup = $r->data['backup'];
        $typeName = mb_strtolower($this->apps->get($inst['app'])['backups'][$type] ?? $type);
        $steps[] = ['ok' => true, 'text' => sprintf('Copia (%s) creada: %s (%s s)', $typeName, self::bytes((int) $backup['size']), $backup['seconds'] ?? '?')];
        $this->activity->log($user, 'backup.create', $inst['id'], true, $backup['file'], ['type' => $type, 'size' => $backup['size']]);

        $ok = true;
        if ($pull) {
            $pulled = $this->pull($inst, $backup['file'], $user);
            $steps[] = $pulled;
            $ok = $pulled['ok'];
        }
        // El estado (fecha de la última copia) cambia
        $this->monitor->check([$inst['id']], $user);
        return ['ok' => $ok, 'steps' => $steps, 'backup' => $backup];
    }

    /** @return array{ok:bool,text:string} */
    public function pull(array $inst, string $file, string $user): array
    {
        if (!LocalBackups::validName($file)) {
            return ['ok' => false, 'text' => 'Nombre de copia no válido'];
        }
        $dest = $this->local->dirFor($inst['id']) . '/' . $file;
        if (is_file($dest)) {
            return ['ok' => true, 'text' => 'Ya estaba guardada en Cosmo'];
        }
        $r = $this->client->download($inst, $this->installations->token($inst), $file, $dest);
        $this->activity->log($user, 'backup.pull', $inst['id'], $r->ok, $r->ok ? $file : (string) $r->error);
        if (!$r->ok) {
            $this->notifier->send('error', 'No se pudo guardar en Cosmo la copia de ' . $inst['name'], (string) $r->error);
            return ['ok' => false, 'text' => 'Descarga a Cosmo: ' . $r->error];
        }
        $removed = $this->local->prune($inst['id']);
        return ['ok' => true, 'text' => 'Guardada en Cosmo (' . self::bytes((int) $r->data['size']) . ', sha256 verificado)'
            . ($removed ? " · $removed copias locales antiguas borradas" : '')];
    }

    /**
     * Actualización completa: paquete de GitHub → subida (con copia previa en la instalación)
     * → migración → comprobación final.
     * @return array{ok:bool,steps:list<array{ok:bool,text:string}>,from:?string,to:string}
     */
    public function update(array $inst, string $version, string $user, bool $backup = true, bool $force = false, bool $allowDowngrade = false): array
    {
        $steps = [];
        $done = function (bool $ok, ?string $from) use (&$steps, $inst, $version, $user): array {
            $detail = implode(' · ', array_map(static fn ($s) => $s['text'], array_filter($steps, static fn ($s) => !$s['ok']))) ?: ($from . ' → ' . $version);
            $this->activity->log($user, 'update', $inst['id'], $ok, $detail, ['from' => $from, 'to' => $version]);
            $this->notifier->send($ok ? 'success' : 'error', ($ok ? 'Actualizada ' : 'Falla la actualización de ') . $inst['name'] . " a $version", $ok ? '' : $detail);
            return ['ok' => $ok, 'steps' => $steps, 'from' => $from, 'to' => $version];
        };

        $app = $this->apps->get($inst['app']);
        $token = $this->installations->token($inst);
        $status = $this->client->get($inst, $token, 'status');
        if (!$status->ok) {
            $steps[] = ['ok' => false, 'text' => 'Estado: ' . $status->error];
            return $done(false, null);
        }
        $d = $status->data;
        $from = (string) ($d['version'] ?? '0.0.0');
        if ((int) ($d['api'] ?? 0) < 1) {
            $steps[] = ['ok' => false, 'text' => "La versión $from no tiene API remota: actualízala a mano la primera vez"];
            return $done(false, $from);
        }
        if (isset($d['app']) && $d['app'] !== $app['id']) {
            $steps[] = ['ok' => false, 'text' => 'Responde como ' . $this->apps->name((string) $d['app']) . ', pero está dada de alta como ' . $app['name'] . ': corrige la aplicación en la ficha'];
            return $done(false, $from);
        }
        if (version_compare($version, $from, '==')) {
            $steps[] = ['ok' => false, 'text' => "Ya está en la versión $version"];
            return $done(false, $from);
        }
        if (version_compare($version, $from, '<') && !$allowDowngrade) {
            $steps[] = ['ok' => false, 'text' => "La $version es anterior a la instalada ($from): marca «permitir volver atrás» si es lo que quieres"];
            return $done(false, $from);
        }
        if (version_compare($version, $app['min_version'], '<')) {
            $steps[] = ['ok' => false, 'text' => "Las versiones de {$app['name']} anteriores a la {$app['min_version']} no tienen API remota: la instalación quedaría fuera de Cosmo"];
            return $done(false, $from);
        }
        $steps[] = ['ok' => true, 'text' => "Instalada: {$app['name']} $from · PHP " . ($d['php'] ?? '?')];

        try {
            $pkg = $this->apps->github($app['id'])->package($version);
        } catch (\RuntimeException $e) {
            $steps[] = ['ok' => false, 'text' => 'Paquete: ' . $e->getMessage()];
            return $done(false, $from);
        }
        $steps[] = ['ok' => true, 'text' => 'Paquete ' . $version . ' (' . self::bytes($pkg['size']) . ')'];

        $limit = min(self::iniBytes((string) ($d['server']['upload_max_filesize'] ?? '2M')), self::iniBytes((string) ($d['server']['post_max_size'] ?? '8M')));
        if ($limit > 0 && $pkg['size'] > $limit) {
            $steps[] = ['ok' => false, 'text' => 'El paquete pesa más que el límite de subida del hosting (upload_max_filesize / post_max_size = ' . self::bytes($limit) . ')'];
            return $done(false, $from);
        }

        $r = $this->client->post($inst, $token, 'update', [
            'package' => new \CURLFile($pkg['path'], 'application/zip', basename($pkg['path'])),
            'sha256' => $pkg['sha256'],
            'version' => $version,
            'backup' => $backup ? '1' : '0',
            'force' => $force ? '1' : '0',
        ]);
        if (!$r->ok) {
            $text = 'Instalación: ' . $r->error;
            if (!empty($r->data['data']['modified'])) {
                $text .= ' — ' . implode(', ', array_slice($r->data['data']['modified'], 0, 10))
                    . (count($r->data['data']['modified']) > 10 ? '…' : '') . '. Marca «sobrescribir cambios locales» para continuar.';
            }
            $steps[] = ['ok' => false, 'text' => $text];
            $this->monitor->check([$inst['id']], $user);
            return $done(false, $from);
        }
        if ($backup && !empty($r->data['backup'])) {
            $steps[] = ['ok' => true, 'text' => 'Copia previa: ' . $r->data['backup']['file'] . ' (' . self::bytes((int) $r->data['backup']['size']) . ')'];
        }
        $rep = $r->data['report'];
        $steps[] = ['ok' => true, 'text' => sprintf('Ficheros: %d copiados, %d borrados · autoprueba: %s', $rep['copied'], $rep['deleted'], $rep['self_test'] ?? '—')];
        if (!empty($rep['overwritten_modified'])) {
            $steps[] = ['ok' => true, 'text' => 'Cambios locales sobrescritos: ' . implode(', ', $rep['overwritten_modified'])];
        }

        $m = $this->client->post($inst, $token, 'migrate');
        if (!$m->ok) {
            $steps[] = ['ok' => false, 'text' => 'Migración: ' . $m->error . ' (sigue en mantenimiento; reintenta desde la ficha)'];
            $this->monitor->check([$inst['id']], $user);
            return $done(false, $from);
        }
        $steps[] = ['ok' => true, 'text' => 'Migración: ' . ($m->data['steps'] ? implode(', ', $m->data['steps']) : 'al día') . ' · mantenimiento desactivado'];

        $after = $this->monitor->check([$inst['id']], $user)[$inst['id']] ?? null;
        $now = $after['data']['version'] ?? null;
        $ok = $now === $version;
        $steps[] = ['ok' => $ok, 'text' => $ok ? "Comprobado: responde con la versión $version" : 'La comprobación final no confirma la versión (' . ($after['error'] ?? $now ?? '?') . ')'];
        return $done($ok, $from);
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $n = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private static function bytes(int $b): string
    {
        return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024) . ' KB';
    }
}
