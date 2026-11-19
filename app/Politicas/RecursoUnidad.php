<?php

declare(strict_types=1);

namespace App\Politicas;

/**
 * Recurso de negocio que pertenece a una unidad productiva de un centro (asignación, gasto, proceso…). Lo construye
 * el servicio con los datos del servidor —la unidad del formulario para crear, la del registro guardado para
 * editar— y lo pasa al Autorizador para la decisión ABAC (RDI-003).
 */
final class RecursoUnidad
{
    public function __construct(public readonly int $centroId, public readonly int $unidadId)
    {
    }
}
