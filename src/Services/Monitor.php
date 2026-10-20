<?php
declare(strict_types=1);

namespace Cosmo\Services;

use Cosmo\Core\Store;

/**
 * Comprueba el estado de las instalaciones y guarda:
 *   storage/data/status/{id}.json     última comprobación (y los últimos datos buenos si ahora falla)
 *   storage/data/history/{id}.jsonl   historial: hora, ok, ms, versión (para disponibilidad y latencia)
 * Avisa (actividad + webhook) cuando una instalación cae o se recupera.
 */
final class Monitor
{
    public function __construct(
        private readonly Store $store,
        private readonly Installations $installations,
        private readonly QuasarClient $client,
        private readonly Activity $activity,
        private readonly Notifier $notifier,
        private readonly array $config,
    ) {
    }

    /**
     * @param ?list<string> $ids null = todas
     * @return array<string,array<string,mixed>> id => instantánea
     */
    public function check(?array $ids = null, string $user = 'cron'): array
    {
        $all = $this->installations->all();
        $targets = [];
        $results = [];
        foreach ($ids === null ? array_keys($all) : $ids as $id) {
            if (!isset($all[$id])) {
                continue;
            }
            try {
                $targets[$id] = [$all[$id], $this->installations->token($all[$id])];
            } catch (\RuntimeException $e) {
                $results[$id] = new Response(false, 0, null, $e->getMessage());
            }
        }
        $results += $this->client->statusMany($targets);

        // Quasar 0.1.x no tiene ?r=remote: el router responde 404 con su propio mensaje (no "Not Found")
        foreach ($results as $id => $r) {
            if (!$r->ok && $r->status === 404 && ($r->data['error'] ?? null) !== 'Not Found' && isset($targets[$id])) {
                $legacy = $this->client->get($targets[$id][0], $targets[$id][1], 'status-legacy');
                if ($legacy->ok || $legacy->status !== 404) {
                    $results[$id] = $legacy;
                }
            }
        }

        $snapshots = [];
        foreach ($results as $id => $r) {
            $snapshots[$id] = $this->record($all[$id], $r, $user);
        }
        return $snapshots;
    }

    /** Guarda el resultado de una consulta de estado hecha por otro camino (p. ej. tras actualizar). */
    public function record(array $installation, Response $r, string $user = 'cron'): array
    {
        $id = $installation['id'];
        $previous = $this->snapshot($id);
        $data = $r->ok ? $r->data : null;
        $snapshot = [
            'checked_at' => date(DATE_ATOM),
            'ok' => $r->ok,
            'ms' => $r->ms,
            'http' => $r->status,
            'error' => $r->error,
            'data' => $data,
            // Si ahora falla, se conserva lo último que se supo (versión, PHP…)
            'last_ok' => $r->ok ? date(DATE_ATOM) : ($previous['last_ok'] ?? null),
            'last_data' => $data ?? ($previous['data'] ?? $previous['last_data'] ?? null),
        ];
        $this->store->write('status/' . $id . '.json', $snapshot);
        $this->store->append('history/' . $id . '.jsonl', [
            't' => $snapshot['checked_at'],
            'ok' => $r->ok,
            'ms' => $r->ms,
            'v' => $data['version'] ?? null,
        ], 524_288);

        if ($previous !== null && $previous['ok'] !== $r->ok) {
            if ($r->ok) {
                $this->activity->log($user, 'status.up', $id, true, 'Responde de nuevo');
                $this->notifier->send('success', $installation['name'] . ' vuelve a responder', $installation['url']);
            } else {
                $this->activity->log($user, 'status.down', $id, false, (string) $r->error);
                $this->notifier->send('error', $installation['name'] . ' no responde', $installation['url'] . "\n" . $r->error);
            }
        }
        return $snapshot;
    }

    public function snapshot(string $id): ?array
    {
        $s = $this->store->read('status/' . $id . '.json', null);
        return is_array($s) ? $s : null;
    }

    /** @return array<string,array<string,mixed>> */
    public function snapshots(): array
    {
        $out = [];
        foreach (array_keys($this->installations->all()) as $id) {
            if ($s = $this->snapshot($id)) {
                $out[$id] = $s;
            }
        }
        return $out;
    }

    /** Datos del último estado conocido (aunque la última comprobación haya fallado). */
    public static function data(?array $snapshot): ?array
    {
        return $snapshot['data'] ?? $snapshot['last_data'] ?? null;
    }

    /**
     * Disponibilidad y latencia de los últimos $days días.
     * @return array{checks:int,uptime:?float,avg_ms:?int,points:list<array{t:string,ok:bool,ms:int}>}
     */
    public function history(string $id, int $days = 7): array
    {
        $since = date(DATE_ATOM, time() - $days * 86400);
        $rows = array_reverse(array_filter(
            $this->store->tail('history/' . $id . '.jsonl', PHP_INT_MAX),
            static fn (array $r) => ($r['t'] ?? '') >= $since
        ));
        $ok = array_filter($rows, static fn (array $r) => !empty($r['ok']));
        $ms = array_map(static fn (array $r) => (int) $r['ms'], $ok);
        return [
            'checks' => count($rows),
            'uptime' => $rows ? round(count($ok) * 100 / count($rows), 2) : null,
            'avg_ms' => $ms ? (int) round(array_sum($ms) / count($ms)) : null,
            'points' => array_values(array_map(static fn (array $r) => ['t' => $r['t'], 'ok' => !empty($r['ok']), 'ms' => (int) $r['ms']], $rows)),
        ];
    }

    public function forget(string $id): void
    {
        $this->store->delete('status/' . $id . '.json');
        $this->store->delete('history/' . $id . '.jsonl');
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
