#!/usr/bin/env php
<?php
/**
 * Token de la API de Cosmo (?r=api) para un usuario, el que usa el MCP (bin/mcp.php).
 *
 *   php bin/api-token.php kim
 *
 * Enseña el token (una sola vez: guárdalo en ~/.config/cosmo-mcp/token del ordenador donde corre el MCP) y la línea
 * que va en el usuario de config/config.local.php: 'api_token' => '…sha256…'. Cosmo solo guarda el sha256.
 * El token tiene los permisos del rol del usuario. Para revocarlo, quita o cambia esa línea.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap.php';

$username = $argv[1] ?? '';
$users = (array) ($config['users'] ?? []);
if ($username === '' || !isset($users[$username])) {
    fwrite(STDERR, "Uso: php bin/api-token.php <usuario>\nUsuarios: " . (implode(', ', array_keys($users)) ?: '(ninguno)') . "\n");
    exit(1);
}

$token = 'cs_' . bin2hex(random_bytes(32));
echo "Token (guárdalo ahora, no se vuelve a enseñar):\n\n  $token\n\n";
echo "En config/config.local.php, dentro del usuario '$username':\n\n  'api_token' => '" . hash('sha256', $token) . "',\n\n";
echo "Rol: " . ($users[$username]['role'] ?? 'viewer') . " (para actualizar hace falta operator o admin)\n";
