<?php
declare(strict_types=1);

namespace Cosmo\Services;

use Cosmo\Core\Crypto;
use Cosmo\Core\Store;

/**
 * Servidores (cuentas de hosting) en los que Cosmo puede instalar aplicaciones nuevas por FTP
 * (storage/data/servers.json). La contraseña del FTP se guarda cifrada.
 */
final class Servers
{
    private const FILE = 'servers.json';

    private const DEFAULTS = [
        'id' => '',
        'name' => '',
        'host' => '',
        'port' => 21,
        'user' => '',
        'password' => '',
        'ftps' => true,
        'verify_ssl' => true,
        'base' => '/',
        'created_at' => null,
        'updated_at' => null,
    ];

    public function __construct(private readonly Store $store, private readonly Crypto $crypto)
    {
    }

    /** @return array<string,array<string,mixed>> */
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

    public function ftp(array $server, int $timeout = 60): Ftp
    {
        return new Ftp($server, $this->crypto->decrypt((string) $server['password']), $timeout);
    }

    /**
     * Valida y guarda. $id null = alta. Contraseña vacía al editar = se conserva.
     * @return array{0:?array,1:array<string,string>}
     */
    public function save(?string $id, array $input): array
    {
        $current = $id !== null ? $this->get($id) : null;
        $data = [
            'name' => trim((string) ($input['name'] ?? '')),
            'host' => strtolower(trim((string) ($input['host'] ?? ''))),
            'port' => (int) ($input['port'] ?? 21),
            'user' => trim((string) ($input['user'] ?? '')),
            'ftps' => !empty($input['ftps']),
            'verify_ssl' => !empty($input['verify_ssl']),
            'base' => '/',
        ];
        $password = (string) ($input['password'] ?? '');
        $errors = [];
        if ($data['name'] === '' || mb_strlen($data['name']) > 100) {
            $errors['name'] = 'Escribe un nombre (máximo 100 caracteres)';
        }
        if (!preg_match('/^[a-z0-9.-]{1,253}$/', $data['host'])) {
            $errors['host'] = 'Servidor no válido. Ejemplo: ftp.midominio.com';
        }
        if ($data['port'] < 1 || $data['port'] > 65535) {
            $errors['port'] = 'Puerto entre 1 y 65535';
        }
        if ($data['user'] === '') {
            $errors['user'] = 'Escribe el usuario del FTP';
        }
        if ($password === '' && $current === null) {
            $errors['password'] = 'Escribe la contraseña del FTP';
        }
        try {
            $data['base'] = Ftp::normalize((string) ($input['base'] ?? '/'));
        } catch (\InvalidArgumentException) {
            $errors['base'] = 'Carpeta no válida';
        }
        if ($errors) {
            return [null, $errors];
        }
        $this->store->update(self::FILE, function (array $all) use (&$id, $data, $password): array {
            $now = date(DATE_ATOM);
            if ($id === null) {
                $id = self::slug($data['name'], array_keys($all));
                $all[$id] = ['id' => $id, 'created_at' => $now] + self::DEFAULTS;
            }
            $all[$id] = array_merge($all[$id], $data, ['updated_at' => $now]);
            if ($password !== '') {
                $all[$id]['password'] = $this->crypto->encrypt($password);
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

    /** @param list<string> $taken */
    private static function slug(string $name, array $taken): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name), '-'));
        $base = substr($base !== '' ? $base : 'servidor', 0, 40);
        $slug = $base;
        for ($i = 2; in_array($slug, $taken, true); $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }
}
