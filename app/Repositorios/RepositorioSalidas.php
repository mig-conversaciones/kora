<?php

declare(strict_types=1);

namespace App\Repositorios;

/** Salidas de venta directa y autoconsumo (RF-043 a RF-046). Toda consulta lleva el alcance de datos del usuario (RDI-003). */
final class RepositorioSalidas extends RepositorioConAlcance
{
    protected const TABLA = 'salidas';
    protected const ALIAS = 's';
    protected const ORDENABLES = ['fecha' => 's.fecha', 'valor' => 's.valor', 'unidad' => 's.unidad_id', 'canal' => 's.canal'];
}
