<?php

declare(strict_types=1);

namespace App\Intermediarios;

use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Url;

/**
 * Genera un nonce por respuesta y aplica la CSP estricta y las cabeceras de seguridad (RS-007, RS-008).
 * Sin 'unsafe-inline' ni 'unsafe-eval': htmx corre con allowEval = false y Alpine en su versión CSP (ADR-004).
 *
 * HSTS y upgrade-insecure-requests solo se emiten cuando el sitio se sirve por HTTPS (producción y staging).
 * En MAMP local (http://localhost) romperían la carga de los recursos propios.
 */
final class CabecerasSeguridad implements Intermediario
{
    public function __construct(private readonly bool $https)
    {
    }

    public function procesar(Solicitud $solicitud, callable $siguiente): Respuesta
    {
        $nonce = base64_encode(random_bytes(18));
        $respuesta = $siguiente($solicitud->conAtributo('nonce', $nonce));
        $reportes = Url::a('/csp-reportes');
        $directivas = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'nonce-{$nonce}'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "manifest-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];
        if ($this->https) {
            $directivas[] = 'upgrade-insecure-requests';
        }
        $directivas[] = "report-uri {$reportes}";
        $directivas[] = 'report-to csp';

        $respuesta
            ->conCabecera('Content-Security-Policy', implode('; ', $directivas))
            ->conCabecera('Reporting-Endpoints', "csp=\"{$reportes}\"")
            ->conCabecera('X-Content-Type-Options', 'nosniff')
            ->conCabecera('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->conCabecera('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->conCabecera('Cross-Origin-Opener-Policy', 'same-origin')
            ->conCabecera('Cross-Origin-Resource-Policy', 'same-origin')
            ->conCabecera('X-Frame-Options', 'DENY');
        if ($this->https) {
            $respuesta->conCabecera('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        return $respuesta;
    }
}
