<?php

declare(strict_types=1);

namespace App\Nucleo;

use RuntimeException;

/** Errores de validación por campo. El controlador los convierte en un 422 que conserva lo escrito (RNF-005). */
final class ErrorValidacion extends RuntimeException
{
    /** @param array<string,string> $errores campo => mensaje */
    public function __construct(private readonly array $errores)
    {
        parent::__construct('Datos inválidos.', 422);
    }

    /** @return array<string,string> */
    public function errores(): array
    {
        return $this->errores;
    }
}
