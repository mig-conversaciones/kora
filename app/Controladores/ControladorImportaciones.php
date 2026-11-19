<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Importacion\ServicioImportaciones;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Servicios\AutorizacionRecurso;
use App\Servicios\RecursoAuditado;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioHato;

/**
 * Importaciones asistidas (RF-083 a RF-094): carga del libro, elección de fuentes, vista previa, aplicación,
 * reversión, reporte de errores, plantillas, historial y acta de carga.
 */
final class ControladorImportaciones extends ControladorModulo
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        Vista $vista,
        private readonly ServicioImportaciones $importaciones,
        private readonly ServicioHato $hato,
        private readonly ServicioAuditoria $auditoria,
    ) {
        parent::__construct($vista);
    }

    public function inicio(Solicitud $solicitud): Respuesta
    {
        $this->exigirAlguna($solicitud);
        return $this->pantalla($solicitud, 'importaciones/inicio', $this->datosInicio($solicitud), $this->meta('Importaciones', 'Carga de los libros originales o de las plantillas: se valida todo y nada cambia hasta que confirmas.'));
    }

    public function cargar(Solicitud $solicitud): Respuesta
    {
        $this->exigirAlguna($solicitud);
        try {
            $carga = $this->importaciones->cargar($solicitud, $solicitud->archivos['archivo'] ?? null);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'importaciones/inicio', $this->datosInicio($solicitud), $this->meta('Importaciones'));
        }
        return $this->pantalla($solicitud, 'importaciones/seleccion', $carga + ['unidadesHato' => $this->hato->unidadesPecuarias($solicitud)], $this->meta('Elegir las fuentes'));
    }

    public function crear(Solicitud $solicitud): Respuesta
    {
        $this->exigirAlguna($solicitud);
        try {
            $ulids = $this->importaciones->crearLotes($solicitud, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            $token = (string) ($solicitud->cuerpo['token'] ?? '');
            return $this->validacion($solicitud, $error, 'importaciones/inicio', $this->datosInicio($solicitud) + ['tokenFallido' => $token !== ''], $this->meta('Importaciones'));
        }
        return $this->trasGuardar($this->inicio($solicitud), '/importaciones', count($ulids) === 1 ? 'Lote creado: se está validando.' : count($ulids) . ' lotes creados: se están validando.');
    }

    public function detalle(Solicitud $solicitud, string $ulid): Respuesta
    {
        $lote = $this->importaciones->lote($solicitud, $ulid);
        return $this->pantalla($solicitud, 'importaciones/detalle', $this->datosDetalle($solicitud, $lote), $this->meta('Lote ' . $this->importaciones->registro()->fuente((string) $lote['fuente'])->nombre()));
    }

    /** Fragmento del estado del lote, consultado periódicamente mientras se valida o aplica (RF-085). */
    public function estado(Solicitud $solicitud, string $ulid): Respuesta
    {
        $lote = $this->importaciones->lote($solicitud, $ulid);
        $progreso = $lote['trabajo_id'] === null ? null : $this->importaciones->progreso((int) $lote['trabajo_id']);
        return $this->renderizar($solicitud, 'importaciones/estado', 'importaciones/estado', ['lote' => $lote, 'progreso' => $progreso]);
    }

    public function aplicar(Solicitud $solicitud, string $ulid): Respuesta
    {
        try {
            $this->importaciones->solicitarAplicacion($solicitud, $ulid, ($solicitud->cuerpo['solo_validas'] ?? '') === '1');
        } catch (ErrorValidacion $error) {
            $lote = $this->importaciones->lote($solicitud, $ulid);
            return $this->validacion($solicitud, $error, 'importaciones/detalle', $this->datosDetalle($solicitud, $lote), $this->meta('Lote'));
        }
        return $this->trasGuardar($this->detalle($solicitud, $ulid), '/importaciones/' . $ulid, 'Aplicación en curso: verás el resultado aquí y en tus notificaciones.');
    }

    public function revertir(Solicitud $solicitud, string $ulid): Respuesta
    {
        try {
            $this->importaciones->solicitarReversion($solicitud, $ulid, (string) ($solicitud->cuerpo['motivo'] ?? ''));
        } catch (ErrorValidacion $error) {
            $lote = $this->importaciones->lote($solicitud, $ulid);
            return $this->validacion($solicitud, $error, 'importaciones/detalle', $this->datosDetalle($solicitud, $lote), $this->meta('Lote'));
        }
        return $this->trasGuardar($this->detalle($solicitud, $ulid), '/importaciones/' . $ulid, 'Reversión en curso; queda auditada con su motivo.');
    }

    public function errores(Solicitud $solicitud, string $ulid): Respuesta
    {
        $lote = $this->importaciones->lote($solicitud, $ulid);
        $temporal = (string) tempnam(sys_get_temp_dir(), 'kora-errores-');
        try {
            $this->importaciones->reporteErrores($lote, $temporal);
            $contenido = (string) file_get_contents($temporal);
        } finally {
            @unlink($temporal);
        }
        return $this->descarga($contenido, 'errores-' . $lote['fuente'] . '-' . substr((string) $lote['ulid'], -6) . '.xlsx');
    }

    /** Descarga del archivo original, solo para quien puede ver el lote; queda auditada (RF-093). */
    public function original(Solicitud $solicitud, string $ulid): Respuesta
    {
        $lote = $this->importaciones->lote($solicitud, $ulid);
        $ruta = $this->importaciones->rutaOriginal($lote);
        if (!is_file($ruta)) {
            throw new ErrorHttp(404);
        }
        $this->auditoria->registrar($solicitud, 'importaciones.descargar_original', 'exito', new RecursoAuditado('lotes_importacion', (int) $lote['id']), ['fuente' => $lote['fuente']]);
        return $this->descarga((string) file_get_contents($ruta), (string) $lote['nombre_archivo']);
    }

    public function plantilla(Solicitud $solicitud, string $fuente): Respuesta
    {
        $definicion = $this->importaciones->registro()->todas()[$fuente] ?? throw new ErrorHttp(404);
        if (!AutorizacionRecurso::usuario($solicitud)->tienePermiso($definicion->permiso())) {
            throw new ErrorHttp(403);
        }
        $temporal = (string) tempnam(sys_get_temp_dir(), 'kora-plantilla-');
        try {
            $this->importaciones->plantilla($fuente, $temporal);
            $contenido = (string) file_get_contents($temporal);
        } finally {
            @unlink($temporal);
        }
        return $this->descarga($contenido, 'plantilla-' . $fuente . '.xlsx');
    }

    /** Acta de carga (RF-094): cifras de control de cada lote aplicado frente a las del archivo. */
    public function acta(Solicitud $solicitud): Respuesta
    {
        $this->exigirAlguna($solicitud);
        $lotes = array_values(array_filter($this->importaciones->historial($solicitud), static fn (array $l): bool => in_array($l['estado'], ['aplicado', 'revertido'], true)));
        return $this->pantalla($solicitud, 'importaciones/acta', ['lotes' => $lotes, 'fuentes' => $this->importaciones->registro()->todas()], $this->meta('Acta de carga', 'Cifras de control de cada lote aplicado comparadas con las calculadas del archivo.'));
    }

    private function exigirAlguna(Solicitud $solicitud): void
    {
        if (!ServicioImportaciones::puedeImportar($solicitud)) {
            throw new ErrorHttp(403);
        }
    }

    private function descarga(string $contenido, string $nombre): Respuesta
    {
        $seguro = preg_replace('/[^A-Za-z0-9._ -]/', '_', $nombre) ?: 'archivo.xlsx';
        return (new Respuesta($contenido, 200))
            ->conCabecera('Content-Type', self::XLSX)
            ->conCabecera('Content-Disposition', 'attachment; filename="' . $seguro . '"; filename*=UTF-8\'\'' . rawurlencode($nombre))
            ->conCabecera('X-Content-Type-Options', 'nosniff')
            ->conCabecera('Cache-Control', 'private, no-store');
    }

    /** @return array<string,mixed> */
    private function datosInicio(Solicitud $solicitud): array
    {
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $plantillas = [];
        foreach ($this->importaciones->registro()->todas() as $clave => $fuente) {
            if ($usuario->tienePermiso($fuente->permiso())) {
                $plantillas[$clave] = $fuente->nombre();
            }
        }
        return ['lotes' => $this->importaciones->historial($solicitud), 'plantillas' => $plantillas, 'fuentes' => $this->importaciones->registro()->todas(), 'tokenFallido' => false];
    }

    /**
     * @param array<string,mixed> $lote
     * @return array<string,mixed>
     */
    private function datosDetalle(Solicitud $solicitud, array $lote): array
    {
        $fuente = $this->importaciones->registro()->fuente((string) $lote['fuente']);
        return [
            'lote' => $lote,
            'fuente' => $fuente,
            'resumen' => json_decode((string) ($lote['resumen'] ?? '{}'), true) ?: [],
            'cifras' => json_decode((string) ($lote['cifras_control'] ?? '{}'), true) ?: [],
            'observaciones' => $this->importaciones->filasConObservaciones((int) $lote['id']),
            'bloqueos' => $lote['estado'] === 'aplicado' ? $this->importaciones->bloqueosReversion($lote) : [],
            'puedeOperar' => AutorizacionRecurso::usuario($solicitud)->tienePermiso($fuente->permiso()),
        ];
    }

    /** @return array<string,mixed> */
    private function meta(string $titulo, string $descripcion = ''): array
    {
        return [
            'titulo' => $titulo, 'sobretitulo' => 'Importación de BBDD', 'descripcion' => $descripcion,
            'pestanas' => [['texto' => 'Lotes', 'ruta' => '/importaciones'], ['texto' => 'Acta de carga', 'ruta' => '/importaciones/acta']],
            'migas' => [['texto' => 'Importaciones', 'url' => '/importaciones'], ['texto' => $titulo]],
        ];
    }
}
