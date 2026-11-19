<?php

declare(strict_types=1);

namespace App\Repositorios;

/** Asignaciones presupuestales (RF-024). Toda consulta lleva el alcance de datos del usuario (RDI-003). */
final class RepositorioAsignaciones extends RepositorioConAlcance
{
    protected const TABLA = 'asignaciones_presupuestales';
    protected const ALIAS = 'a';
    protected const ORDENABLES = ['vigencia' => 'a.vigencia', 'unidad' => 'a.unidad_id', 'total' => 'a.valor_total'];
}
