<?php
declare(strict_types=1);

namespace Cosmo\Core;

/** Usuario del panel (definido en config 'users'). */
final class User
{
    public const ROLES = ['admin' => 'Administrador', 'operator' => 'Operador', 'viewer' => 'Consulta'];

    /** permiso => roles que lo tienen */
    private const PERMISSIONS = [
        'view' => ['admin', 'operator', 'viewer'],
        'operate' => ['admin', 'operator'],   // comprobar, copias, actualizar, mantenimiento
        'manage' => ['admin'],                // alta, edición y baja de instalaciones
    ];

    public function __construct(
        public readonly string $username,
        public readonly string $name,
        public readonly string $role,
        public readonly ?string $email = null,
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($this->role, self::PERMISSIONS[$permission] ?? [], true);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }
}
