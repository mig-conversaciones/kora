<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Servicios\ServicioAdministracionCola;
use App\Servicios\ServicioMonitorCola;

/**
 * Panel de colas del Superadministrador (RF-Q-014): pendientes, reservados, completados y fallidos, antigüedad del
 * pendiente más viejo, duración media y latidos; reintentar, cancelar o descartar con motivo y auditoría.
 */
final class ControladorColas extends ControladorBase
{
    private const ACCIONES = ['reintentar', 'cancelar', 'descartar'];

    public function __construct(
        Vista $vista,
        private readonly BaseDatos $bd,
        private readonly ServicioMonitorCola $monitor,
        private readonly ServicioAdministracionCola $administracion,
    ) {
        parent::__construct($vista);
    }

    public function mostrar(Solicitud $solicitud): Respuesta
    {
        return $this->renderizar($solicitud, 'sistema/colas_pagina', 'sistema/colas', $this->datos() + [
            'titulo' => 'Colas de trabajos',
            'migas' => [['texto' => 'Sistema'], ['texto' => 'Colas de trabajos']],
        ]);
    }

    public function operar(Solicitud $solicitud, string $ulid, string $accion): Respuesta
    {
        if (!in_array($accion, self::ACCIONES, true)) {
            throw new ErrorHttp(404);
        }
        try {
            $this->administracion->{$accion}($solicitud, strtoupper($ulid), (string) ($solicitud->cuerpo['motivo'] ?? ''));
        } catch (ErrorValidacion $error) {
            return $this->respuestaValidacion($solicitud, $error, 'sistema/colas', $this->datos());
        }
        $mensajes = ['reintentar' => 'El trabajo volvió a la cola.', 'cancelar' => 'El trabajo se canceló.', 'descartar' => 'El fallo quedó marcado como revisado.'];
        return $this->conToast($this->renderizar($solicitud, 'sistema/colas', 'sistema/colas', $this->datos()), 'success', $mensajes[$accion]);
    }

    /** @return array<string,mixed> */
    private function datos(): array
    {
        return [
            'estadisticas' => $this->monitor->estadisticas(),
            'fallidos' => $this->bd->consultar(
                "SELECT t.ulid, t.tipo, t.intentos, f.clase_error, f.mensaje_error, f.fallido_en, f.revisado_en
                   FROM trabajos t JOIN trabajos_fallidos f ON f.trabajo_id = t.id
                  WHERE t.estado = 'fallido' ORDER BY f.fallido_en DESC LIMIT 25"
            ),
            'pendientes' => $this->bd->consultar(
                "SELECT ulid, tipo, prioridad, intentos, disponible_en, creado_en FROM trabajos
                  WHERE estado = 'pendiente' ORDER BY prioridad, disponible_en LIMIT 25"
            ),
            'valores' => [],
            'errores' => [],
        ];
    }
}
