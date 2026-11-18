<?php

declare(strict_types=1);

namespace App\Intermediarios;

use App\Nucleo\ErrorHttp;
use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Solicitud;
use App\Servicios\ServicioAuditoria;

/**
 * Protección CSRF de las escrituras (RS-005; base 04-07, 4.7.4): token sincronizador de la sesión (campo _csrf o
 * cabecera X-CSRF-Token) comparado en tiempo constante, más verificación de Origin y Sec-Fetch-Site. Las rutas de
 * la futura API de integración (firma HMAC) se excluyen con la opción de ruta «sinCsrf».
 */
final class VerificarCsrf implements Intermediario
{
    public function __construct(
        private readonly Sesion $sesion,
        private readonly ServicioAuditoria $auditoria,
        private readonly string $origenPermitido,
    ) {
    }

    public function procesar(Solicitud $solicitud, callable $siguiente): Respuesta
    {
        $ruta = $solicitud->atributo('ruta');
        if (!$solicitud->esEscritura() || !is_array($ruta) || !empty($ruta['opciones']['sinCsrf'])) {
            return $siguiente($solicitud);
        }
        $recibido = $solicitud->cabecera('X-CSRF-Token') ?? (string) ($solicitud->cuerpo['_csrf'] ?? '');
        $esperado = $this->sesion->existe() ? (string) $this->sesion->obtener('_csrf', '') : '';
        $origen = $solicitud->cabecera('Origin');
        $sitio = $solicitud->cabecera('Sec-Fetch-Site');
        $origenValido = ($origen === null || hash_equals($this->origenPermitido, $origen))
            && ($sitio === null || in_array($sitio, ['same-origin', 'none'], true));
        if ($esperado === '' || $recibido === '' || !hash_equals($esperado, $recibido) || !$origenValido) {
            $this->auditoria->registrar($solicitud, 'seguridad.csrf', 'denegado', null, ['origen_presente' => $origen !== null, 'ruta' => $solicitud->ruta]);
            throw new ErrorHttp(403, 'La sesión del formulario venció o la solicitud no viene de KORA. Recarga la página e intenta de nuevo.');
        }
        return $siguiente($solicitud);
    }
}
