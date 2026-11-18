<?php

declare(strict_types=1);

/**
 * Programador de tareas periódicas (ROB-010; Anexo D, D.4). El cron de hPanel lo ejecuta cada minuto:
 *
 *   php cli/programador.php
 *
 * Una sola instancia a la vez (flock). Ver App\Servicios\Programador para las tareas de cada iteración.
 */

use App\Servicios\Programador;

require __DIR__ . '/arranque.php';

$ranura = tomarRanura('programador', 1);
if ($ranura === null) {
    exit(0);
}
$resumen = contenedorCli()->obtener(Programador::class)->ejecutar();
if (isset(opcionesCli($argv)['detalle'])) {
    escribirLinea(json_encode($resumen, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}
flock($ranura, LOCK_UN);
fclose($ranura);
exit(0);
