<?php
declare(strict_types=1);

namespace Cosmo\Services;

/**
 * Versiones de una aplicación (Quasar, Neutron…) publicadas en GitHub: etiquetas vX.Y.Z, su CHANGELOG
 * y el ZIP de cada una. Las etiquetas y el CHANGELOG se guardan en caché ('cache_ttl'); los paquetes, para
 * siempre en storage/packages (una etiqueta publicada no cambia).
 */
final class GitHub
{
    private const API = 'https://api.github.com';

    public function __construct(
        private readonly array $config,
        private readonly string $app,
        private readonly string $repo,
        private readonly string $cacheDir,
        private readonly string $packagesDir,
    ) {
    }

    public function repo(): string
    {
        if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $this->repo)) {
            throw new \RuntimeException("apps.{$this->app}.repo no válido: '{$this->repo}' (formato propietario/repositorio)");
        }
        return $this->repo;
    }

    /**
     * Versiones publicadas, la más reciente primero.
     * @return list<array{version:string,tag:string,sha:string}>
     */
    public function versions(bool $refresh = false): array
    {
        return $this->cached('tags', $refresh, function (): array {
            $tags = [];
            for ($page = 1; $page <= 5; $page++) {
                $batch = $this->api('/repos/' . $this->repo() . '/tags?per_page=100&page=' . $page);
                foreach ($batch as $t) {
                    if (preg_match('/^v(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$/', (string) ($t['name'] ?? ''), $m)) {
                        $tags[] = ['version' => $m[1], 'tag' => $t['name'], 'sha' => (string) ($t['commit']['sha'] ?? '')];
                    }
                }
                if (count($batch) < 100) {
                    break;
                }
            }
            usort($tags, static fn (array $a, array $b) => version_compare($b['version'], $a['version']));
            return $tags;
        });
    }

    public function latest(): ?string
    {
        foreach ($this->versions() as $v) {
            // Las versiones previas (1.0.0-beta) no cuentan como "la última"
            if (!str_contains($v['version'], '-')) {
                return $v['version'];
            }
        }
        return null;
    }

    /**
     * CHANGELOG.md de la última etiqueta, por versión.
     * @return array<string,array{date:?string,body:string}>
     */
    public function changelog(bool $refresh = false): array
    {
        return $this->cached('changelog', $refresh, function (): array {
            $versions = $this->versions();
            if (!$versions) {
                return [];
            }
            $text = $this->request(self::API . '/repos/' . $this->repo() . '/contents/CHANGELOG.md?ref=' . rawurlencode($versions[0]['tag']), 'application/vnd.github.raw');
            return self::parseChangelog($text);
        });
    }

    /** @return array<string,array{date:?string,body:string}> */
    public static function parseChangelog(string $text): array
    {
        $out = [];
        $parts = preg_split('/^## +/m', $text) ?: [];
        array_shift($parts); // cabecera del fichero
        foreach ($parts as $part) {
            [$head, $body] = array_pad(explode("\n", $part, 2), 2, '');
            if (preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)\s*(?:[—–-]+\s*(\d{4}-\d{2}-\d{2}))?/u', trim($head), $m)) {
                $out[$m[1]] = ['date' => $m[2] ?? null, 'body' => trim($body)];
            }
        }
        return $out;
    }

    /**
     * Cambios entre dos versiones (excluida $from, incluida $to), la más reciente primero.
     * @return array<string,array{date:?string,body:string}>
     */
    public function changesBetween(string $from, string $to): array
    {
        return array_filter(
            $this->changelog(),
            static fn (string $v) => version_compare($v, $from, '>') && version_compare($v, $to, '<='),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * ZIP de una versión (descargado una vez y guardado).
     * @return array{path:string,sha256:string,size:int}
     */
    public function package(string $version): array
    {
        $tag = null;
        foreach ($this->versions() as $v) {
            if ($v['version'] === $version) {
                $tag = $v['tag'];
            }
        }
        if ($tag === null) {
            throw new \RuntimeException("La versión $version no está publicada en GitHub");
        }
        $file = $this->packagesDir . '/' . $this->app . '-' . $version . '.zip';
        if (!is_file($file)) {
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.part';
            try {
                $this->request(self::API . '/repos/' . $this->repo() . '/zipball/' . rawurlencode($tag), 'application/vnd.github+json', $tmp);
                $zip = new \ZipArchive();
                if ($zip->open($tmp, \ZipArchive::RDONLY) !== true) {
                    throw new \RuntimeException('GitHub no ha devuelto un ZIP válido');
                }
                $first = (string) $zip->getNameIndex(0);
                $root = str_contains($first, '/') ? substr($first, 0, strpos($first, '/') + 1) : '';
                $inside = trim((string) $zip->getFromName($root . 'VERSION'));
                $zip->close();
                if ($inside !== $version) {
                    throw new \RuntimeException("El paquete de $tag tiene VERSION '$inside': revisa que el fichero VERSION se actualizó antes de etiquetar");
                }
                rename($tmp, $file);
            } finally {
                @unlink($tmp);
            }
        }
        return ['path' => $file, 'sha256' => hash_file('sha256', $file), 'size' => (int) filesize($file)];
    }

    /** @return list<array{version:string,size:int}> paquetes descargados */
    public function cachedPackages(): array
    {
        $out = [];
        foreach (glob($this->packagesDir . '/' . $this->app . '-*.zip') ?: [] as $f) {
            if (preg_match('/^' . preg_quote($this->app, '/') . '-(\d.*)\.zip$/', basename($f), $m)) {
                $out[] = ['version' => $m[1], 'size' => (int) filesize($f)];
            }
        }
        usort($out, static fn (array $a, array $b) => version_compare($b['version'], $a['version']));
        return $out;
    }

    public function cacheAge(): ?int
    {
        $file = $this->cacheDir . '/github-' . $this->app . '-tags.json';
        return is_file($file) ? time() - (int) filemtime($file) : null;
    }

    // ------------------------------------------------------------------ HTTP

    private function cached(string $key, bool $refresh, \Closure $fetch): array
    {
        $file = $this->cacheDir . '/github-' . $this->app . '-' . $key . '.json';
        $ttl = (int) ($this->config['cache_ttl'] ?? 600);
        if (!$refresh && is_file($file) && time() - filemtime($file) < $ttl) {
            return (array) json_decode((string) file_get_contents($file), true);
        }
        try {
            $data = $fetch();
        } catch (\RuntimeException $e) {
            // Sin conexión con GitHub: mejor datos viejos que ninguno
            if (is_file($file)) {
                return (array) json_decode((string) file_get_contents($file), true);
            }
            throw $e;
        }
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $data;
    }

    private function api(string $path): array
    {
        $data = json_decode($this->request(self::API . $path, 'application/vnd.github+json'), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Respuesta de GitHub no válida');
        }
        return $data;
    }

    /** Con $sink guarda la respuesta en ese fichero y devuelve ''. */
    private function request(string $url, string $accept, ?string $sink = null): string
    {
        $headers = ['Accept: ' . $accept, 'X-GitHub-Api-Version: 2022-11-28'];
        $token = (string) ($this->config['token'] ?? '');
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $ch = curl_init($url);
        $out = $sink !== null ? fopen($sink, 'wb') : null;
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'Cosmo',
            CURLOPT_FOLLOWLOCATION => true,   // zipball redirige a codeload.github.com (curl no reenvía Authorization a otro host)
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $sink !== null ? 300 : 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($out) {
            curl_setopt($ch, CURLOPT_FILE, $out);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $body === false ? curl_error($ch) : null;
        curl_close($ch);
        if ($out) {
            fclose($out);
        }
        if ($error !== null) {
            throw new \RuntimeException('No se puede conectar con GitHub: ' . $error);
        }
        if ($code !== 200) {
            $hint = match ($code) {
                401 => 'el token de GitHub no es válido o ha caducado',
                403 => 'límite de peticiones de GitHub o el token no tiene permiso (Contents: read)',
                404 => $token === '' ? 'el repositorio ' . $this->repo() . ' no existe o es privado: configura github.token'
                    : 'el repositorio ' . $this->repo() . ' no existe o el token no tiene acceso',
                default => 'HTTP ' . $code,
            };
            throw new \RuntimeException('GitHub: ' . $hint);
        }
        return $out ? '' : (string) $body;
    }
}
