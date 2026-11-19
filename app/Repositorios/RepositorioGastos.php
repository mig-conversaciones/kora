<?php

declare(strict_types=1);

namespace App\Repositorios;

/** Gastos ejecutados (RF-025). Toda consulta lleva el alcance de datos del usuario (RDI-003). */
final class RepositorioGastos extends RepositorioConAlcance
{
    protected const TABLA = 'gastos_ejecutados';
    protected const ALIAS = 'g';
    protected const ORDENABLES = ['fecha' => 'g.fecha', 'valor' => 'g.valor', 'unidad' => 'g.unidad_id'];
}
