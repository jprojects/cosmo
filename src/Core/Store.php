<?php
declare(strict_types=1);

namespace Cosmo\Core;

/**
 * Ficheros JSON y JSONL en storage/data. Cosmo no usa base de datos: los datos son pocos
 * (decenas de instalaciones) y así el panel se instala copiando una carpeta.
 */
final class Store
{
    public function __construct(private readonly string $dir)
    {
        self::ensureDir($dir);
    }

    public function path(string $name): string
    {
        if (!preg_match('~^[a-z0-9_-]+(/[a-z0-9_-]+)*\.(json|jsonl)$~', $name)) {
            throw new \InvalidArgumentException("Nombre de fichero no válido: $name");
        }
        return $this->dir . '/' . $name;
    }

    public function read(string $name, mixed $default = []): mixed
    {
        $file = $this->path($name);
        if (!is_file($file)) {
            return $default;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return $data ?? $default;
    }

    /** Escritura atómica: fichero temporal + rename. */
    public function write(string $name, mixed $data): void
    {
        $file = $this->path($name);
        self::ensureDir(dirname($file));
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("No se puede escribir $file");
        }
    }

    /**
     * Lee, modifica y guarda con bloqueo exclusivo (para cambios concurrentes: cron + panel).
     * @param \Closure(mixed):mixed $fn recibe el valor actual y devuelve el nuevo
     */
    public function update(string $name, \Closure $fn, mixed $default = []): mixed
    {
        $lock = fopen($this->path($name) . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException("No se puede bloquear $name");
        }
        try {
            flock($lock, LOCK_EX);
            $value = $fn($this->read($name, $default));
            $this->write($name, $value);
            return $value;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function delete(string $name): void
    {
        $file = $this->path($name);
        if (is_file($file)) {
            unlink($file);
        }
    }

    public function append(string $name, array $row, int $maxBytes = 0): void
    {
        $file = $this->path($name);
        self::ensureDir(dirname($file));
        if ($maxBytes > 0 && is_file($file) && filesize($file) > $maxBytes) {
            $this->trim($file, (int) ($maxBytes / 2));
        }
        file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Últimas filas de un JSONL, la más reciente primero.
     * @return list<array<string,mixed>>
     */
    public function tail(string $name, int $limit = 200, int $maxBytes = 2_097_152): array
    {
        $file = $this->path($name);
        if (!is_file($file)) {
            return [];
        }
        $fh = fopen($file, 'rb');
        $size = (int) filesize($file);
        if ($size > $maxBytes) {
            fseek($fh, -$maxBytes, SEEK_END);
            fgets($fh);
        }
        $rows = [];
        while (($line = fgets($fh)) !== false) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        fclose($fh);
        return array_slice(array_reverse($rows), 0, $limit);
    }

    /** Se queda con la segunda mitad del fichero (aprox. $keepBytes). */
    private function trim(string $file, int $keepBytes): void
    {
        $fh = fopen($file, 'c+b');
        if (!$fh || !flock($fh, LOCK_EX)) {
            return;
        }
        fseek($fh, -min($keepBytes, (int) filesize($file)), SEEK_END);
        fgets($fh);
        $rest = (string) stream_get_contents($fh);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $rest);
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("No se puede crear $dir");
        }
    }
}
