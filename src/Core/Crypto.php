<?php
declare(strict_types=1);

namespace Cosmo\Core;

/**
 * Cifra los tokens de las instalaciones con la clave de config 'key' (32 bytes en base64).
 * Quien copie storage/ sin config.local.php no puede usar los tokens.
 *
 * Con la extensión sodium usa secretbox (enc1:); si el hosting no la tiene, AES-256-GCM
 * de OpenSSL (enc2:). Los dos formatos se pueden leer mientras esté su extensión.
 */
final class Crypto
{
    private const KEY_BYTES = 32;
    private const SODIUM = 'enc1:';
    private const OPENSSL = 'enc2:';
    private const GCM_IV = 12;
    private const GCM_TAG = 16;

    private readonly string $key;

    public function __construct(?string $base64Key)
    {
        if (!self::hasSodium() && !self::hasOpenssl()) {
            throw new ConfigException('Cosmo necesita la extensión sodium u openssl de PHP para cifrar los tokens');
        }
        $key = is_string($base64Key) ? base64_decode($base64Key, true) : false;
        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new ConfigException("La clave 'key' de config/config.local.php falta o no es válida: debe ser base64 de 32 bytes. "
                . "Genera una con: php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'");
        }
        $this->key = $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(self::KEY_BYTES));
    }

    public function encrypt(string $plain): string
    {
        if (self::hasSodium()) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return self::SODIUM . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
        }
        $iv = random_bytes(self::GCM_IV);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::GCM_TAG);
        if ($cipher === false) {
            throw new \RuntimeException('No se puede cifrar el token');
        }
        return self::OPENSSL . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $value): string
    {
        $isSodium = str_starts_with($value, self::SODIUM);
        if (!$isSodium && !str_starts_with($value, self::OPENSSL)) {
            throw new \RuntimeException('Token sin cifrar o con un formato desconocido');
        }
        $raw = base64_decode(substr($value, 5), true);
        if ($raw === false) {
            throw new \RuntimeException('Token cifrado dañado');
        }

        if ($isSodium) {
            if (!self::hasSodium()) {
                throw new ConfigException('Este token se cifró con sodium y el PHP actual no tiene esa extensión: actívala o vuelve a introducir el token');
            }
            $plain = strlen($raw) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ? sodium_crypto_secretbox_open(
                substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $this->key
            ) : false;
        } else {
            if (!self::hasOpenssl()) {
                throw new ConfigException('Este token se cifró con openssl y el PHP actual no tiene esa extensión');
            }
            $plain = strlen($raw) > self::GCM_IV + self::GCM_TAG ? openssl_decrypt(
                substr($raw, self::GCM_IV + self::GCM_TAG),
                'aes-256-gcm',
                $this->key,
                OPENSSL_RAW_DATA,
                substr($raw, 0, self::GCM_IV),
                substr($raw, self::GCM_IV, self::GCM_TAG)
            ) : false;
        }
        if ($plain === false) {
            throw new \RuntimeException('No se puede descifrar el token: ¿ha cambiado la clave de Cosmo?');
        }
        return $plain;
    }

    private static function hasSodium(): bool
    {
        return function_exists('sodium_crypto_secretbox');
    }

    private static function hasOpenssl(): bool
    {
        return function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }
}
