<?php

declare(strict_types=1);

namespace App\Repositorios;

/** Procesos de autoconsumo (RF-036). Toda consulta lleva el alcance de datos del usuario (RDI-003). */
final class RepositorioProcesos extends RepositorioConAlcance
{
    protected const TABLA = 'procesos_autoconsumo';
    protected const ALIAS = 'p';
    protected const ORDENABLES = ['codigo' => 'p.codigo', 'vigencia' => 'p.vigencia', 'unidad' => 'p.unidad_id'];
}
