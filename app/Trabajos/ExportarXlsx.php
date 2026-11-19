<?php

declare(strict_types=1);

namespace App\Trabajos;

use App\Cola\ContextoTrabajo;
use App\Cola\ErrorDefinitivo;
use App\Cola\ManejadorTrabajo;
use App\Tableros\ServicioExportacion;

/**
 * Exportación en XLSX o CSV (tipo exportacion.xlsx; RF-080).
 * La identidad y el alcance del autor se releen al ejecutar (RAC-024). Idempotente: si el archivo ya existe, no se
 * vuelve a generar.
 */
final class ExportarXlsx implements ManejadorTrabajo
{
    public function __construct(private readonly ServicioExportacion $exportaciones)
    {
    }

    public function ejecutar(array $trabajo, ContextoTrabajo $contexto): array
    {
        $id = (int) ($trabajo['carga']['exportacion_id'] ?? 0);
        if ($id <= 0) {
            throw new ErrorDefinitivo('Falta la exportación.');
        }
        $contexto->latido(10, 'Calculando las cifras con tus filtros.');
        $resultado = $this->exportaciones->generar($id);
        $contexto->latido(100, 'Archivo listo para descargar.');
        return $resultado;
    }
}
