<?php

declare(strict_types=1);

/**
 * Correo transaccional (Anexo I, RI-009). En Hostinger: SMTP del dominio con SPF, DKIM y DMARC. En local
 * (MAMP no tiene servidor de correo) CORREO_TRANSPORTE=archivo guarda cada mensaje .eml en almacenamiento/correos.
 */

use App\Nucleo\Entorno;

return [
    'transporte' => Entorno::texto('CORREO_TRANSPORTE', 'smtp'),
    'host' => Entorno::texto('CORREO_HOST', 'smtp.hostinger.com'),
    'puerto' => Entorno::entero('CORREO_PUERTO', 465),
    'cifrado' => Entorno::texto('CORREO_CIFRADO', 'ssl'),
    'usuario' => Entorno::texto('CORREO_USUARIO', ''),
    'contrasena' => Entorno::texto('CORREO_CONTRASENA', ''),
    'remitente' => Entorno::texto('CORREO_REMITENTE', 'notificaciones@kora.local'),
    'nombre_remitente' => Entorno::texto('CORREO_NOMBRE_REMITENTE', 'KORA'),
    // Buzón que recibe las alertas operativas (RF-Q-016). Vacío: solo registro y notificación en la aplicación.
    'buzon_operaciones' => Entorno::texto('CORREO_ALERTAS', ''),
    'directorio_archivo' => RAIZ . '/almacenamiento/correos',
    'url_aplicacion' => rtrim(Entorno::texto('APP_URL', ''), '/'),
];
