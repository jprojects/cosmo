<?php
declare(strict_types=1);

namespace Cosmo\Services;

/**
 * FTP (o FTPS explícito) con cURL, sin la extensión ftp: listar una carpeta, subir y borrar ficheros.
 * Las rutas son las del servidor FTP ("/" = la carpeta en la que entra el usuario).
 */
final class Ftp
{
    /**
     * @param array{host:string,port:int,user:string,ftps:bool,verify_ssl:bool} $server
     */
    public function __construct(
        private readonly array $server,
        private readonly string $password,
        private readonly int $timeout = 60,
    ) {
    }

    /**
     * Contenido de una carpeta.
     * @return array{dirs:list<string>,files:list<string>}
     */
    public function list(string $dir): array
    {
        $raw = $this->run(self::normalize($dir) . '/', []);
        $dirs = $files = [];
        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            $entry = self::parseLine($line);
            if ($entry === null || in_array($entry['name'], ['.', '..'], true)) {
                continue;
            }
            if ($entry['dir']) {
                $dirs[] = $entry['name'];
            } else {
                $files[] = $entry['name'];
            }
        }
        natcasesort($dirs);
        natcasesort($files);
        return ['dirs' => array_values($dirs), 'files' => array_values($files)];
    }

    /** Sube un fichero local a $remote (ruta completa con el nombre). */
    public function upload(string $local, string $remote): void
    {
        $fp = fopen($local, 'rb');
        if ($fp === false) {
            throw new \RuntimeException("No se puede leer $local");
        }
        try {
            $this->run(self::normalize($remote), [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $fp,
                CURLOPT_INFILESIZE => (int) filesize($local),
                CURLOPT_TIMEOUT => max($this->timeout, 900),
            ]);
        } finally {
            fclose($fp);
        }
    }

    /** Borra un fichero. */
    public function delete(string $remote): void
    {
        $remote = self::normalize($remote);
        $dir = dirname($remote);
        $this->run(($dir === '/' ? '' : $dir) . '/', [CURLOPT_QUOTE => ['DELE ' . basename($remote)], CURLOPT_NOBODY => true]);
    }

    /** "/a//b/../c/" → "/a/c"; rechaza rutas con ".." */
    public static function normalize(string $path): string
    {
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), static fn ($p) => $p !== '' && $p !== '.'));
        if (in_array('..', $parts, true)) {
            throw new \InvalidArgumentException('Ruta no válida');
        }
        return '/' . implode('/', $parts);
    }

    private function run(string $path, array $options): string
    {
        $url = sprintf('ftp://%s:%d%s', $this->server['host'], (int) $this->server['port'], implode('/', array_map('rawurlencode', explode('/', $path))));
        $ch = curl_init($url);
        curl_setopt_array($ch, $options + [
            CURLOPT_USERPWD => $this->server['user'] . ':' . $this->password,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_FTP,
            CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
            CURLOPT_USE_SSL => !empty($this->server['ftps']) ? CURLUSESSL_ALL : CURLUSESSL_NONE,
            CURLOPT_SSL_VERIFYPEER => !empty($this->server['verify_ssl']),
            CURLOPT_SSL_VERIFYHOST => !empty($this->server['verify_ssl']) ? 2 : 0,
        ]);
        $out = curl_exec($ch);
        if ($out === false) {
            $error = curl_error($ch);
            $code = curl_errno($ch);
            throw new \RuntimeException(match ($code) {
                67 => 'FTP: usuario o contraseña incorrectos', // CURLE_LOGIN_DENIED (PHP no la define)
                CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST, CURLE_OPERATION_TIMEDOUT => 'FTP: no se puede conectar con ' . $this->server['host'] . " ($error)",
                CURLE_FTP_SSL_FAILED => 'FTP: el servidor no admite FTPS; desactívalo en el servidor',
                default => "FTP: $error",
            });
        }
        return is_string($out) ? $out : '';
    }

    /**
     * Una línea de LIST: formato Unix ("drwxr-xr-x 2 u g 4096 Oct 28 10:00 nombre") o Windows
     * ("10-28-26  10:00AM  <DIR>  nombre").
     * @return array{name:string,dir:bool}|null
     */
    private static function parseLine(string $line): ?array
    {
        if (preg_match('/^([dlsbcp-])[rwxsStT-]{9}\S*\s+\d+\s+\S+\s+\S+\s+\d+\s+\w{3}\s+\d{1,2}\s+[\d:]{4,5}\s+(.+)$/', $line, $m)) {
            $name = $m[1] === 'l' ? explode(' -> ', $m[2])[0] : $m[2];
            return ['name' => $name, 'dir' => $m[1] === 'd'];
        }
        if (preg_match('/^\d{2}-\d{2}-\d{2,4}\s+\d{1,2}:\d{2}[AP]M\s+(<DIR>|\d+)\s+(.+)$/i', $line, $m)) {
            return ['name' => $m[2], 'dir' => strtoupper($m[1]) === '<DIR>'];
        }
        return null;
    }
}
