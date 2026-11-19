<?php

declare(strict_types=1);

namespace App\Nucleo;

use RuntimeException;

/** Error con código HTTP. El mensaje, si existe, ya está redactado para la persona usuaria (RUI-015). */
final class ErrorHttp extends RuntimeException
{
    /** @param string $titulo reemplaza el título genérico del estado («Periodo cerrado» en lugar de «El registro cambió») */
    public function __construct(public readonly int $estado, string $mensaje = '', public readonly string $titulo = '')
    {
        parent::__construct($mensaje, $estado);
    }
}
