<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Cola\DespachadorCola;
use App\Nucleo\Entrada;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Servicios\AutorizacionRecurso;
use App\Servicios\ServicioCalidad;
use App\Servicios\ServicioCatalogos;

/**
 * Bandeja de calidad de datos (RF-058, RF-059): incidencias por tipo dentro del alcance y sus acciones de corrección.
 */
final class ControladorCalidad extends ControladorModulo
{
    public function __construct(Vista $vista, private readonly ServicioCalidad $calidad, private readonly ServicioCatalogos $catalogos, private readonly DespachadorCola $despachador)
    {
        parent::__construct($vista);
    }

    public function bandeja(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'calidad/bandeja', $this->datosBandeja($solicitud), $this->meta());
    }

    /** Encola el recálculo (calidad.recalcular) y devuelve el seguimiento del trabajo (202). */
    public function recalcular(Solicitud $solicitud): Respuesta
    {
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $trabajo = $this->despachador->encolar('calidad.recalcular', [], [
            'actor_id' => $usuario->id,
            'centro_id' => ServicioCatalogos::centro($solicitud),
            'clave_idempotencia' => DespachadorCola::claveIdempotencia($solicitud),
        ]);
        return $this->despachador->respuestaAceptada($solicitud, $trabajo, '/trabajos/' . $trabajo['ulid']);
    }

    public function vincularRecepcion(Solicitud $solicitud, string $id): Respuesta
    {
        return $this->accion($solicitud, $id, function () use ($solicitud, $id): void {
            $this->calidad->vincularRecepcion($solicitud, (int) $id, Entrada::entero($solicitud->cuerpo['recepcion_id'] ?? '', 1) ?? 0);
        }, 'Salida vinculada al lote.');
    }

    public function asignarProceso(Solicitud $solicitud, string $id): Respuesta
    {
        return $this->accion($solicitud, $id, function () use ($solicitud, $id): void {
            $this->calidad->asignarProceso($solicitud, (int) $id, Entrada::entero($solicitud->cuerpo['proceso_id'] ?? '', 1) ?? 0);
        }, 'Entrega vinculada al proceso.');
    }

    public function aceptar(Solicitud $solicitud, string $id): Respuesta
    {
        return $this->accion($solicitud, $id, function () use ($solicitud, $id): void {
            $this->calidad->aceptar($solicitud, (int) $id, (string) ($solicitud->cuerpo['motivo'] ?? ''));
        }, 'Incidencia aceptada sin corrección; queda en la auditoría.');
    }

    private function accion(Solicitud $solicitud, string $id, callable $operacion, string $mensaje): Respuesta
    {
        try {
            $operacion();
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'calidad/bandeja', ['incidenciaConError' => (int) $id] + $this->datosBandeja($solicitud), $this->meta());
        }
        return $this->trasGuardar($this->bandeja($solicitud), '/calidad', $mensaje);
    }

    /** @return array<string,mixed> */
    private function datosBandeja(Solicitud $solicitud): array
    {
        $tipo = (string) ($solicitud->consulta['tipo'] ?? '');
        $estado = (string) ($solicitud->consulta['estado'] ?? 'abierta');
        $filtros = [
            'tipo' => isset(ServicioCalidad::TIPOS[$tipo]) ? $tipo : null,
            'unidad' => $this->enteroConsulta($solicitud, 'unidad'),
            'estado' => in_array($estado, ['abierta', 'resuelta', 'aceptada'], true) ? $estado : 'abierta',
        ];
        $pagina = $this->enteroConsulta($solicitud, 'pagina', 1) ?? 1;
        $nombres = [];
        foreach ($this->catalogos->unidades(ServicioCatalogos::centro($solicitud)) as $u) {
            $nombres[(string) $u['id']] = (string) $u['nombre'];
        }
        $usuario = AutorizacionRecurso::usuario($solicitud);
        return $this->calidad->bandeja($solicitud, $filtros, $pagina) + [
            'filtros' => $filtros,
            'pagina' => $pagina,
            'tipos' => ServicioCalidad::TIPOS,
            'unidades' => $this->catalogos->unidadesDelAlcance($solicitud),
            'nombresUnidades' => $nombres,
            'puedeCorregir' => $usuario->tienePermiso('calidad.gestionar'),
            'incidenciaConError' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function meta(): array
    {
        return [
            'titulo' => 'Calidad de datos',
            'sobretitulo' => 'Mi gestión',
            'descripcion' => 'Registros que los indicadores no pueden usar todavía: salidas sin lote, entregas sin proceso, recaudos sin clasificar…',
            'pestanas' => [],
            'migas' => [['texto' => 'Calidad de datos']],
        ];
    }
}
