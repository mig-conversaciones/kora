<?php

declare(strict_types=1);

namespace App\Intermediarios;

use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Solicitud;

/**
 * Carga la sesión del servidor a partir de la cookie (RAC-016, RAC-017) y, al volver, guarda sus cambios y agrega
 * Set-Cookie y X-Sesion-Expira-En. /health/live y los informes CSP no tocan sesiones; /health/ready solo lee la
 * del superadministrador si existe (D-28). Cargar nunca crea una sesión: solo el token CSRF la crea.
 */
final class IniciarSesion implements Intermediario
{
    public function __construct(private readonly Sesion $sesion)
    {
    }

    public function procesar(Solicitud $solicitud, callable $siguiente): Respuesta
    {
        if ($solicitud->ruta === '/health/live' || $solicitud->ruta === '/csp-reportes') {
            return $siguiente($solicitud);
        }
        $this->sesion->cargar($solicitud);
        $respuesta = $siguiente($solicitud->conAtributo('sesion', $this->sesion));
        return $this->sesion->persistir($respuesta);
    }
}
