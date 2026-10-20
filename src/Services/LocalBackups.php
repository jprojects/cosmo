<?php
declare(strict_types=1);

namespace Cosmo\Services;

use Cosmo\Core\Store;

/**
 * Copias descargadas a Cosmo: storage/backups/{instalación}/backup-….zip
 * (una segunda ubicación por si el hosting se pierde).
 */
final class LocalBackups
{
    private const NAME = '/^backup-\d{8}-\d{6}-(full|db|files)-[0-9a-f]{6}\.zip$/';

    public function __construct(private readonly string $dir, private readonly int $keep)
    {
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match(self::NAME, $name);
    }

    public function dirFor(string $installationId): string
    {
        if (!preg_match('/^[a-z0-9-]+$/', $installationId)) {
            throw new \InvalidArgumentException('Instalación no válida');
        }
        $dir = $this->dir . '/' . $installationId;
        Store::ensureDir($dir);
        return $dir;
    }

    public function path(string $installationId, string $name): ?string
    {
        if (!self::validName($name)) {
            return null;
        }
        $file = $this->dirFor($installationId) . '/' . $name;
        return is_file($file) ? $file : null;
    }

    /** @return list<array{file:string,size:int,saved_at:string}> la más reciente primero */
    public function list(string $installationId): array
    {
        $out = [];
        foreach (glob($this->dirFor($installationId) . '/backup-*.zip') ?: [] as $f) {
            if (self::validName(basename($f))) {
                $out[] = ['file' => basename($f), 'size' => (int) filesize($f), 'saved_at' => date(DATE_ATOM, (int) filemtime($f))];
            }
        }
        usort($out, static fn (array $a, array $b) => strcmp($b['file'], $a['file']));
        return $out;
    }

    public function delete(string $installationId, string $name): bool
    {
        $file = $this->path($installationId, $name);
        return $file !== null && unlink($file);
    }

    /** Conserva las 'backups.local_keep' más recientes. */
    public function prune(string $installationId): int
    {
        $removed = 0;
        foreach (array_slice($this->list($installationId), max(1, $this->keep)) as $old) {
            if (@unlink($this->dirFor($installationId) . '/' . $old['file'])) {
                $removed++;
            }
        }
        return $removed;
    }

    public function totalSize(string $installationId): int
    {
        return array_sum(array_column($this->list($installationId), 'size'));
    }

    public function forget(string $installationId): void
    {
        foreach ($this->list($installationId) as $b) {
            @unlink($this->dirFor($installationId) . '/' . $b['file']);
        }
        @rmdir($this->dirFor($installationId));
    }
}
