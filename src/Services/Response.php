<?php
declare(strict_types=1);

namespace Cosmo\Services;

/** Respuesta de una instalación. */
final class Response
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly ?array $data,
        public readonly ?string $error,
        public readonly int $ms = 0,
    ) {
    }

    public function toArray(): array
    {
        return ['ok' => $this->ok, 'status' => $this->status, 'error' => $this->error, 'ms' => $this->ms, 'data' => $this->data];
    }
}
