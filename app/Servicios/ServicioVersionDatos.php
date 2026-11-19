<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;

/**
 * Versión de los datos del centro (ADR-010). Cada escritura relevante la incrementa dentro de su transacción, de
 * modo que las claves de caché de los tableros (I6) cambian en el mismo instante en que cambia el dato.
 */
final class ServicioVersionDatos
{
    public function __construct(private readonly BaseDatos $bd)
    {
    }

    public function incrementar(int $centroId): void
    {
        $this->bd->ejecutar(
            'INSERT INTO versiones_datos (centro_id, version) VALUES (:centro, 1) ON DUPLICATE KEY UPDATE version = version + 1',
            ['centro' => $centroId]
        );
    }

    public function actual(int $centroId): int
    {
        return (int) ($this->bd->valor('SELECT version FROM versiones_datos WHERE centro_id = :centro', ['centro' => $centroId]) ?? 0);
    }
}
