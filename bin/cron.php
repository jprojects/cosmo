#!/usr/bin/env php
<?php
/**
 * Tareas periódicas: comprobar todas las instalaciones y hacer las copias programadas.
 *
 *   php bin/cron.php                 todo
 *   php bin/cron.php --check         solo comprobar
 *   php bin/cron.php --backups       solo copias programadas
 *   php bin/cron.php --quiet         sin salida salvo errores
 *
 * Crontab (cada 10 minutos):  *\/10 * * * * php /ruta/a/cosmo/bin/cron.php --quiet
 *
 * Copias: diaria = una al día a partir de 'backup_hour'; semanal = cada 7 días a partir de esa hora.
 * Si una copia falla se reintenta pasada una hora. Nunca se ejecutan dos cron a la vez.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Cosmo\Core\App;
use Cosmo\Core\Log;
use Cosmo\Services\Monitor;

$opts = getopt('', ['check', 'backups', 'quiet']);
$doCheck = isset($opts['check']) || !isset($opts['backups']);
$doBackups = isset($opts['backups']) || !isset($opts['check']);
$quiet = isset($opts['quiet']);
$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo '[' . date('H:i:s') . '] ' . $line . PHP_EOL;
    }
};

$app = new App($config);
$lock = fopen($app->storageDir('data') . '/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    $say('Otro cron sigue en marcha; salgo');
    exit(0);
}
@set_time_limit(0);

$exit = 0;
try {
    if ($doCheck) {
        $snapshots = $app->monitor()->check(null, 'cron');
        $down = array_keys(array_filter($snapshots, static fn ($s) => !$s['ok']));
        $say(sprintf('Comprobadas %d, no responden %d%s', count($snapshots), count($down), $down ? ': ' . implode(', ', $down) : ''));
    }

    if ($doBackups) {
        $state = $app->store->read('schedule.json');
        $now = time();
        foreach ($app->installations()->all() as $id => $inst) {
            if ($inst['backup_schedule'] === 'off') {
                continue;
            }
            $last = isset($state[$id]['last_ok']) ? strtotime($state[$id]['last_ok']) : 0;
            $attempt = isset($state[$id]['last_attempt']) ? strtotime($state[$id]['last_attempt']) : 0;
            $todayAt = strtotime(date('Y-m-d') . sprintf(' %02d:00:00', (int) $inst['backup_hour']));
            $due = match ($inst['backup_schedule']) {
                'daily' => $now >= $todayAt && $last < $todayAt,
                'weekly' => (int) date('G') >= (int) $inst['backup_hour'] && $last < $now - 7 * 86400 + 3600,
                default => false,
            };
            if (!$due || ($attempt > $now - 3600 && $attempt > $last)) {
                continue;
            }
            $snap = $app->monitor()->snapshot($id);
            if (!($snap['ok'] ?? false) || !isset(Monitor::data($snap)['api'])) {
                $say("$id: copia pendiente, pero no responde o no tiene API remota");
                continue;
            }

            $say("$id: copia {$inst['backup_type']}…");
            $result = $app->operations()->backup($inst, $inst['backup_type'], (bool) $inst['backup_pull'], 'cron', 'Programada');
            foreach ($result['steps'] as $step) {
                $say("  " . ($step['ok'] ? '✓' : '✗') . ' ' . $step['text']);
            }
            $state = $app->store->update('schedule.json', static function (array $all) use ($id, $result): array {
                $all[$id]['last_attempt'] = date(DATE_ATOM);
                if ($result['ok']) {
                    $all[$id]['last_ok'] = date(DATE_ATOM);
                }
                return $all;
            });
            if (!$result['ok']) {
                $exit = 2;
            }
        }
    }
} catch (\Throwable $e) {
    Log::error('Cron: ' . $e->getMessage(), ['exception' => $e]);
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    $exit = 1;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
exit($exit);
