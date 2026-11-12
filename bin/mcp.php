#!/usr/bin/env php
<?php
/**
 * Cosmo · servidor MCP para Claude Code (local, stdio, sin dependencias): desde VS Code, Claude ve las instalaciones
 * de Quasar, Neutron y Nebula y, cuando se lo pides, las actualiza a una versión publicada (después de etiquetar y
 * subir una versión del núcleo). Habla con la API de Cosmo (?r=api) con TU token: puede lo mismo que tu rol.
 *
 * Token: en el servidor de Cosmo, php bin/api-token.php <usuario> (y la línea 'api_token' en config.local.php).
 * Alta en Claude Code (una vez; disponible en todos los proyectos):
 *
 *   mkdir -p ~/.config/cosmo-mcp && (umask 077; echo 'cs_…' > ~/.config/cosmo-mcp/token)
 *   claude mcp add cosmo --scope user -e COSMO_URL=https://cosmo.ejemplo.com -- php /home/…/cosmo/bin/mcp.php
 *
 * El token se lee de COSMO_TOKEN o, si no está, de ~/.config/cosmo-mcp/token (o de COSMO_TOKEN_FILE).
 * Protocolo: JSON-RPC 2.0, un mensaje por línea en stdin/stdout (los avisos van a stderr).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const MCP_VERSION = '2025-06-18';
const UPDATE_TIMEOUT = 1800;   // una actualización (copia previa + subida + migración) puede tardar

$base = rtrim((string) getenv('COSMO_URL'), '/');
$home = (string) (getenv('HOME') ?: getenv('USERPROFILE') ?: '');
$token = (string) getenv('COSMO_TOKEN');
if ($token === '') {
    $tokenFile = (string) (getenv('COSMO_TOKEN_FILE') ?: ($home . '/.config/cosmo-mcp/token'));
    $token = is_readable($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
}

// ------------------------------------------------------------------------------------------------- API de Cosmo

final class CosmoApi
{
    public function __construct(private readonly string $base, private readonly string $token)
    {
    }

    /** @return array{0:int,1:array<string,mixed>} [código HTTP, respuesta] */
    public function call(string $method, string $action, array $query = [], ?array $body = null, int $timeout = 120): array
    {
        $url = $this->base . '/index.php?' . http_build_query(['r' => 'api', 'a' => $action] + $query);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->token, 'X-Cosmo-Token: ' . $this->token];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Cosmo-MCP/1.0',
        ] + (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA') ? [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA] : [])
          + ($body !== null ? [CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)] : []));
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false) {
            throw new RuntimeException('No se puede conectar con Cosmo: ' . curl_error($ch));
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Cosmo ha respondido $status sin JSON (revisa COSMO_URL)");
        }
        return [$status, $data];
    }

    /** Como call(), pero un error HTTP es una excepción. */
    public function get(string $action, array $query = []): array
    {
        [$status, $data] = $this->call('GET', $action, $query);
        if ($status >= 400 || empty($data['ok'])) {
            throw new RuntimeException('Cosmo: ' . ($data['error'] ?? "error $status"));
        }
        return $data;
    }
}

// ------------------------------------------------------------------------------------------------- herramientas

final class Tools
{
    /** @var Closure(int,int,string):void */
    private Closure $progress;

    public function __construct(private readonly CosmoApi $api)
    {
    }

