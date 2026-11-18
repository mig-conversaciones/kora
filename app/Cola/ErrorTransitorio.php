<?php

declare(strict_types=1);

namespace App\Cola;

use RuntimeException;

/** Fallo recuperable (red, SMTP, bloqueo): se reintenta con espera exponencial o la sugerida por el proveedor. */
final class ErrorTransitorio extends RuntimeException
{
    public function __construct(string $mensaje, public readonly ?int $retardoSugerido = null)
    {
        parent::__construct($mensaje);
    }
}
