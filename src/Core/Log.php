<?php
declare(strict_types=1);

namespace Cosmo\Core;

/**
 * Log mínimo: storage/logs/cosmo-AAAA-MM-DD.log (30 días).
 * Las acciones de los usuarios van aparte, en el registro de actividad (Services\Activity).
 */
final class Log
{
    private static string $dir = '';
    private static bool $debug = false;

    public static function configure(string $dir, bool $debug): void
    {
        self::$dir = $dir;
        self::$debug = $debug;
        set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            // Lo obsoleto (p. ej. al subir de versión de PHP) solo se anota: no debe tumbar una página
            if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED) {
                self::write('WARNING', "PHP obsoleto: $msg en $file:$line", []);
                return true;
            }
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        if (self::$debug) {
            self::write('DEBUG', $message, $context);
        }
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (self::$dir === '') {
            return;
        }
        $exception = $context['exception'] ?? null;
        unset($context['exception']);
        $line = sprintf('[%s] %s %s', date('Y-m-d H:i:s'), $level, str_replace(["\r", "\n"], ' ', $message));
        if ($context) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        if ($exception instanceof \Throwable) {
            $line .= "\n  " . get_class($exception) . ': ' . $exception->getMessage() . ' en ' . $exception->getFile() . ':' . $exception->getLine()
                . "\n  " . str_replace("\n", "\n  ", $exception->getTraceAsString());
        }
        $file = self::$dir . '/cosmo-' . date('Y-m-d') . '.log';
        $isNew = !is_file($file);
        if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log('[cosmo] ' . $line);
            return;
        }
        if ($isNew) {
            $old = glob(self::$dir . '/cosmo-????-??-??.log') ?: [];
            sort($old);
            foreach (array_slice($old, 0, max(0, count($old) - 30)) as $f) {
                @unlink($f);
            }
        }
    }
}