    public static function definitions(): array
    {
        $app = ['type' => 'string', 'description' => 'Aplicación: quasar, neutron, nebula…'];
        return [
            [
                'name' => 'cosmo_instalaciones',
                'description' => 'Instalaciones que gestiona Cosmo (de una aplicación o todas): versión instalada, última versión '
                    . 'publicada de su aplicación (consultada a GitHub en el momento), si está desactualizada, si responde y si '
                    . 'está en mantenimiento. «comprobar» pide el estado a cada instalación ahora (si no, el de la última '
                    . 'comprobación del cron, de hace como mucho 10 minutos).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'app' => $app,
                    'comprobar' => ['type' => 'boolean', 'description' => 'Comprobar el estado ahora (tarda unos segundos)'],
                ]],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'cosmo_versiones',
                'description' => 'Versiones publicadas de una aplicación (etiquetas vX.Y.Z de GitHub, recién consultadas), el '
                    . 'CHANGELOG de las últimas y qué instalaciones hay en cada versión.',
                'inputSchema' => ['type' => 'object', 'properties' => ['app' => $app], 'required' => ['app']],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'cosmo_actualiza',
                'description' => 'Actualiza instalaciones desde Cosmo, como el botón Actualizar del panel: copia previa, '
                    . 'mantenimiento, solo los ficheros del núcleo, autoprueba con vuelta atrás, migración y comprobación final. '
                    . 'Solo actualiza a versiones etiquetadas y subidas a GitHub (vX.Y.Z): un commit sin etiqueta no basta. '
                    . 'Con «instalaciones», esas (ids de cosmo_instalaciones); sin ellas, todas las desactualizadas de «app». '
                    . 'Van de una en una y cada una puede tardar minutos. Úsala solo cuando el usuario lo pida: antes, '
                    . 'enséñale qué instalaciones y a qué versión, y espera su confirmación.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'app' => $app,
                    'instalaciones' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ids de las instalaciones'],
                    'version' => ['type' => 'string', 'description' => 'X.Y.Z (por defecto, la última publicada de su aplicación)'],
                    'copia' => ['type' => 'boolean', 'description' => 'Copia previa en la instalación (por defecto, sí)'],
                    'forzar' => ['type' => 'boolean', 'description' => 'Sobrescribir ficheros del núcleo cambiados a mano (solo si el usuario lo pide tras ver la lista)'],
                ]],
                'annotations' => ['destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => true],
            ],
        ];
    }

    /** @param Closure(int,int,string):void|null $progress */
    public function call(string $name, array $args, ?Closure $progress = null): array
    {
        $this->progress = $progress ?? static function (): void {
        };
        return match ($name) {
            'cosmo_instalaciones' => $this->installations($args),
            'cosmo_versiones' => $this->releases($args),
            'cosmo_actualiza' => $this->update($args),
            default => throw new InvalidArgumentException("Herramienta desconocida: $name"),
        };
    }

    private function installations(array $args): array
    {
        $query = array_filter([
            'app' => (string) ($args['app'] ?? ''),
            'check' => !empty($args['comprobar']) ? '1' : '',
            'refresh' => '1',
        ]);
        $d = $this->api->get('installations', $query);
        $rows = $d['installations'];
        if (!$rows) {
            return self::text('No hay instalaciones' . (isset($query['app']) ? ' de ' . $query['app'] : '') . '.');
        }
        $out = [];
        foreach ($d['latest'] as $app => $v) {
            $out[] = "Última de $app: " . ($v ?? '— (' . ($d['github_errors'][$app] ?? 'sin etiquetas') . ')');
        }
        $outdated = count(array_filter($rows, static fn ($r) => $r['outdated']));
        $out[] = '';
        $out[] = count($rows) . ' instalaciones' . ($outdated ? ", $outdated desactualizadas" : ', todas al día') . ':';
        foreach ($rows as $r) {
            $flags = array_filter([
                $r['outdated'] ? 'DESACTUALIZADA' : null,
                $r['online'] === false ? 'NO RESPONDE: ' . $r['error'] : null,
                $r['online'] === null ? 'sin comprobar' : null,
                $r['maintenance'] ? 'en mantenimiento' : null,
            ]);
            $out[] = sprintf('- %s · %s · %s %s%s', $r['id'], $r['name'], $r['app'], $r['version'] ?? '?', $flags ? ' · ' . implode(' · ', $flags) : '');
        }
        return self::text(implode("\n", $out));
    }

    private function releases(array $args): array
    {
        $d = $this->api->get('releases', ['app' => (string) ($args['app'] ?? ''), 'refresh' => '1']);
        $out = ["# {$d['app']} ({$d['repo']})", '', 'Última: ' . ($d['latest'] ?? '—'), 'Publicadas: ' . (implode(', ', $d['versions']) ?: 'ninguna'), ''];
        if ($d['installations_by_version']) {
            $out[] = 'Instalaciones por versión:';
            $byVersion = $d['installations_by_version'];
            uksort($byVersion, static fn ($a, $b) => version_compare((string) $b, (string) $a));
            foreach ($byVersion as $v => $ids) {
                $out[] = "- $v: " . implode(', ', $ids);
            }
            $out[] = '';
        }
        foreach ($d['changelog'] as $v => $c) {
            $out[] = "## $v" . (!empty($c['date']) ? " — {$c['date']}" : '');
            $out[] = $c['body'];
            $out[] = '';
        }
        return self::text(trim(implode("\n", $out)));
    }

    private function update(array $args): array
    {
        $ids = array_values(array_filter((array) ($args['instalaciones'] ?? []), 'is_string'));
        $app = (string) ($args['app'] ?? '');
        $version = trim((string) ($args['version'] ?? ''));
        if (!$ids) {
            if ($app === '') {
                throw new InvalidArgumentException('Di qué instalaciones («instalaciones») o de qué aplicación («app»)');
            }
            $d = $this->api->get('installations', ['app' => $app, 'refresh' => '1']);
            $target = $version !== '' ? ltrim($version, 'v') : null;
            foreach ($d['installations'] as $r) {
                $behind = $target !== null ? ($r['version'] !== null && version_compare($r['version'], $target, '<')) : $r['outdated'];
                if ($behind) {
                    $ids[] = $r['id'];
                }
            }
            if (!$ids) {
                return self::text("Ninguna instalación de $app por debajo de la " . ($target ?? $d['latest'][$app] ?? 'última') . ': no hay nada que actualizar.');
            }
        }

        $out = [];
        $failed = 0;
        foreach ($ids as $n => $id) {
            ($this->progress)($n, count($ids), "Actualizando $id (" . ($n + 1) . '/' . count($ids) . ')');
            try {
                [$status, $r] = $this->api->call('POST', 'update', [], [
                    'id' => $id,
                    'version' => $version !== '' ? $version : 'latest',
                    'backup' => ($args['copia'] ?? true) !== false,
                    'force' => !empty($args['forzar']),
                ], UPDATE_TIMEOUT);
            } catch (RuntimeException $e) {
                $status = 0;
                $r = ['ok' => false, 'error' => $e->getMessage() . ' (puede que siga en marcha: mira la ficha en Cosmo)'];
            }
            $ok = $status < 400 && !empty($r['ok']);
            $failed += $ok ? 0 : 1;
            $title = ($r['name'] ?? $id) . (isset($r['to']) ? ': ' . ($r['from'] ?? '?') . ' → ' . $r['to'] : '');
            $out[] = ($ok ? '✔ ' : '✘ ') . $title;
            foreach ($r['steps'] ?? [] as $s) {
                $out[] = '  ' . ($s['ok'] ? '·' : '✘') . ' ' . $s['text'];
            }
            if (empty($r['steps']) && !empty($r['error'])) {
                $out[] = '  ✘ ' . $r['error'];
            }
        }
        ($this->progress)(count($ids), count($ids), 'Hecho');
        array_unshift($out, sprintf('%d de %d actualizadas%s', count($ids) - $failed, count($ids), $failed ? " ($failed con error)" : ''), '');
        return ['content' => [['type' => 'text', 'text' => implode("\n", $out)]]] + ($failed === count($ids) ? ['isError' => true] : []);
    }

    private static function text(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]]];
    }
}

