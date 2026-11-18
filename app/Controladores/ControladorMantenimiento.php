<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Servicios\ServicioMantenimiento;

/**
 * Panel Sistema → Mantenimiento (RF-MAN-001 a RF-MAN-004; FP-10). Solo con el permiso mantenimiento.gestionar
 * (Superadministrador). Activar y desactivar exigen CSRF, confirmación en el modal, reautenticación de 5 min o
 * menos (opción de ruta) y motivo. El alcance es global (RF-MAN-004).
 */
final class ControladorMantenimiento extends ControladorBase
{
    public function __construct(Vista $vista, private readonly ServicioMantenimiento $mantenimiento)
    {
        parent::__construct($vista);
    }

    public function mostrar(Solicitud $solicitud): Respuesta
    {
        return $this->renderizar($solicitud, 'sistema/mantenimiento_pagina', 'sistema/mantenimiento', $this->datos() + [
            'titulo' => 'Mantenimiento',
            'migas' => [['texto' => 'Sistema'], ['texto' => 'Mantenimiento']],
        ]);
    }

    public function activar(Solicitud $solicitud): Respuesta
    {
        try {
            $resultado = $this->mantenimiento->activar($solicitud, [
                'motivo' => $solicitud->cuerpo['motivo'] ?? '',
                'titulo' => $solicitud->cuerpo['titulo'] ?? '',
                'mensaje' => $solicitud->cuerpo['mensaje'] ?? '',
                'estimado_hasta' => self::estimado((string) ($solicitud->cuerpo['estimado_hasta'] ?? '')),
                'version' => (int) ($solicitud->cuerpo['version'] ?? 0),
            ]);
        } catch (ErrorValidacion $error) {
            return $this->respuestaValidacion($solicitud, $error, 'sistema/mantenimiento', $this->datos());
        }
        return $this->conToast($this->renderizar($solicitud, 'sistema/mantenimiento', 'sistema/mantenimiento', $this->datos()), 'warning', $resultado['mensaje']);
    }

    public function desactivar(Solicitud $solicitud): Respuesta
    {
        try {
            $resultado = $this->mantenimiento->desactivar($solicitud, [
                'motivo' => $solicitud->cuerpo['motivo'] ?? '',
                'version' => (int) ($solicitud->cuerpo['version'] ?? 0),
            ]);
        } catch (ErrorValidacion $error) {
            return $this->respuestaValidacion($solicitud, $error, 'sistema/mantenimiento', $this->datos());
        }
        $respuesta = $this->renderizar($solicitud, 'sistema/mantenimiento', 'sistema/mantenimiento', $this->datos() + ['comprobaciones' => $resultado['comprobaciones']]);
        return $this->conToast($respuesta, $resultado['exito'] ? 'success' : 'error', $resultado['mensaje']);
    }

    /** @return array<string,mixed> */
    private function datos(): array
    {
        $estado = $this->mantenimiento->estadoActual();
        return [
            'estado' => $estado,
            'activo' => $this->mantenimiento->activo(),
            'drenaje' => $this->mantenimiento->estadoDrenaje(),
            'comprobaciones' => $this->mantenimiento->verificarSalida(),
            'valores' => [],
            'errores' => [],
        ];
    }

    /** Fecha y hora locales del formulario (America/Bogota, RF-MAN-003) → ISO 8601 con zona; vacío si no hay. */
    private static function estimado(string $local): string
    {
        if ($local === '') {
            return '';
        }
        $fecha = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $local, new \DateTimeZone('America/Bogota'));
        return $fecha === false ? 'invalida' : $fecha->format(DATE_ATOM);
    }
}
