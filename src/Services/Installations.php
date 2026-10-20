<?php
declare(strict_types=1);

namespace Cosmo\Services;

use Cosmo\Core\Crypto;
use Cosmo\Core\Store;

/**
 * Instalaciones de Quasar dadas de alta en el panel (storage/data/installations.json).
 * El token se guarda cifrado; solo se descifra para hablar con la instalación.
 */
final class Installations
{
    private const FILE = 'installations.json';

    public const ENVIRONMENTS = ['production' => 'Producción', 'staging' => 'Pruebas', 'development' => 'Desarrollo'];
    public const SCHEDULES = ['off' => 'Sin copias programadas', 'daily' => 'Diaria', 'weekly' => 'Semanal'];

    /** Valores por defecto de una instalación */
    private const DEFAULTS = [
        'id' => '',
        'name' => '',
        'url' => '',
        'token' => '',
        'client' => '',
        'environment' => 'production',
        'notes' => '',
        'verify_ssl' => true,
        'backup_schedule' => 'off',
        'backup_type' => 'full',
        'backup_hour' => 3,
        'backup_pull' => true,
        'created_at' => null,
        'updated_at' => null,
    ];

    public function __construct(private readonly Store $store, private readonly Crypto $crypto)
    {
    }

    /** @return array<string,array<string,mixed>> id => instalación, por nombre */
    public function all(): array
    {
        $list = [];
        foreach ((array) $this->store->read(self::FILE) as $id => $row) {
            if (is_array($row)) {
                $list[(string) $id] = $row + self::DEFAULTS;
            }
        }
        uasort($list, static fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));
        return $list;
    }

    public function get(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    public function token(array $installation): string
    {
        return $this->crypto->decrypt((string) $installation['token']);
    }

    /**
     * Valida y guarda. $id null = alta. Token vacío al editar = se conserva.
     * @return array{0:?array,1:array<string,string>} [instalación guardada, errores por campo]
     */
    public function save(?string $id, array $input): array
    {
        $current = $id !== null ? $this->get($id) : null;
        $data = [
            'name' => trim((string) ($input['name'] ?? '')),
            'url' => self::normalizeUrl((string) ($input['url'] ?? '')),
            'client' => trim((string) ($input['client'] ?? '')),
            'environment' => (string) ($input['environment'] ?? 'production'),
            'notes' => trim((string) ($input['notes'] ?? '')),
            'verify_ssl' => !empty($input['verify_ssl']),
            'backup_schedule' => (string) ($input['backup_schedule'] ?? 'off'),
            'backup_type' => (string) ($input['backup_type'] ?? 'full'),
            'backup_hour' => (int) ($input['backup_hour'] ?? 3),
            'backup_pull' => !empty($input['backup_pull']),
        ];
        $token = trim((string) ($input['token'] ?? ''));

        $errors = [];
        if ($data['name'] === '' || mb_strlen($data['name']) > 100) {
            $errors['name'] = 'Escribe un nombre (máximo 100 caracteres)';
        }
        if ($data['url'] === '') {
            $errors['url'] = 'URL no válida. Ejemplo: https://gestion.cliente.com';
        }
        if ($token === '' && $current === null) {
            $errors['token'] = 'El token es obligatorio';
        } elseif ($token !== '' && (strlen($token) < 32 || strlen($token) > 256 || preg_match('/\s/', $token))) {
            $errors['token'] = 'El token debe tener entre 32 y 256 caracteres, sin espacios';
        }
        if (!isset(self::ENVIRONMENTS[$data['environment']])) {
            $errors['environment'] = 'Entorno no válido';
        }
        if (!isset(self::SCHEDULES[$data['backup_schedule']])) {
            $errors['backup_schedule'] = 'Periodicidad no válida';
        }
        if (!in_array($data['backup_type'], ['full', 'db', 'files'], true)) {
            $errors['backup_type'] = 'Tipo no válido';
        }
        if ($data['backup_hour'] < 0 || $data['backup_hour'] > 23) {
            $errors['backup_hour'] = 'Hora entre 0 y 23';
        }
        foreach ($this->all() as $other) {
            if ($other['id'] !== $id && $data['url'] !== '' && $other['url'] === $data['url']) {
                $errors['url'] = 'Ya hay una instalación con esta URL: ' . $other['name'];
            }
        }
        if ($errors) {
            return [null, $errors];
        }

        $this->store->update(self::FILE, function (array $all) use (&$id, $data, $token): array {
            $now = date(DATE_ATOM);
            if ($id === null) {
                $id = self::slug($data['name'], array_keys($all));
                $all[$id] = ['id' => $id, 'created_at' => $now] + self::DEFAULTS;
            }
            $all[$id] = array_merge($all[$id], $data, ['updated_at' => $now]);
            if ($token !== '') {
                $all[$id]['token'] = $this->crypto->encrypt($token);
            }
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

    /** https://cliente.com/app/index.php?x → https://cliente.com/app ('' si no es válida) */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && !preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        $path = preg_replace('~/index\.php$~', '', (string) ($parts['path'] ?? '')) ?? '';
        return strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim($path, '/');
    }

    /** @param list<string> $taken */
    private static function slug(string $name, array $taken): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name), '-'));
        $base = substr($base !== '' ? $base : 'instalacion', 0, 40);
        $slug = $base;
        for ($i = 2; in_array($slug, $taken, true); $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }
}
