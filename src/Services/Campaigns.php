<?php
declare(strict_types=1);

namespace Cosmo\Services;

use Cosmo\Core\Store;

/**
 * Campañas: avisos de AFI que salen en el inicio del panel de las webs Neutron (/admin/), con un botón a una página
 * (un servicio, una promoción). Se guardan en storage/data/campaigns.json y se envían con la acción remota
 * announcements-set de Neutron (1.14.0+): cada web recibe siempre la lista entera de las campañas que le tocan,
 * así que quitar, cambiar o caducar una campaña es volver a enviar. La respuesta trae las vistas (una por sesión del
 * panel) y los clics de cada campaña en esa web, que se guardan en sync.
 */
final class Campaigns
{
    private const FILE = 'campaigns.json';
    /** Primera versión de Neutron con avisos */
    public const MIN_NEUTRON = '1.14.0';
    private const LIMITS = ['title' => 120, 'text' => 600, 'button' => 40, 'url' => 500];

    public function __construct(
        private readonly Store $store,
        private readonly Installations $installations,
        private readonly Monitor $monitor,
        private readonly QuasarClient $client,
    ) {
    }

    /** @return array<string,array<string,mixed>> id => campaña, la más reciente primero */
    public function all(): array
    {
        $list = array_filter((array) $this->store->read(self::FILE), 'is_array');
        uasort($list, static fn (array $a, array $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $list;
    }

    public function get(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * Valida y guarda. $id null = alta.
     * @return array{0:?array,1:array<string,string>} [campaña guardada, errores por campo]
     */
    public function save(?string $id, array $input, string $user): array
    {
        $data = [];
        foreach (self::LIMITS as $key => $max) {
            $data[$key] = trim((string) ($input[$key] ?? ''));
        }
        $data['until'] = trim((string) ($input['until'] ?? ''));
        $data['target'] = ($input['target'] ?? 'all') === 'some' ? 'some' : 'all';
        $ids = array_keys($this->targets());
        $data['installations'] = array_values(array_intersect(array_map('strval', (array) ($input['installations'] ?? [])), $ids));

        $errors = [];
        if ($data['title'] === '') {
            $errors['title'] = 'Escribe el título';
        }
        foreach (self::LIMITS as $key => $max) {
            if (mb_strlen($data[$key]) > $max) {
                $errors[$key] = "Máximo $max caracteres";
            }
        }
        if ($data['url'] !== '' && !preg_match('~^https?://[^\s]+$~i', $data['url'])) {
            $errors['url'] = 'Una dirección completa: https://…';
        }
        if ($data['until'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['until'])) {
            $errors['until'] = 'Fecha no válida';
        }
        if ($data['target'] === 'some' && !$data['installations']) {
            $errors['installations'] = 'Marca al menos una web';
        }
        if ($errors) {
            return [null, $errors];
        }

        $this->store->update(self::FILE, function (array $all) use (&$id, $data, $user): array {
            $now = date(DATE_ATOM);
            if ($id === null) {
                $id = date('ymd') . '-' . bin2hex(random_bytes(3));
                $all[$id] = ['id' => $id, 'created_at' => $now, 'created_by' => $user, 'sync' => []];
            }
            $all[$id] = array_merge($all[$id], $data, ['updated_at' => $now]);
            return $all;
        });
        return [$this->get($id), []];
    }

    public function delete(string $id): void
    {
        $this->store->update(self::FILE, static function (array $all) use ($id): array {
            unset($all[$id]);
            return $all;
        });
    }

    /**
     * Webs Neutron a las que se pueden enviar campañas, con su versión (último estado conocido) y si la admiten.
     * @return array<string,array{installation:array,version:?string,supported:bool}>
     */
    public function targets(): array
    {
        $out = [];
        foreach ($this->installations->all() as $id => $inst) {
            if ($inst['app'] !== 'neutron') {
                continue;
            }
            $version = Monitor::data($this->monitor->snapshot($id))['version'] ?? null;
            $out[$id] = [
                'installation' => $inst,
                'version' => is_string($version) ? $version : null,
                'supported' => is_string($version) && version_compare($version, self::MIN_NEUTRON, '>='),
            ];
        }
        return $out;
    }

    /** Las campañas que tocan a una web (las caducadas no se envían) */
    public function forInstallation(string $id): array
    {
        $today = date('Y-m-d');
        $items = [];
        foreach ($this->all() as $c) {
            if ($c['until'] !== '' && $c['until'] < $today) {
                continue;
            }
            if ($c['target'] === 'some' && !in_array($id, $c['installations'], true)) {
                continue;
            }
            $items[] = ['id' => $c['id'], 'title' => $c['title'], 'text' => $c['text'], 'url' => $c['url'],
                'button' => $c['button'], 'until' => $c['until']];
        }
        return $items;
    }

    /**
     * Envía a cada web Neutron que lo admite la lista de sus campañas y guarda vistas y clics.
     * @return array<string,array{ok:bool,error:?string,skipped:bool}> por instalación
     */
    public function sync(): array
    {
        $results = [];
        $stats = [];
        foreach ($this->targets() as $id => $t) {
            if (!$t['supported']) {
                $results[$id] = ['ok' => false, 'skipped' => true,
                    'error' => $t['version'] ? 'Neutron ' . $t['version'] . ': hace falta la ' . self::MIN_NEUTRON : 'Sin estado: comprueba la web'];
                continue;
            }
            $r = $this->client->post($t['installation'], $this->installations->token($t['installation']), 'announcements-set',
                ['items' => json_encode($this->forInstallation($id), JSON_UNESCAPED_UNICODE)], false);
            $results[$id] = ['ok' => $r->ok, 'skipped' => false, 'error' => $r->ok ? null : (string) $r->error];
            if ($r->ok) {
                foreach ((array) ($r->data['stats'] ?? []) as $cid => $s) {
                    $stats[(string) $cid][$id] = ['views' => (int) ($s['views'] ?? 0), 'clicks' => (int) ($s['clicks'] ?? 0)];
                }
            }
        }
        $now = date(DATE_ATOM);
        $this->store->update(self::FILE, static function (array $all) use ($stats, $results, $now): array {
            foreach ($all as $cid => &$c) {
                foreach ($results as $iid => $res) {
                    if ($res['skipped']) {
                        continue;
                    }
                    $prev = $c['sync'][$iid] ?? [];
                    $c['sync'][$iid] = [
                        'at' => $now,
                        'ok' => $res['ok'],
                        'error' => $res['error'],
                        // si la web no responde, se conservan las últimas cifras conocidas
                        'views' => $stats[$cid][$iid]['views'] ?? ($prev['views'] ?? 0),
                        'clicks' => $stats[$cid][$iid]['clicks'] ?? ($prev['clicks'] ?? 0),
                    ];
                }
            }
            unset($c);
            return $all;
        });
        return $results;
    }

    /** Totales de una campaña: webs donde está, vistas y clics */
    public static function totals(array $campaign): array
    {
        $views = $clicks = 0;
        foreach ((array) ($campaign['sync'] ?? []) as $s) {
            $views += (int) ($s['views'] ?? 0);
            $clicks += (int) ($s['clicks'] ?? 0);
        }
        return ['views' => $views, 'clicks' => $clicks];
    }
}
