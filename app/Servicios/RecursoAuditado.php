<?php

declare(strict_types=1);

namespace App\Servicios;

/**
 * Registro de negocio tal como se anota en la auditoría: tipo (tabla o entidad), id y, si aplica, la unidad
 * (columna unidad_id del evento, para filtrar la consulta de auditoría por unidad, RAU-001).
 */
final class RecursoAuditado
{
    public function __construct(public readonly string $tipo, public readonly int|string $id, public readonly ?int $unidadId = null)
    {
    }
}
