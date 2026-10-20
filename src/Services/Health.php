<?php
declare(strict_types=1);

namespace Cosmo\Services;

/**
 * Avisos sobre una instalación a partir de su último estado.
 * Nivel: danger (hay que actuar ya), warning (revisar), info.
 */
final class Health
{
    /** Fin del soporte de seguridad de cada rama de PHP (php.net/supported-versions) */
    private const PHP_EOL_DATES = [
        '7.4' => '2022-11-28', '8.0' => '2023-11-26', '8.1' => '2025-12-31',
        '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31',
    ];

    /** @return list<array{level:string,text:string}> */
    public static function warnings(?array $snapshot, ?string $latest, array $monitor): array
    {
        $w = [];
        $add = static function (string $level, string $text) use (&$w): void {
            $w[] = ['level' => $level, 'text' => $text];
        };

        if ($snapshot === null) {
            $add('info', 'Todavía no se ha comprobado');
            return $w;
        }
        if (!$snapshot['ok']) {
            $add('danger', 'No responde: ' . ($snapshot['error'] ?? 'error desconocido'));
        }
        $d = Monitor::data($snapshot);
        if ($d === null) {
            return $w;
        }

        if (!isset($d['api'])) {
            $add('warning', 'Versión sin API remota: actualízala a mano a la 0.2.0 o superior para poder hacer copias y actualizaciones desde Cosmo');
        }
        $version = (string) ($d['version'] ?? '0.0.0');
        if ($latest !== null && version_compare($version, $latest, '<')) {
            $major = (int) explode('.', $version)[0] !== (int) explode('.', $latest)[0];
            $add($major ? 'warning' : 'info', "Hay una versión nueva: $latest" . ($major ? ' (cambio de versión mayor: revisa el CHANGELOG antes)' : ''));
        }
        if (!empty($d['maintenance'])) {
            $add('danger', 'En modo mantenimiento desde ' . self::date($d['maintenance']['since'] ?? null)
                . (($d['maintenance']['reason'] ?? '') !== '' ? ' (' . $d['maintenance']['reason'] . ')' : '') . ': los usuarios no pueden entrar');
        }
        if (isset($d['db']['ok']) && !$d['db']['ok']) {
            $add('danger', 'La base de datos no responde' . (!empty($d['db']['error']) ? ': ' . $d['db']['error'] : ''));
        }
        if (!empty($d['config']['debug'])) {
            $add('danger', 'debug activado: muestra errores internos a los usuarios');
        }
        if (isset($d['config']['https']) && !$d['config']['https']) {
            $add('warning', 'No se sirve por HTTPS: el token y las sesiones viajan sin cifrar');
        }
        if (($d['config']['mail_transport'] ?? null) === 'log') {
            $add('warning', 'El correo está en modo log: no se envía ningún email (tampoco los códigos de verificación)');
        }

        $errors = (int) ($d['logs']['error'] ?? 0);
        if ($errors > 0) {
            $add('warning', "$errors errores en el log en las últimas 24 h");
        }
        if (!empty($d['integrity']['modified']) || !empty($d['integrity']['missing'])) {
            $add('warning', sprintf('Ficheros del núcleo modificados a mano: %d cambiados, %d que faltan (se perderán al actualizar)',
                $d['integrity']['modified'] ?? 0, $d['integrity']['missing'] ?? 0));
        }

        if (isset($d['backups'])) {
            $last = $d['backups']['last']['created_at'] ?? null;
            $days = (int) ($monitor['stale_backup_days'] ?? 7);
            if ($last === null) {
                $add('warning', 'No tiene ninguna copia de seguridad');
            } elseif (strtotime($last) < time() - $days * 86400) {
                $add('warning', 'La última copia tiene más de ' . $days . ' días (' . self::date($last) . ')');
            }
        }

        $free = $d['server']['disk_free'] ?? null;
        $total = $d['server']['disk_total'] ?? null;
        $minFree = (int) ($monitor['disk_min_free'] ?? 1073741824);
        if (is_int($free) && ($free < $minFree || (is_int($total) && $total > 0 && $free / $total < 0.05))) {
            $add('warning', 'Poco espacio libre en disco: ' . round($free / 1048576) . ' MB');
        }
        if (isset($d['server']['writable']) && !$d['server']['writable']) {
            $add('warning', 'PHP no puede escribir en src/ o public/: las actualizaciones remotas fallarán');
        }
        foreach (['zip' => 'copias y actualizaciones', 'pdo_mysql' => 'base de datos', 'fileinfo' => 'subida de archivos'] as $ext => $for) {
            if (isset($d['extensions'][$ext]) && !$d['extensions'][$ext]) {
                $add('danger', "Falta la extensión PHP $ext ($for)");
            }
        }

        $branch = implode('.', array_slice(explode('.', (string) ($d['php'] ?? '')), 0, 2));
        if (isset(self::PHP_EOL_DATES[$branch])) {
            $eol = strtotime(self::PHP_EOL_DATES[$branch]);
            if ($eol < time()) {
                $add('warning', "PHP $branch ya no recibe parches de seguridad (desde " . self::date(self::PHP_EOL_DATES[$branch]) . ')');
            } elseif ($eol < time() + 90 * 86400) {
                $add('info', "PHP $branch deja de tener soporte el " . self::date(self::PHP_EOL_DATES[$branch]));
            }
        }

        $remoteTime = isset($d['time']) ? strtotime((string) $d['time']) : false;
        $checked = strtotime((string) $snapshot['checked_at']);
        if ($snapshot['ok'] && $remoteTime !== false && abs($remoteTime - $checked) > 300) {
            $add('info', 'El reloj del servidor va desfasado ' . round(abs($remoteTime - $checked) / 60) . ' min');
        }
        return $w;
    }

    /** danger > warning > info > ok */
    public static function worst(array $warnings): string
    {
        $levels = array_column($warnings, 'level');
        foreach (['danger', 'warning', 'info'] as $l) {
            if (in_array($l, $levels, true)) {
                return $l;
            }
        }
        return 'ok';
    }

    private static function date(?string $date): string
    {
        $ts = $date ? strtotime($date) : false;
        return $ts === false ? '?' : date('d/m/Y H:i', $ts);
    }
}
