<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Registro;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;

/**
 * Recibe los informes de violación de la CSP (RS-007). Lee como máximo 8 KB, registra solo los campos útiles para
 * diagnosticar y responde 204. En la I2 se le aplica el límite de frecuencia por IP.
 */
final class ControladorCsp
{
    private const TAMANO_MAXIMO = 8192;

    public function __construct(private readonly Registro $registro)
    {
    }

    public function recibir(Solicitud $solicitud): Respuesta
    {
        $cuerpo = (string) file_get_contents('php://input', false, null, 0, self::TAMANO_MAXIMO);
        $datos = json_decode($cuerpo, true);
        if (is_array($datos)) {
            $informe = $datos['csp-report'] ?? ($datos[0]['body'] ?? $datos);
            if (is_array($informe)) {
                $this->registro->aviso('csp_violacion', [
                    'directiva' => mb_substr((string) ($informe['violated-directive'] ?? $informe['effectiveDirective'] ?? ''), 0, 120),
                    'bloqueado' => mb_substr((string) ($informe['blocked-uri'] ?? $informe['blockedURL'] ?? ''), 0, 255),
                    'documento' => mb_substr((string) ($informe['document-uri'] ?? $informe['documentURL'] ?? ''), 0, 255),
                ]);
            }
        }
        return new Respuesta('', 204);
    }
}
