<?php

declare(strict_types=1);

namespace App\Trabajos;

use App\Cola\ContextoTrabajo;
use App\Cola\ErrorDefinitivo;
use App\Cola\ManejadorTrabajo;
use App\Importacion\ServicioImportaciones;

/** Tipo importacion.aplicar. Aplicación de un lote validado (RF-088). Cada fila aplicada queda marcada en su transacción: un reintento continúa desde ahí. */
final class AplicarImportacion implements ManejadorTrabajo
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
        $this->importaciones->aplicar($lote, $contexto);
        return ['lote_id' => $lote];
    }
}
