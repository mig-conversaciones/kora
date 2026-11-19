<?php

declare(strict_types=1);

namespace App\Politicas;

/**
 * Cuenta sobre la que se actúa en la gestión de usuarios (RF-009, RF-012). $esSuperadministrador indica si la cuenta
 * objetivo es, o pasaría a ser, superadministradora.
 */
final class RecursoUsuario
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $centroId,
        public readonly bool $esSuperadministrador,
    ) {
    }
}
