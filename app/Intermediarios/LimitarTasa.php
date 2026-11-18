<?php

declare(strict_types=1);

namespace App\Intermediarios;

use App\Nucleo\ErrorHttp;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Servicios\ServicioLimiteTasa;

/**
 * Límite de frecuencia por IP para las rutas que lo declaran con la opción «limite» (RAC-029): cada solicitud
 * cuenta. Los límites por cuenta (inicio de sesión, OTP, recuperación) los aplican los servicios, porque necesitan
 * distinguir aciertos de fallos. Responde 429 con Retry-After.
 */
final class LimitarTasa implements Intermediario
{
    public function __construct(private readonly ServicioLimiteTasa $limites)
    {
    }

    public function procesar(Solicitud $solicitud, callable $siguiente): Respuesta
    {
        $ruta = $solicitud->atributo('ruta');
        $tipo = is_array($ruta) ? ($ruta['opciones']['limite'] ?? null) : null;
        if (!is_string($tipo) || !$solicitud->esEscritura()) {
            return $siguiente($solicitud);
        }
        $espera = $this->limites->espera($tipo, 'ip', $solicitud->ip);
        if ($espera > 0) {
            throw new ErrorHttp(429, "Demasiadas solicitudes seguidas. Espera {$espera} segundos e intenta de nuevo.");
        }
        $this->limites->registrar($tipo, 'ip', $solicitud->ip);
        return $siguiente($solicitud);
    }
}
