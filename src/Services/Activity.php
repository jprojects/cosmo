<?php
declare(strict_types=1);

namespace Cosmo\Services;

use Cosmo\Core\Store;

/**
 * Registro de actividad (auditoría): quién hizo qué, sobre qué instalación y con qué resultado.
 * storage/data/activity.jsonl, una línea por acción.
 */
final class Activity
{
    private const FILE = 'activity.jsonl';
    private const MAX_BYTES = 10_485_760;

    public const ACTIONS = [
        'login' => 'Inicio de sesión',
        'logout' => 'Cierre de sesión',
        'install.create' => 'Alta de instalación',
        'install.update' => 'Edición de instalación',
        'install.delete' => 'Baja de instalación',
        'check' => 'Comprobación',
        'status.down' => 'Instalación caída',
        'status.up' => 'Instalación recuperada',
        'backup.create' => 'Copia de seguridad',
        'backup.delete' => 'Copia borrada',
        'backup.pull' => 'Copia guardada en Cosmo',
        'backup.download' => 'Copia descargada',
        'backup.local-delete' => 'Copia local borrada',
        'update' => 'Actualización',
        'migrate' => 'Migración de BD',
        'maintenance.on' => 'Mantenimiento activado',
        'maintenance.off' => 'Mantenimiento desactivado',
        'releases.refresh' => 'Versiones recargadas',
    ];

    public function __construct(private readonly Store $store)
    {
    }

    public function log(string $user, string $action, ?string $installation, bool $ok = true, string $detail = '', array $data = []): void
    {
        $this->store->append(self::FILE, [
            't' => date(DATE_ATOM),
            'user' => $user,
            'ip' => PHP_SAPI === 'cli' ? 'cli' : (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'action' => $action,
            'install' => $installation,
            'ok' => $ok,
            'detail' => mb_substr($detail, 0, 1000),
        ] + ($data ? ['data' => $data] : []), self::MAX_BYTES);
    }

    /** @return list<array<string,mixed>> la más reciente primero */
    public function recent(int $limit = 200, ?string $installation = null, ?string $user = null, ?string $action = null): array
    {
        $rows = $this->store->tail(self::FILE, PHP_INT_MAX, 8_388_608);
        $rows = array_filter($rows, static fn (array $r) => ($installation === null || ($r['install'] ?? null) === $installation)
            && ($user === null || ($r['user'] ?? null) === $user)
            && ($action === null || str_starts_with((string) ($r['action'] ?? ''), $action)));
        return array_slice(array_values($rows), 0, $limit);
    }

    public static function label(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }
}
