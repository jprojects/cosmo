#!/usr/bin/env php
<?php
/**
 * Hash para la clave 'password' de un usuario en config 'users'.
 *
 *   php bin/hash-password.php            (pregunta la contraseña sin mostrarla)
 *   php bin/hash-password.php 'secreto'  (cuidado: queda en el historial del shell)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$password = $argv[1] ?? '';
if ($password === '') {
    echo 'Contraseña (mín. 10 caracteres): ';
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $password = trim((string) fgets(STDIN));
    if ($tty) {
        shell_exec('stty echo');
        echo PHP_EOL;
    }
}
if (mb_strlen($password) < 10) {
    fwrite(STDERR, "La contraseña debe tener al menos 10 caracteres\n");
    exit(1);
}
echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
