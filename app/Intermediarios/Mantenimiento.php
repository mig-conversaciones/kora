<?php

declare(strict_types=1);

namespace App\Intermediarios;

use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Solicitud;
use App\Repositorios\RepositorioUsuarios;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioMantenimiento;

/**
 * Aplica el modo mantenimiento a páginas, API, fragmentos HTMX y escrituras, incluso con sesiones abiertas
 * (RF-MAN-006; base 06-03). La señal se lee en cada solicitud, sin caché entre solicitudes (RF-MAN-013).
 *
 * El acceso excepcional exige sesión autenticada con 2FA y el permiso explícito mantenimiento.acceso_excepcional;
 * no se acepta ninguna otra vía: parámetros, cabeceras, cookies no validadas, rutas adivinables ni IP (RF-MAN-009,
 * RF-MAN-011). Las rutas exceptuadas (ingreso, 2FA, salud, el propio panel) continúan, pero la autenticación y la
 * autorización se siguen aplicando más adelante en la cadena.
 */
final class Mantenimiento implements Intermediario
{
    public function __construct(
        private readonly ServicioMantenimiento $mantenimiento,
        private readonly Sesion $sesion,
        private readonly RepositorioUsuarios $usuarios,
        private readonly ServicioAuditoria $auditoria,
    ) {
    }

    public function procesar(Solicitud $solicitud, callable $siguiente): Respuesta
    {
        $senal = $this->mantenimiento->senalActual();
        if ($senal === null) {
            return $siguiente($solicitud);
        }
        /** @var array{opciones?:array<string,mixed>} $ruta */
        $ruta = $solicitud->atributo('ruta', []);
        if (!empty($ruta['opciones']['exceptuadaMantenimiento']) || ServicioMantenimiento::rutaExceptuada($solicitud->ruta)) {
            return $siguiente($solicitud->conAtributo('mantenimiento', $senal));
        }
        $this->sesion->cargar($solicitud);
        $usuarioId = $this->sesion->usuarioId();
        $usuario = $usuarioId === null ? null : $this->usuarios->identidad($usuarioId);
        if ($usuario !== null && $this->sesion->nivel() === '2fa' && $usuario->tienePermiso('mantenimiento.acceso_excepcional')) {
            if (!$this->sesion->obtener('_acceso_mantenimiento_auditado')) {
                $this->auditoria->registrar($solicitud->conAtributo('usuario', $usuario), 'mantenimiento.acceso_excepcional', 'exito', null, ['ruta' => $solicitud->ruta]);
                $this->sesion->guardar('_acceso_mantenimiento_auditado', true);
            }
            return $siguiente($solicitud->conAtributo('mantenimiento', $senal));
        }
        if ($usuario !== null && $solicitud->esEscritura()) {
            $this->auditoria->registrar($solicitud->conAtributo('usuario', $usuario), 'mantenimiento.operacion_rechazada', 'denegado', null, ['ruta' => $solicitud->ruta]);
        }
        return ServicioMantenimiento::respuestaAviso($solicitud, $senal);
    }
}
