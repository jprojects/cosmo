<?php
declare(strict_types=1);

namespace Cosmo\Services;

/**
 * Aplicaciones que gestiona Cosmo (config 'apps'): Quasar, Neutron… Todas hablan la misma API remota
 * (?r=remote&a=…, ver QuasarClient) y su estado dice cuál es ('app'); cada una tiene su repositorio
 * de GitHub, la primera versión con API remota y sus tipos de copia.
 */
final class Apps
{
    private const DEFAULTS = [
        'name' => '',
        'repo' => '',
        'min_version' => '0.0.0',
        'backups' => ['full' => 'Completa', 'db' => 'Base de datos', 'files' => 'Ficheros'],
    ];

    /** @var array<string,GitHub> */
    private array $github = [];

    public function __construct(
        private readonly array $apps,
        private readonly array $githubConfig,
        private readonly string $cacheDir,
        private readonly string $packagesDir,
    ) {
    }

    /** @return array<string,array{id:string,name:string,repo:string,min_version:string,backups:array<string,string>}> */
    public function all(): array
    {
        $out = [];
        foreach ($this->apps as $id => $def) {
            if (is_array($def) && preg_match('/^[a-z0-9_-]{1,32}$/', (string) $id)) {
                $out[(string) $id] = self::define((string) $id, $def);
            }
        }
        return $out;
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    /** Definición de la aplicación; si ya no está en la configuración, una mínima con su id como nombre. */
    public function get(string $id): array
    {
        return $this->all()[$id] ?? self::define($id, []);
    }

    public function name(string $id): string
    {
        return $this->get($id)['name'];
    }

    public function github(string $id): GitHub
    {
        return $this->github[$id] ??= new GitHub($this->githubConfig, $id, (string) $this->get($id)['repo'], $this->cacheDir, $this->packagesDir);
    }

    private static function define(string $id, array $def): array
    {
        $def += self::DEFAULTS;
        return ['id' => $id, 'name' => (string) ($def['name'] !== '' ? $def['name'] : ucfirst($id))] + $def;
    }
}
