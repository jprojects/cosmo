#!/usr/bin/env php
<?php
/**
 * Primera instalación: crea config/config.local.php con la clave de cifrado y el primer usuario.
 *
 *   php bin/setup.php
 *   php bin/setup.php --user=kim --name="Kim" --email=kim@example.com --password=… --github-token=…
 *
 * Si config.local.php ya existe no lo toca: solo comprueba el entorno.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/src/Core/Crypto.php';

function ask(string $question, bool $hidden = false): string
{
    echo $question;
    $tty = $hidden && function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $value = trim((string) fgets(STDIN));
    if ($tty) {
        shell_exec('stty echo');
        echo PHP_EOL;
    }
    return $value;
}

echo "Cosmo · comprobación del entorno\n";
$ok = true;
foreach (['curl' => 'hablar con las instalaciones y GitHub', 'sodium' => 'cifrar los tokens', 'zip' => 'comprobar los paquetes', 'mbstring' => 'textos', 'json' => 'datos'] as $ext => $why) {
    $loaded = extension_loaded($ext);
    $ok = $ok && $loaded;
    printf("  %s %-9s %s\n", $loaded ? '✓' : '✗', $ext, $loaded ? '' : "(falta: necesaria para $why)");
}
if (PHP_VERSION_ID < 80100) {
    echo '  ✗ PHP ' . PHP_VERSION . " (hace falta 8.1 o superior)\n";
    $ok = false;
}
foreach (['data', 'backups', 'packages', 'cache', 'logs'] as $dir) {
    $path = "$root/storage/$dir";
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    if (!is_writable($path)) {
        echo "  ✗ storage/$dir no tiene permiso de escritura\n";
        $ok = false;
    }
}
if (!$ok) {
    exit(1);
}

$local = "$root/config/config.local.php";
if (is_file($local)) {
    echo "\nconfig/config.local.php ya existe: no se modifica.\n";
    echo "Para añadir usuarios: php bin/hash-password.php y edita 'users' en ese fichero.\n";
    exit(0);
}

$opts = getopt('', ['user:', 'name:', 'email:', 'password:', 'github-token:']);
echo "\nPrimer usuario (administrador)\n";
$user = (string) ($opts['user'] ?? '') ?: (ask('  Usuario [admin]: ') ?: 'admin');
if (!preg_match('/^[a-z0-9._-]{2,32}$/i', $user)) {
    fwrite(STDERR, "Usuario no válido (letras, números, . _ -)\n");
    exit(1);
}
$name = (string) ($opts['name'] ?? '') ?: (ask("  Nombre [$user]: ") ?: $user);
$email = (string) ($opts['email'] ?? '') ?: ask('  Email (opcional): ');
$password = (string) ($opts['password'] ?? '');
while (mb_strlen($password) < 10) {
    $password = ask('  Contraseña (mín. 10 caracteres): ', true);
}
$github = (string) ($opts['github-token'] ?? '');
if (!isset($opts['github-token'])) {
    $github = ask('  Token de GitHub para leer el repositorio de Quasar (Enter para ponerlo luego): ');
}

$config = [
    'key' => Cosmo\Core\Crypto::generateKey(),
    'users' => [
        $user => array_filter([
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'admin',
        ], static fn ($v) => $v !== null),
    ],
    'github' => ['token' => $github !== '' ? $github : null],
];

/** var_export con corchetes y sangría de 4 espacios */
function export(mixed $value, int $depth = 0): string
{
    if (!is_array($value)) {
        return var_export($value, true);
    }
    $pad = str_repeat('    ', $depth + 1);
    $out = "[\n";
    foreach ($value as $k => $v) {
        $out .= $pad . var_export($k, true) . ' => ' . export($v, $depth + 1) . ",\n";
    }
    return $out . str_repeat('    ', $depth) . ']';
}

$php = "<?php\n// Configuración local de Cosmo: NO versionar (contiene la clave de cifrado de los tokens).\n"
    . "// Usuarios: password = php bin/hash-password.php · role = admin | operator | viewer\n"
    . 'return ' . export($config) . ";\n";
if (file_put_contents($local, $php) === false) {
    fwrite(STDERR, "No se puede escribir $local\n");
    exit(1);
}
@chmod($local, 0640);
echo "\n  ✓ config/config.local.php creado (guarda una copia: sin la clave hay que volver a meter los tokens)\n";
echo "\nArranca en local con:  php -S localhost:8080 -t public\n";
echo "Y programa el cron:    */10 * * * * php $root/bin/cron.php\n";
