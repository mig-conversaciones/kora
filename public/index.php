<?php

declare(strict_types=1);

/**
 * Controlador frontal de KORA (04-02, 4.2.1).
 * Antes de depender de la base de datos evalúa la señal mínima de mantenimiento: durante una migración o con la
 * base caída, el usuario debe ver el aviso 503 y no un error 500 (RF-MAN-005).
 */

use App\Nucleo\Aplicacion;
use App\Nucleo\Entorno;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Url;
use App\Servicios\ServicioMantenimiento;

define('RAIZ', dirname(__DIR__));

require RAIZ . '/vendor/autoload.php';

try {
    Entorno::cargar(RAIZ . '/.env');
    date_default_timezone_set('UTC');
    $solicitud = Solicitud::desdeGlobales(Url::base());
} catch (Throwable $error) {
    error_log(json_encode(['nivel' => 'critico', 'evento' => 'entorno_no_disponible', 'clase' => $error::class]) ?: 'entorno_no_disponible');
    Respuesta::servicioNoDisponible(new Solicitud('GET', '/', [], [], [], [], ''))->enviar();
    exit;
}

$senal = ServicioMantenimiento::leerSenal(RAIZ . '/almacenamiento/estado/mantenimiento.json');

// Ruta rápida: con mantenimiento activo, quien no tiene sesión ni pide una ruta exceptuada recibe el aviso sin
// abrir conexión a la base de datos. Las sesiones existentes continúan hacia el intermediario Mantenimiento, que
// decide si hay acceso excepcional autorizado (RF-MAN-009, desde la I2).
if ($senal !== null
    && !ServicioMantenimiento::rutaExceptuada($solicitud->ruta)
    && !$solicitud->tieneCookie((require RAIZ . '/config/seguridad.php')['cookie_sesion']['nombre'])) {
    ServicioMantenimiento::respuestaAviso($solicitud, $senal)->enviar();
    exit;
}

try {
    $respuesta = Aplicacion::crear(require RAIZ . '/config/app.php')->manejar($solicitud);
} catch (Throwable $error) {
    // Falla del arranque (por ejemplo, configuración incompleta): respuesta mínima sin detalles (RS-010).
    error_log(json_encode(['nivel' => 'critico', 'evento' => 'arranque_fallido', 'clase' => $error::class, 'codigo' => $error->getCode()]) ?: 'arranque_fallido');
    $respuesta = $senal !== null
        ? ServicioMantenimiento::respuestaAviso($solicitud, $senal)
        : Respuesta::servicioNoDisponible($solicitud);
}
$respuesta->enviar();
