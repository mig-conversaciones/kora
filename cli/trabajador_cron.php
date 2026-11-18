<?php

declare(strict_types=1);

/**
 * Trabajador de la cola (equivale a cron_worker.php; RF-Q-005). Lo invoca el cron de hPanel cada minuto, más una
 * entrada desfasada 30 s (Anexo D, D.4):
 *
 *   php cli/trabajador_cron.php [--cola=correo] [--presupuesto=50]
 *
 * Como máximo COLA_MAX_TRABAJADORES instancias a la vez (ranuras con flock); una instancia extra sale sin
 * reclamar. Cada ejecución registra un latido, que vigilan /health/ready y el programador (ROB-010).
 */

use App\Cola\Trabajador;
use App\Servicios\ServicioMonitorCola;

require __DIR__ . '/arranque.php';

$opciones = opcionesCli($argv);
$config = require RAIZ . '/config/cola.php';
$ranura = tomarRanura('trabajador', (int) $config['max_trabajadores']);
if ($ranura === null) {
    exit(0);
}

$contenedor = contenedorCli();
$contenedor->obtener(ServicioMonitorCola::class)->registrarLatido(ServicioMonitorCola::LATIDO_TRABAJADOR);
$resultado = $contenedor->obtener(Trabajador::class)->ejecutar(
    isset($opciones['cola']) && is_string($opciones['cola']) ? $opciones['cola'] : null,
    isset($opciones['presupuesto']) && is_string($opciones['presupuesto']) ? max(1.0, (float) $opciones['presupuesto']) : null,
);
$contenedor->obtener(ServicioMonitorCola::class)->registrarLatido(ServicioMonitorCola::LATIDO_TRABAJADOR);

if (isset($opciones['detalle'])) {
    escribirLinea(json_encode($resultado, JSON_THROW_ON_ERROR));
}
flock($ranura, LOCK_UN);
fclose($ranura);
exit(0);
