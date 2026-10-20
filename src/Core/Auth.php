<?php
declare(strict_types=1);

namespace Cosmo\Core;

/**
 * Inicio de sesión contra los usuarios de config 'users'.
 * Bloquea por usuario y por IP tras 'login_max_attempts' fallos durante 'login_lockout' segundos.
 */
final class Auth
{
    private const ATTEMPTS = 'login-attempts.json';
    /** Hash de una contraseña cualquiera, para comparar cuando el usuario no existe */
    private const DUMMY_HASH = '$2y$10$weqz0frjprpyGeeQeORd6.zhlqLv/sD0wyxSILJG6wEW.w9IUQPXu';

    private ?User $user = null;
    private bool $loaded = false;

    public function __construct(private readonly array $config, private readonly Store $store)
    {
    }

    public function user(): ?User
    {
        if ($this->loaded) {
            return $this->user;
        }
        $this->loaded = true;
        $username = Session::get('user');
        if (!is_string($username)) {
            return null;
        }
        $lifetime = (int) ($this->config['session_lifetime'] ?? 7200);
        $last = (int) Session::get('last_seen', 0);
        $def = $this->definition($username);
        if ($def === null || ($lifetime > 0 && time() - $last > $lifetime)) {
            // Usuario quitado de la config o sesión caducada
            Session::reset();
            return null;
        }
        Session::set('last_seen', time());
        return $this->user = $this->makeUser($username, $def);
    }

    /** @return string|null mensaje de error, o null si ha entrado */
    public function attempt(string $username, string $password, string $ip): ?string
    {
        $max = max(1, (int) ($this->config['login_max_attempts'] ?? 5));
        $lockout = max(1, (int) ($this->config['login_lockout'] ?? 900));
        $keys = ['u:' . mb_strtolower($username), 'ip:' . $ip];

        $attempts = $this->store->read(self::ATTEMPTS);
        foreach ($keys as $k) {
            $until = (int) ($attempts[$k]['locked_until'] ?? 0);
            if ($until > time()) {
                return sprintf('Demasiados intentos. Vuelve a probar en %d minutos.', (int) ceil(($until - time()) / 60));
            }
        }

        $def = $this->definition($username);
        $hash = is_string($def['password'] ?? null) ? $def['password'] : null;
        // Con usuario inexistente también se calcula un hash: mismo tiempo de respuesta
        $ok = password_verify($password, $hash ?? self::DUMMY_HASH);

        $this->store->update(self::ATTEMPTS, function (array $all) use ($keys, $ok, $max, $lockout): array {
            $now = time();
            // Limpieza de entradas viejas
            $all = array_filter($all, static fn ($a) => ($a['last'] ?? 0) > $now - 86400);
            foreach ($keys as $k) {
                if ($ok) {
                    unset($all[$k]);
                    continue;
                }
                $count = (($all[$k]['locked_until'] ?? 0) > 0 && $all[$k]['locked_until'] <= $now) ? 1 : ($all[$k]['count'] ?? 0) + 1;
                $all[$k] = ['count' => $count, 'last' => $now, 'locked_until' => $count >= $max ? $now + $lockout : 0];
            }
            return $all;
        });

        if (!$ok || $def === null) {
            Log::warning('Inicio de sesión fallido', ['user' => mb_substr($username, 0, 50), 'ip' => $ip]);
            return 'Usuario o contraseña incorrectos';
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Log::info('La contraseña de {user} usa un algoritmo antiguo: genera un hash nuevo con bin/hash-password.php', ['user' => $username]);
        }

        Session::reset();
        Session::set('user', $username);
        Session::set('last_seen', time());
        Session::set('login_at', time());
        $this->user = $this->makeUser($username, $def);
        $this->loaded = true;
        return null;
    }

    public function logout(): void
    {
        Session::reset();
        $this->user = null;
    }

    private function definition(string $username): ?array
    {
        $users = (array) ($this->config['users'] ?? []);
        $def = $users[$username] ?? null;
        return is_array($def) && !empty($def['password']) && ($def['active'] ?? true) ? $def : null;
    }

    private function makeUser(string $username, array $def): User
    {
        $role = isset(User::ROLES[$def['role'] ?? '']) ? $def['role'] : 'viewer';
        return new User($username, (string) ($def['name'] ?? $username), $role, $def['email'] ?? null);
    }
}
