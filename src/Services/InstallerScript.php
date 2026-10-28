<?php
declare(strict_types=1);

namespace Cosmo\Services;

/**
 * Instalador de un solo uso que Cosmo sube por FTP a la carpeta del dominio junto con el paquete (ZIP de
 * GitHub). Cosmo lo llama por HTTPS con la clave, el paquete y los parámetros; el instalador comprueba la
 * clave (solo guarda su sha256) y la caducidad, descomprime el paquete, hace require de bin/cosmo-setup.php
 * de la aplicación (que escribe la configuración, crea las tablas y el administrador) y se borra.
 * Si algo falla, deshace lo descomprimido para poder repetir la instalación.
 */
final class InstallerScript
{
    public static function render(string $secretHash, int $expires): string
    {
        return strtr(self::SOURCE, [
            '{{DATE}}' => date('Y-m-d H:i'),
            '{{HASH}}' => $secretHash,
            '{{EXPIRES}}' => (string) $expires,
            '{{EXPIRES_TEXT}}' => date('Y-m-d H:i', $expires),
        ]);
    }

    private const SOURCE = <<<'PHP'
<?php
// Instalador de un solo uso generado por Cosmo el {{DATE}}. Caduca el {{EXPIRES_TEXT}} y se borra al terminar.
declare(strict_types=1);

const COSMO_SECRET = '{{HASH}}';
const COSMO_EXPIRES = {{EXPIRES}};

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
@set_time_limit(300);
ignore_user_abort(true);

$dir = __DIR__;
$created = [];
$steps = [];
$respond = static function (int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
// Crea una carpeta y anota cada nivel nuevo (para deshacerlo si algo falla)
$makeDir = static function (string $path) use (&$created): bool {
    $missing = [];
    for ($p = $path; !is_dir($p) && $p !== dirname($p); $p = dirname($p)) {
        $missing[] = $p;
    }
    if ($missing === []) {
        return true;
    }
    if (!@mkdir($path, 0755, true)) {
        return false;
    }
    foreach (array_reverse($missing) as $p) {
        $created[] = $p;
    }
    return true;
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
};
$selfDestruct = static function (?string $zip): void {
    if ($zip !== null) {
        @unlink($zip);
    }
    @unlink(__FILE__);
};

if (time() > COSMO_EXPIRES) {
    $selfDestruct(null);
    $respond(410, ['ok' => false, 'error' => 'El instalador ha caducado']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals(COSMO_SECRET, hash('sha256', (string) ($_POST['secret'] ?? '')))) {
    $respond(403, ['ok' => false, 'error' => 'Acceso denegado']);
}
$package = (string) ($_POST['package'] ?? '');
if (!preg_match('/^cosmo-package-[a-f0-9]{16}\.zip$/', $package) || !is_file($dir . '/' . $package)) {
    $selfDestruct(null);
    $respond(400, ['ok' => false, 'error' => 'No se encuentra el paquete en el servidor']);
}
$zipFile = $dir . '/' . $package;

try {
    if (!hash_equals((string) ($_POST['sha256'] ?? ''), (string) hash_file('sha256', $zipFile))) {
        throw new RuntimeException('El paquete ha llegado incompleto o dañado (sha256 distinto)');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('El PHP del servidor no tiene la extensión zip');
    }
    foreach (['config', 'src', 'public', 'index.php'] as $existing) {
        if (file_exists($dir . '/' . $existing)) {
            throw new RuntimeException("La carpeta ya tiene una instalación ($existing)");
        }
    }
    $params = json_decode((string) ($_POST['params'] ?? ''), true, 32, JSON_THROW_ON_ERROR);

    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('El paquete no es un ZIP válido');
    }
    // GitHub mete todo en una carpeta "usuario-repo-commit/"
    $first = (string) $zip->getNameIndex(0);
    $prefix = str_contains($first, '/') ? substr($first, 0, strpos($first, '/') + 1) : '';
    $hostingHtaccess = is_file($dir . '/.htaccess') ? (string) file_get_contents($dir . '/.htaccess') : null;
    $count = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if ($prefix !== '' && !str_starts_with($name, $prefix)) {
            continue;
        }
        $rel = substr($name, strlen($prefix));
        if ($rel === '' || $rel === false) {
            continue;
        }
        if (str_contains($rel, "\0") || str_starts_with($rel, '/') || preg_match('~(^|/)\.\.(/|$)~', $rel)) {
            throw new RuntimeException("Ruta no válida en el paquete: $rel");
        }
        $target = $dir . '/' . $rel;
        if (str_ends_with($rel, '/')) {
            if (!$makeDir(rtrim($target, '/'))) {
                throw new RuntimeException("No se puede crear la carpeta $rel");
            }
            continue;
        }
        if (!$makeDir(dirname($target))) {
            throw new RuntimeException('No se puede crear la carpeta ' . dirname($rel));
        }
        if ($rel === '.htaccess' && $hostingHtaccess !== null) {
            continue; // se combina después
        }
        $in = $zip->getStream($name);
        $out = @fopen($target, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException("No se puede escribir $rel");
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $created[] = $target;
        $count++;
    }
    // El .htaccess del hosting (versión de PHP, etc.) se conserva delante del de la aplicación
    if ($hostingHtaccess !== null && ($ours = $zip->getFromName($prefix . '.htaccess')) !== false) {
        file_put_contents($dir . '/.htaccess', rtrim($hostingHtaccess) . "\n\n# --- Aplicación (añadido por Cosmo) ---\n" . $ours);
        $steps[] = '.htaccess del hosting conservado y combinado';
    }
    $zip->close();
    $steps[] = "$count ficheros descomprimidos";

    $setupFile = $dir . '/bin/cosmo-setup.php';
    $setup = is_file($setupFile) ? require $setupFile : null;
    if (!is_callable($setup)) {
        throw new RuntimeException('Esta versión de la aplicación no se puede instalar desde Cosmo (falta bin/cosmo-setup.php)');
    }
    $steps = [...$steps, ...$setup($params)];
    $version = trim((string) @file_get_contents($dir . '/VERSION'));
    $selfDestruct($zipFile);
    $respond(200, ['ok' => true, 'version' => $version, 'steps' => $steps]);
} catch (Throwable $e) {
    // Deshace lo descomprimido: las carpetas creadas, enteras (no existían: lo de dentro es de esta instalación)
    foreach (array_reverse($created) as $path) {
        $removeTree($path);
    }
    if (isset($hostingHtaccess) && $hostingHtaccess !== null) {
        @file_put_contents($dir . '/.htaccess', $hostingHtaccess);
    }
    $selfDestruct($zipFile);
    $respond(500, ['ok' => false, 'error' => $e->getMessage(), 'steps' => $steps]);
}
PHP;
}
