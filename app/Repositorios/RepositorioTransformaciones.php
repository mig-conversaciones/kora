<?php

declare(strict_types=1);

namespace App\Repositorios;

/** Transformaciones lácteas, con el alcance por la unidad de origen de la leche (RF-047 a RF-051). Toda consulta lleva el alcance de datos del usuario (RDI-003). */
final class RepositorioTransformaciones extends RepositorioConAlcance
{
    protected const TABLA = 'transformaciones';
    protected const ALIAS = 't';
    protected const ORDENABLES = ['fecha' => 't.fecha', 'litros' => 't.litros_asignados'];
}
