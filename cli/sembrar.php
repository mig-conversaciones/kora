<?php

declare(strict_types=1);

/**
 * Siembra los datos de configuración (RDL-008): centro, unidades, destinos, roles, permisos y matriz de
 * autorización, estado inicial del mantenimiento. Idempotente; no crea usuarios ni datos de negocio.
 *
 *   php cli/sembrar.php [--base=kora_pruebas]
 */

use App\Nucleo\BaseDatos;
use App\Nucleo\Entorno;
use App\Nucleo\Migrador;

require __DIR__ . '/arranque.php';

$opciones = opcionesCli($argv);
if (isset($opciones['base']) && is_string($opciones['base']) && preg_match('/^[a-z0-9_]+$/', $opciones['base']) === 1) {
    Entorno::establecer('BD_NOMBRE', $opciones['base']);
}
$migrador = new Migrador(new BaseDatos(require RAIZ . '/config/base_datos.php'), RAIZ . '/bd/migraciones');
foreach (glob(RAIZ . '/bd/semillas/[0-9][0-9][0-9]_*.sql') ?: [] as $archivo) {
    escribirLinea('  ✔ ' . basename($archivo) . ' (' . $migrador->sembrar($archivo) . ' sentencias)');
}
escribirLinea('Semillas aplicadas en «' . Entorno::texto('BD_NOMBRE') . '».');
exit(0);
