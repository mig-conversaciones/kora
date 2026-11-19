<?php

declare(strict_types=1);

namespace App\Repositorios;

/** Recaudos de la Dirección General (RF-035). Un recaudo sin unidad solo lo ve quien tiene alcance de centro. Toda consulta lleva el alcance de datos del usuario (RDI-003). */
final class RepositorioRecaudos extends RepositorioConAlcance
{
    protected const TABLA = 'recaudos';
    protected const ALIAS = 'rc';
    protected const ORDENABLES = ['fecha' => 'rc.fecha_pago', 'valor' => 'rc.valor', 'unidad' => 'rc.unidad_id', 'pasarela' => 'rc.pasarela'];
}
