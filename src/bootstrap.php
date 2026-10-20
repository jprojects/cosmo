<?php
declare(strict_types=1);

define('CS_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Cosmo\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = CS_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$config = require CS_ROOT . '/config/config.php';
if (is_file(CS_ROOT . '/config/config.local.php')) {
    $local = require CS_ROOT . '/config/config.local.php';
    $config = array_replace_recursive($config, $local);
    // Los usuarios se sustituyen enteros (array_replace_recursive mezclaría los de los dos ficheros)
    if (isset($local['users'])) {
        $config['users'] = (array) $local['users'];
    }
}

date_default_timezone_set($config['timezone'] ?? 'UTC');
Cosmo\Core\Log::configure(rtrim((string) $config['storage_dir'], '/') . '/logs', !empty($config['debug']));

return $config;
