<?php

declare(strict_types=1);

namespace App\Servicios;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * QR del enrolamiento de la 2FA, generado en el servidor sin servicios externos (RAC-010; chillerlan/php-qrcode,
 * ADR-005). Devuelve un data URI SVG: la CSP lo admite como imagen (img-src 'self' data:) sin estilos en línea.
 */
final class ServicioQr
{
    public function svgDataUri(string $contenido): string
    {
        $opciones = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => true,
            'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
            'addQuietzone' => true,
            'svgAddXmlHeader' => false,
            'drawLightModules' => false,
        ]);
        return (string) (new QRCode($opciones))->render($contenido);
    }
}
