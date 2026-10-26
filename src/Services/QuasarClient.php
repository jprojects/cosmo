<?php
declare(strict_types=1);

namespace Cosmo\Services;

/**
 * Habla con la API remota de cada instalación: index.php?r=remote&a=acción, cabecera X-Quasar-Token.
 * La definió Quasar y la replican las demás aplicaciones (Neutron…), con las mismas acciones y respuestas.
 *
 * Todas las llamadas devuelven un Response; nunca lanzan por errores de red o HTTP.
 */
final class QuasarClient
{
    public function __construct(private readonly array $http, private readonly string $cosmoVersion)
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Cosmo necesita la extensión curl de PHP');
        }
    }

    public static function endpoint(array $installation, string $action, array $query = []): string
    {
        $base = rtrim((string) $installation['url'], '/') . '/index.php';
        // Instalaciones 0.1.x: solo existe ?r=status
        $params = $action === 'status-legacy' ? ['r' => 'status'] : ['r' => 'remote', 'a' => $action];
        return $base . '?' . http_build_query($params + $query);
    }

    public function get(array $installation, string $token, string $action, array $query = [], bool $long = false): Response
    {
        $ch = $this->handle($installation, $token, self::endpoint($installation, $action, $query), $long);
        return $this->finish($ch, curl_exec($ch));
    }

    /** @param array<string,string|\CURLFile> $fields */
    public function post(array $installation, string $token, string $action, array $fields = [], bool $long = true): Response
    {
        $ch = $this->handle($installation, $token, self::endpoint($installation, $action), $long);
        curl_setopt($ch, CURLOPT_POST, true);
        // Con CURLFile va como multipart; si no, como formulario normal
        $hasFile = (bool) array_filter($fields, static fn ($v) => $v instanceof \CURLFile);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $hasFile ? $fields : http_build_query($fields));
        return $this->finish($ch, curl_exec($ch));
    }

    /**
     * Estado de varias instalaciones en paralelo (curl_multi).
     * @param array<string,array{0:array,1:string}> $targets id => [instalación, token]
     * @return array<string,Response>
     */
    public function statusMany(array $targets): array
    {
        $results = [];
        $queue = $targets;
        $concurrency = max(1, (int) ($this->http['concurrency'] ?? 8));
        $mh = curl_multi_init();
        $running = [];

        $add = function () use (&$queue, &$running, $mh): void {
            $id = array_key_first($queue);
            [$inst, $token] = $queue[$id];
            unset($queue[$id]);
            $ch = $this->handle($inst, $token, self::endpoint($inst, 'status'), false);
            curl_multi_add_handle($mh, $ch);
            $running[(int) $ch] = [$id, $ch];
        };

        while ($queue && count($running) < $concurrency) {
            $add();
        }
        do {
            curl_multi_exec($mh, $active);
            if (curl_multi_select($mh, 1.0) === -1) {
                usleep(50_000);
            }
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                [$id] = $running[(int) $ch];
                unset($running[(int) $ch]);
                $body = $info['result'] === CURLE_OK ? curl_multi_getcontent($ch) : false;
                if ($body === false) {
                    // curl_error no funciona con multi: el código del resultado sí
                    $results[$id] = new Response(false, 0, null, curl_strerror($info['result']), (int) (curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000));
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                } else {
                    curl_multi_remove_handle($mh, $ch);
                    $results[$id] = $this->finish($ch, $body);
                }
                if ($queue) {
                    $add();
                }
            }
        } while ($running || $queue);
        curl_multi_close($mh);
        return $results;
    }

    /** Descarga una copia a $dest comprobando el sha256 que envía la instalación. */
    public function download(array $installation, string $token, string $file, string $dest): Response
    {
        $out = fopen($dest . '.part', 'wb');
        if ($out === false) {
            return new Response(false, 0, null, "No se puede escribir $dest");
        }
        $headers = [];
        $ch = $this->handle($installation, $token, self::endpoint($installation, 'download', ['file' => $file]), true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FILE, $out);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        });
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ms = (int) (curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        $error = $ok === false ? curl_error($ch) : null;
        curl_close($ch);
        fclose($out);

        if ($error !== null || $code !== 200) {
            @unlink($dest . '.part');
            return new Response(false, $code, null, $error ?? "HTTP $code", $ms);
        }
        $expected = $headers['x-backup-sha256'] ?? '';
        if ($expected !== '' && !hash_equals(strtolower($expected), hash_file('sha256', $dest . '.part'))) {
            @unlink($dest . '.part');
            return new Response(false, $code, null, 'La copia descargada no coincide con el sha256 de origen', $ms);
        }
        rename($dest . '.part', $dest);
        return new Response(true, $code, ['size' => filesize($dest), 'sha256' => $expected], null, $ms);
    }

    /**
     * Reenvía una copia directamente al navegador sin guardarla en Cosmo.
     * @return ?string error, o null si se ha enviado
     */
    public function stream(array $installation, string $token, string $file): ?string
    {
        $status = 0;
        $started = false;
        $ch = $this->handle($installation, $token, self::endpoint($installation, 'download', ['file' => $file]), true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$status, $file): int {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                $status = (int) $m[1];
            } elseif ($status === 200 && preg_match('/^content-length:\s*(\d+)/i', $line, $m)) {
                header('Content-Length: ' . $m[1]);
            }
            return strlen($line);
        });
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use (&$status, &$started, $file): int {
            if ($status !== 200) {
                return 0; // corta la descarga
            }
            if (!$started) {
                $started = true;
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . basename($file) . '"');
                header('Cache-Control: no-store');
            }
            echo $chunk;
            flush();
            return strlen($chunk);
        });
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $ok = curl_exec($ch);
        $error = $ok === false && !$started ? ($status ? "HTTP $status" : curl_error($ch)) : null;
        curl_close($ch);
        return $error;
    }

    /** @return \CurlHandle */
    private function handle(array $installation, string $token, string $url, bool $long)
    {
        $ch = curl_init($url);
        $verify = (bool) ($installation['verify_ssl'] ?? true);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,     // una redirección suele ser http→https o una URL mal puesta: mejor verla
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $long ? (int) ($this->http['long_timeout'] ?? 900) : (int) ($this->http['timeout'] ?? 20),
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Cosmo/' . $this->cosmoVersion,
            CURLOPT_HTTPHEADER => ['X-Quasar-Token: ' . $token, 'Accept: application/json'],
        ]);
        return $ch;
    }

    private function finish(\CurlHandle $ch, string|bool $body): Response
    {
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ms = (int) (curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $error = $body === false ? curl_error($ch) : null;
        curl_close($ch);

        if ($body === false) {
            return new Response(false, 0, null, $error ?: 'Sin respuesta', $ms);
        }
        $data = json_decode((string) $body, true);
        $data = is_array($data) ? $data : null;
        if ($code >= 300 && $code < 400) {
            return new Response(false, $code, null, "Redirige a $location: revisa la URL (¿http en vez de https?)", $ms);
        }
        if ($code === 404 && ($data['error'] ?? null) === 'Not Found') {
            return new Response(false, $code, $data, 'La instalación no responde a la API remota: token incorrecto, remote.token sin configurar o IP no permitida', $ms);
        }
        if ($code !== 200 || $data === null || ($data['ok'] ?? true) === false) {
            $message = is_string($data['error'] ?? null) ? $data['error'] : 'HTTP ' . $code . ($data === null ? ': respuesta no JSON' : '');
            return new Response(false, $code, $data, $message, $ms);
        }
        return new Response(true, $code, $data, null, $ms);
    }
}
