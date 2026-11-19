<?php

declare(strict_types=1);

namespace App\Repositorios;

/** Recepciones de lotes (RF-039 a RF-041). Toda consulta lleva el alcance de datos del usuario (RDI-003). */
final class RepositorioRecepciones extends RepositorioConAlcance
{
    protected const TABLA = 'recepciones_lote';
    protected const ALIAS = 'r';
    protected const ORDENABLES = ['numero' => 'r.numero_interno', 'fecha' => 'r.fecha_recepcion', 'unidad' => 'r.unidad_id', 'cantidad' => 'r.cantidad'];
}
