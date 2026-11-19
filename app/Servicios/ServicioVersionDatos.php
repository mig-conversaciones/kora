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
    /** Un tablero arma varias claves en la misma solicitud: la versión leída vale 1 s (y se olvida al incrementar). */
    private const MEMORIA_SEGUNDOS = 1.0;

    /** @var array<int,array{version:int,leida:float}> */
    private array $leidas = [];

    public function __construct(private readonly BaseDatos $bd)
    {
    }

    public function incrementar(int $centroId): void
    {
        unset($this->leidas[$centroId]);
        $this->bd->ejecutar(
            'INSERT INTO versiones_datos (centro_id, version) VALUES (:centro, 1) ON DUPLICATE KEY UPDATE version = version + 1',
            ['centro' => $centroId]
        );
    }

    public function actual(int $centroId): int
    {
        $memoria = $this->leidas[$centroId] ?? null;
        if ($memoria !== null && microtime(true) - $memoria['leida'] < self::MEMORIA_SEGUNDOS) {
            return $memoria['version'];
        }
        $version = (int) ($this->bd->valor('SELECT version FROM versiones_datos WHERE centro_id = :centro', ['centro' => $centroId]) ?? 0);
        $this->leidas[$centroId] = ['version' => $version, 'leida' => microtime(true)];
        return $version;
    }
}
