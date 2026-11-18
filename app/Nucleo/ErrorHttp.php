<?php

declare(strict_types=1);

namespace App\Nucleo;

use RuntimeException;

/** Error con código HTTP. El mensaje, si existe, ya está redactado para la persona usuaria (RUI-015). */
final class ErrorHttp extends RuntimeException
{
    public function __construct(public readonly int $estado, string $mensaje = '')
    {
        parent::__construct($mensaje, $estado);
    }
}