// ------------------------------------------------------------------------------------------------- bucle JSON-RPC

function send(array $message): void
{
    fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
}

$tools = ($base !== '' && $token !== '') ? new Tools(new CosmoApi($base, $token)) : null;
if ($tools === null) {
    fwrite(STDERR, "cosmo-mcp: faltan COSMO_URL y el token (COSMO_TOKEN o ~/.config/cosmo-mcp/token)\n");
}

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method'])) {
        if (is_array($msg) && array_key_exists('id', $msg)) {
            continue; // respuesta del cliente: no esperamos ninguna
        }
        send(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
        continue;
    }
    $id = $msg['id'] ?? null;
    $method = (string) $msg['method'];
    if ($id === null) {
        continue; // notificaciones (notifications/initialized…)
    }
    try {
        $result = match ($method) {
            'initialize' => [
                'protocolVersion' => (string) ($msg['params']['protocolVersion'] ?? MCP_VERSION),
                'capabilities' => ['tools' => new stdClass()],
                'serverInfo' => ['name' => 'cosmo', 'version' => '1.0.0'],
                'instructions' => 'Cosmo es el panel que actualiza las instalaciones de los clientes de Quasar, Neutron y Nebula. '
                    . 'Actualiza solo cuando el usuario lo pida: primero mira con cosmo_instalaciones (o cosmo_versiones) cuáles y a '
                    . 'qué versión, enséñaselo y espera su confirmación antes de cosmo_actualiza. Solo hay versiones etiquetadas y '
                    . 'subidas a GitHub (vX.Y.Z): si la del repo en el que estás aún no aparece, recuérdale etiquetarla y hacer '
                    . 'git push --tags. Si una falla, cuenta los pasos tal cual; no fuerces ni repitas sin preguntar.',
            ],
            'ping' => new stdClass(),
            'tools/list' => ['tools' => Tools::definitions()],
            'tools/call' => (function () use ($tools, $msg) {
                if ($tools === null) {
                    return ['content' => [['type' => 'text', 'text' => 'Falta COSMO_URL o el token: guárdalo en ~/.config/cosmo-mcp/token (php bin/api-token.php en el servidor de Cosmo).']], 'isError' => true];
                }
                $progressToken = $msg['params']['_meta']['progressToken'] ?? null;
                $progress = $progressToken === null ? null : static function (int $done, int $total, string $text) use ($progressToken): void {
                    send(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => [
                        'progressToken' => $progressToken, 'progress' => $done, 'total' => $total, 'message' => $text,
                    ]]);
                };
                try {
                    return $tools->call((string) ($msg['params']['name'] ?? ''), (array) ($msg['params']['arguments'] ?? []), $progress);
                } catch (Throwable $e) {
                    return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
                }
            })(),
            default => throw new BadMethodCallException($method),
        };
        send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    } catch (BadMethodCallException $e) {
        send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found: ' . $e->getMessage()]]);
    } catch (Throwable $e) {
        send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => $e->getMessage()]]);
    }
}
