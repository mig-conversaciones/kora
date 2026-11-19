<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Entrada;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Servicios\AutorizacionRecurso;
use App\Tableros\FiltrosTablero;
use App\Tableros\ServicioExportacion;
use App\Tableros\ServicioIndicadores;

/**
 * Exportaciones (RF-080 a RF-082): la solicitud responde 202 con un fragmento que sigue el trabajo; al terminar ofrece
 * la descarga, que solo funciona para su autor durante 24 horas y siempre con Cache-Control: no-store.
 */
final class ControladorExportaciones extends ControladorBase
{
    /** @param list<int> $esperas */
    public function __construct(
        Vista $vista,
        private readonly ServicioExportacion $exportaciones,
        private readonly ServicioIndicadores $indicadores,
        private readonly array $esperas,
    ) {
        parent::__construct($vista);
    }

    public function solicitar(Solicitud $solicitud): Respuesta
    {
        $tipo = (string) ($solicitud->cuerpo['tipo'] ?? '');
        $formato = (string) ($solicitud->cuerpo['formato'] ?? '');
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $unidades = array_map(static fn (array $u): int => (int) $u['id'], $this->indicadores->unidadesDelAlcance($alcance));
        $f = FiltrosTablero::desdeConsulta($solicitud->cuerpo, $unidades, (int) date('Y'));
        $filtros = $f->aArreglo();
        if ($tipo === 'tablero_unidad') {
            $unidad = Entrada::entero($solicitud->cuerpo['unidad_tecnica'] ?? '', 1);
            if ($unidad === null || !in_array($unidad, $unidades, true)) {
                throw new ErrorHttp(404);
            }
            $filtros['unidad'] = null;
            $filtros['unidad_tecnica'] = $unidad;
            $filtros['practica'] = ($solicitud->cuerpo['practica'] ?? '') === '1';
        }
        try {
            if (!$f->esValido()) {
                throw new ErrorValidacion($f->errores);
            }
            $exportacion = $this->exportaciones->solicitar($solicitud, $tipo, $formato, $filtros);
        } catch (ErrorValidacion $error) {
            $respuesta = $this->renderizar($solicitud, 'exportaciones/pagina', 'exportaciones/error', ['mensaje' => implode(' ', $error->errores())], 422);
            return $this->conToast($respuesta, 'error', 'No se pudo pedir la exportación.');
        }
        $fila = $this->exportaciones->propia($solicitud, (string) $exportacion['ulid']);
        $respuesta = $this->renderizar($solicitud, 'exportaciones/pagina', 'exportaciones/estado', ['exportacion' => $fila, 'espera' => $this->esperas[0], 'intento' => 1], 202);
        return $this->conToast($respuesta, 'info', 'Estamos preparando el archivo; te avisaremos aquí cuando esté listo.');
    }

    public function estado(Solicitud $solicitud, string $ulid): Respuesta
    {
        $fila = $this->exportaciones->propia($solicitud, $ulid) ?? throw new ErrorHttp(404);
        $indice = min(count($this->esperas) - 1, max(0, (int) ($solicitud->consulta['intento'] ?? 0)));
        $respuesta = $this->renderizar($solicitud, 'exportaciones/pagina', 'exportaciones/estado', ['exportacion' => $fila, 'espera' => $this->esperas[$indice], 'intento' => $indice + 1]);
        if ($solicitud->esHtmx() && ($fila['estado'] ?? '') === 'completado') {
            $respuesta = $this->conToast($respuesta, 'success', 'El archivo está listo para descargar.');
        }
        return $respuesta;
    }

    public function descargar(Solicitud $solicitud, string $ulid): Respuesta
    {
        $archivo = $this->exportaciones->descargar($solicitud, $ulid);
        $seguro = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $archivo['nombre']);
        return Respuesta::html((string) file_get_contents($archivo['ruta']))
            ->conCabecera('Content-Type', $archivo['tipo'])
            ->conCabecera('Content-Disposition', 'attachment; filename="' . $seguro . '"')
            ->conCabecera('X-Content-Type-Options', 'nosniff')
            ->conCabecera('Cache-Control', 'private, no-store');
    }
}
