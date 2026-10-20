<?php
declare(strict_types=1);

namespace Cosmo\Core;

/**
 * Cifra los tokens de las instalaciones con la clave de config 'key' (libsodium, secretbox).
 * Quien copie storage/ sin config.local.php no puede usar los tokens.
 */
final class Crypto
{
    private const PREFIX = 'enc1:';

    private readonly string $key;

    public function __construct(?string $base64Key)
    {
        $key = is_string($base64Key) ? base64_decode($base64Key, true) : false;
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException("Falta la clave de Cosmo ('key' en config/config.local.php). Ejecuta php bin/setup.php");
        }
        $this->key = $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $value): string
    {
        if (!str_starts_with($value, self::PREFIX)) {
            throw new \RuntimeException('Token sin cifrar o con un formato desconocido');
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Token cifrado dañado');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );
        if ($plain === false) {
            throw new \RuntimeException('No se puede descifrar el token: ¿ha cambiado la clave de Cosmo?');
        }
        return $plain;
    }
}
