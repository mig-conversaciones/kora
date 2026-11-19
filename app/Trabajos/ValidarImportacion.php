<?php

declare(strict_types=1);

namespace App\Trabajos;

use App\Cola\ContextoTrabajo;
use App\Cola\ErrorDefinitivo;
use App\Cola\ManejadorTrabajo;
use App\Importacion\ServicioImportaciones;

/** Tipo importacion.validar. Validación en streaming de un lote (RF-085, RF-086). Idempotente: borra y recalcula las filas del lote. */
final class ValidarImportacion implements ManejadorTrabajo
{
    public function __construct(private readonly ServicioImportaciones $importaciones)
    {
    }

    public function ejecutar(array $trabajo, ContextoTrabajo $contexto): array
    {
        $lote = (int) ($trabajo['carga']['lote_id'] ?? 0);
        if ($lote <= 0) {
            throw new ErrorDefinitivo('El trabajo no indica el lote de importación.');
        }
        $this->importaciones->validar($lote, $contexto);
        return ['lote_id' => $lote];
    }
}
