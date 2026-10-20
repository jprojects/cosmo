<?php
declare(strict_types=1);

namespace Cosmo\Core;

final class HttpException extends \RuntimeException
{
    private const MESSAGES = [
        400 => 'Petición incorrecta',
        401 => 'Tu sesión ha caducado. Vuelve a iniciar sesión.',
        403 => 'No tienes permiso para hacer esto',
        404 => 'No encontrado',
        405 => 'Método no permitido',
        419 => 'El formulario ha caducado. Recarga la página e inténtalo de nuevo.',
        500 => 'Se ha producido un error interno',
    ];

    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : (self::MESSAGES[$status] ?? 'Error'));
    }
}
