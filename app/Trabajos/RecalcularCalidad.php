<?php

declare(strict_types=1);

namespace App\Trabajos;

use App\Cola\ContextoTrabajo;
use App\Cola\ErrorDefinitivo;
use App\Cola\ManejadorTrabajo;
use App\Servicios\ServicioCalidad;
use App\Servicios\ServicioVersionDatos;

/**
 * Recálculo de la bandeja de calidad de un centro (tipo calidad.recalcular; RF-058). Se encola tras cada
 * importación y desde la bandeja. Es idempotente: repetirlo deja el mismo resultado (RF-Q-010).
 */
final class RecalcularCalidad implements ManejadorTrabajo
{
    public function __construct(private readonly ServicioCalidad $calidad, private readonly ServicioVersionDatos $versiones)
    {
    }

    public function ejecutar(array $trabajo, ContextoTrabajo $contexto): array
    {
        $centro = (int) ($trabajo['centro_id'] ?? 0);
        if ($centro <= 0) {
            throw new ErrorDefinitivo('El recálculo de calidad necesita el centro.');
        }
        $contexto->latido(10, 'Detectando incidencias.');
        $abiertas = $this->calidad->recalcular($centro);
        $this->versiones->incrementar($centro);
        return ['abiertas' => $abiertas, 'total' => array_sum($abiertas)];
    }
}
