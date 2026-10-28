<?php
declare(strict_types=1);

namespace Cosmo\Services;

use Cosmo\Core\App;

/**
 * Instalación nueva de una aplicación en un servidor (Servidores → Nueva instalación).
 *
 * El dominio y la base de datos se crean antes en el panel del hosting. Cosmo comprueba que la carpeta del
 * dominio está vacía y que el dominio responde por HTTPS, sube por FTP el paquete de la versión y un
 * instalador de un solo uso, lo llama por HTTPS (descomprime, escribe la configuración, crea las tablas y el
 * administrador y se borra), da de alta la instalación en el panel y hace la primera copia.
 */
final class Provisioner
{
    /** Lo que puede haber en la carpeta del dominio antes de instalar (lo deja el hosting) */
    private const ALLOWED = ['index.html', 'index.htm', 'default.html', 'favicon.ico', '.htaccess', '.user.ini', 'php.ini', 'cgi-bin', '.well-known'];
    private const INSTALLER_TTL = 900;

    /** @var list<array{ok:bool,text:string}> */
    private array $steps = [];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Valida el formulario.
     * @return array{0:?array,1:array<string,string>} [datos limpios, errores por campo]
     */
    public function validate(array $in): array
    {
        $errors = [];
        $server = $this->app->servers()->get((string) ($in['server'] ?? ''));
        if ($server === null) {
            $errors['server'] = 'Elige el servidor';
        }
        $app = (string) ($in['app'] ?? '');
        if (!in_array($app, AppTemplates::PROVISIONABLE, true) || !$this->app->apps()->has($app)) {
            $errors['app'] = 'Esta aplicación todavía no se puede instalar desde Cosmo';
        }
        $url = Installations::normalizeUrl((string) ($in['url'] ?? ''));
        // Solo HTTPS (viajan las contraseñas), salvo un servidor de pruebas en esta misma máquina
        if ($url === '' || (!str_starts_with($url, 'https://') && !preg_match('~^http://(127\.0\.0\.1|localhost)[:/]~', $url . '/'))) {
            $errors['url'] = 'URL con https:// del dominio. Ejemplo: https://gestion.cliente.com';
        }
        foreach ($this->app->installations()->all() as $other) {
            if ($url !== '' && $other['url'] === $url) {
                $errors['url'] = 'Ya hay una instalación con esta URL: ' . $other['name'];
            }
        }
        try {
            $folder = Ftp::normalize((string) ($in['folder'] ?? ''));
            if ($folder === '/') {
                $errors['folder'] = 'Elige la carpeta del dominio (no la raíz del FTP)';
            }
        } catch (\InvalidArgumentException) {
            $folder = '';
            $errors['folder'] = 'Carpeta no válida';
        }
        $data = [
            'server' => (string) ($in['server'] ?? ''),
            'app' => $app,
            'version' => (string) ($in['version'] ?? ''),
            'name' => trim((string) ($in['name'] ?? '')),
            'client' => trim((string) ($in['client'] ?? '')),
            'url' => $url,
            'folder' => $folder,
            'db_host' => trim((string) ($in['db_host'] ?? 'localhost')) ?: 'localhost',
            'db_port' => (int) ($in['db_port'] ?? 3306),
            'db_name' => trim((string) ($in['db_name'] ?? '')),
            'db_user' => trim((string) ($in['db_user'] ?? '')),
            'db_pass' => (string) ($in['db_pass'] ?? ''),
            'admin_user' => trim((string) ($in['admin_user'] ?? 'admin')) ?: 'admin',
            'admin_name' => trim((string) ($in['admin_name'] ?? '')) ?: 'Administrador',
            'admin_email' => trim((string) ($in['admin_email'] ?? '')),
            'ai_key' => trim((string) ($in['ai_key'] ?? '')),
            'demo' => !empty($in['demo']),
            'backup_schedule' => (string) ($in['backup_schedule'] ?? 'daily'),
        ];
        if ($data['name'] === '' || mb_strlen($data['name']) > 100) {
            $errors['name'] = 'Escribe un nombre (máximo 100 caracteres)';
        }
        if (!preg_match('/^\d+\.\d+\.\d+$/', $data['version'])) {
            $errors['version'] = 'Elige la versión';
        } elseif (isset(AppTemplates::PROVISION_MIN[$app]) && version_compare($data['version'], AppTemplates::PROVISION_MIN[$app], '<')) {
            $errors['version'] = 'Solo desde la versión ' . AppTemplates::PROVISION_MIN[$app] . ' (las anteriores no se pueden instalar desde Cosmo)';
        }
        if ($data['db_name'] === '' || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $data['db_name'])) {
            $errors['db_name'] = 'Nombre de la base de datos no válido';
        }
        if ($data['db_user'] === '') {
            $errors['db_user'] = 'Escribe el usuario de la base de datos';
        }
        if ($data['db_pass'] === '') {
            $errors['db_pass'] = 'Escribe la contraseña de la base de datos';
        }
        if (!preg_match('/^[A-Za-z0-9_.]{1,50}$/', $data['admin_user'])) {
            $errors['admin_user'] = 'Letras, números, punto y guion bajo';
        }
        if (filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['admin_email'] = 'Email no válido';
        }
        if (!isset(Installations::SCHEDULES[$data['backup_schedule']])) {
            $errors['backup_schedule'] = 'Periodicidad no válida';
        }
        return $errors ? [null, $errors] : [$data, []];
    }

    /**
     * Instala. Devuelve los pasos y, si ha ido bien, la instalación dada de alta y la contraseña del administrador.
     * @return array{ok:bool,steps:list<array{ok:bool,text:string}>,installation:?array,password:?string,error:?string}
     */
    public function run(array $d, string $user): array
    {
        $server = $this->app->servers()->get($d['server']);
        $ftp = $this->app->servers()->ftp($server);
        $uploaded = [];
        try {
            // 1. Comprobaciones antes de tocar nada
            $listing = $ftp->list($d['folder']);
            $extra = array_values(array_diff([...$listing['dirs'], ...$listing['files']], self::ALLOWED));
            if ($extra) {
                throw new \RuntimeException('La carpeta ' . $d['folder'] . ' no está vacía: ' . implode(', ', array_slice($extra, 0, 8)) . (count($extra) > 8 ? '…' : ''));
            }
            $this->ok('Carpeta ' . $d['folder'] . ' vacía');
            $this->checkHttps($d['url']);
            $this->ok('El dominio responde por HTTPS con un certificado válido');
            $package = $this->app->apps()->github($d['app'])->package($d['version']);
            $this->ok(sprintf('Paquete de %s %s (%s)', $this->app->apps()->name($d['app']), $d['version'], self::size($package['size'])));

            // 2. Subida del paquete y del instalador de un solo uso
            $suffix = bin2hex(random_bytes(8));
            $secret = bin2hex(random_bytes(32));
            $installerName = 'cosmo-install-' . $suffix . '.php';
            $packageName = 'cosmo-package-' . $suffix . '.zip';
            $tmp = tempnam(sys_get_temp_dir(), 'cosmo');
            file_put_contents($tmp, InstallerScript::render(hash('sha256', $secret), time() + self::INSTALLER_TTL));
            try {
                // Primero el instalador (pequeño): si la URL no lo sirve, la carpeta no es la del dominio
                $ftp->upload($tmp, $d['folder'] . '/' . $installerName);
                $uploaded[] = $d['folder'] . '/' . $installerName;
            } finally {
                @unlink($tmp);
            }
            $this->checkFolderServed($d['url'] . '/' . $installerName, $d['folder']);
            $this->ok('La carpeta ' . $d['folder'] . ' es la que sirve ' . $d['url'] . ' (y ejecuta PHP)');
            $ftp->upload($package['path'], $d['folder'] . '/' . $packageName);
            $uploaded[] = $d['folder'] . '/' . $packageName;
            $this->ok('Paquete subido por FTP');

            // 3. Instalación en el servidor
            $token = bin2hex(random_bytes(32));
            $password = self::password();
            $result = $this->callInstaller($d['url'] . '/' . $installerName, [
                'secret' => $secret,
                'package' => $packageName,
                'sha256' => $package['sha256'],
                'params' => json_encode([
                    'db' => ['host' => $d['db_host'], 'port' => $d['db_port'], 'name' => $d['db_name'], 'user' => $d['db_user'], 'pass' => $d['db_pass']],
                    'base_url' => $d['url'],
                    'token' => $token,
                    'config' => $this->app->appTemplates()->quasarConfig(['ai_key' => $d['ai_key'], 'app_name' => $d['name']]),
                    'admin' => ['username' => $d['admin_user'], 'password' => $password, 'name' => $d['admin_name'], 'email' => $d['admin_email']],
                    'demo' => $d['demo'],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $uploaded = []; // el instalador ya se ha borrado (y el paquete)
            foreach ((array) ($result['steps'] ?? []) as $step) {
                $this->ok('En el servidor: ' . $step);
            }
        } catch (\Throwable $e) {
            foreach ($uploaded as $file) {
                try {
                    $ftp->delete($file);
                } catch (\Throwable) {
                    // si no se puede borrar, caduca solo
                }
            }
            $this->fail($e->getMessage());
            $this->app->activity()->log($user, 'install.provision', null, false, $d['name'] . ' · ' . $d['url'] . ' · ' . $e->getMessage());
            return ['ok' => false, 'steps' => $this->steps, 'installation' => null, 'password' => null, 'error' => $e->getMessage()];
        }

        // 4. Alta en el panel, comprobación y primera copia
        [$inst, $errors] = $this->app->installations()->save(null, [
            'name' => $d['name'], 'app' => $d['app'], 'url' => $d['url'], 'token' => $token, 'client' => $d['client'],
            'environment' => 'production', 'verify_ssl' => '1', 'backup_schedule' => $d['backup_schedule'], 'backup_type' => 'full',
            'backup_hour' => 3, 'backup_pull' => '1', 'notes' => 'Instalada desde Cosmo el ' . date('d/m/Y') . ' en ' . $server['name'] . ':' . $d['folder'],
        ]);
        if ($inst === null) {
            $this->fail('Instalada, pero no se ha podido dar de alta en el panel: ' . implode(' · ', $errors) . '. Dala de alta a mano con el token ' . $token);
            return ['ok' => false, 'steps' => $this->steps, 'installation' => null, 'password' => $password, 'error' => 'alta'];
        }
        $this->ok('Dada de alta en el panel');
        $this->app->activity()->log($user, 'install.provision', $inst['id'], true, $inst['name'] . ' · ' . $inst['url'] . ' · ' . $d['version']);
        $snap = $this->app->monitor()->check([$inst['id']], $user)[$inst['id']] ?? null;
        $snap && $snap['ok']
            ? $this->ok('Responde con la versión ' . ($snap['data']['version'] ?? '?'))
            : $this->fail('No responde a la comprobación: ' . ($snap['error'] ?? 'sin respuesta'));
        if ($snap && $snap['ok']) {
            foreach ($this->app->operations()->backup($inst, 'full', true, $user, 'Primera copia')['steps'] as $step) {
                $this->steps[] = $step;
            }
        }
        return ['ok' => true, 'steps' => $this->steps, 'installation' => $inst, 'password' => $password, 'error' => null];
    }

    private function checkHttps(string $url): void
    {
        $ch = curl_init($url . '/');
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Cosmo',
        ]);
        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        if ($ok === false) {
            throw new \RuntimeException(match ($errno) {
                CURLE_COULDNT_RESOLVE_HOST => 'El dominio no existe todavía en el DNS: espera a que se propague',
                CURLE_SSL_CACERT, CURLE_SSL_CONNECT_ERROR => 'El certificado SSL del dominio no es válido todavía: actívalo en el panel del hosting',
                default => "El dominio no responde por HTTPS: $error",
            });
        }
    }

    /**
     * GET al instalador recién subido: sin POST responde 403 con JSON. Un 404 (o HTML, o el PHP en texto) quiere
     * decir que la carpeta elegida no es la raíz del dominio o que el dominio no ejecuta PHP.
     */
    private function checkFolderServed(string $url, string $folder): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Cosmo',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $json = json_decode($body, true);
        if ($code === 403 && is_array($json) && ($json['ok'] ?? null) === false) {
            return;
        }
        if (str_contains($body, 'COSMO_SECRET')) {
            throw new \RuntimeException('El dominio sirve ' . $folder . ' pero no ejecuta PHP: revisa la versión de PHP del dominio en el panel del hosting');
        }
        throw new \RuntimeException('La carpeta ' . $folder . ' no es la que sirve ' . preg_replace('~/[^/]+$~', '', $url)
            . " (HTTP $code al pedir el instalador). Elige el directorio raíz del dominio que muestra el panel del hosting");
    }

    /** POST al instalador; devuelve su JSON o lanza el error que da. */
    private function callInstaller(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Cosmo',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $body === false ? curl_error($ch) : null;
        if ($error !== null) {
            throw new \RuntimeException("No se puede llamar al instalador: $error");
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            throw new \RuntimeException($code === 404
                ? 'El instalador no responde en ' . $url . ': ¿es esta la carpeta del dominio?'
                : "El instalador ha respondido HTTP $code sin JSON: " . mb_substr(trim(strip_tags((string) $body)), 0, 300));
        }
        if (empty($json['ok'])) {
            // Lo que llegó a hacer antes de fallar (ya deshecho por el instalador)
            foreach ((array) ($json['steps'] ?? []) as $step) {
                $this->ok('En el servidor: ' . $step);
            }
            throw new \RuntimeException('Instalación: ' . ($json['error'] ?? "HTTP $code"));
        }
        return $json;
    }

    /** Contraseña legible de 16 caracteres (sin 0/O, 1/l…). */
    private static function password(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }

    private function ok(string $text): void
    {
        $this->steps[] = ['ok' => true, 'text' => $text];
    }

    private function fail(string $text): void
    {
        $this->steps[] = ['ok' => false, 'text' => $text];
    }
}
