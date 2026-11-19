<?php

declare(strict_types=1);

/**
 * Verificación de integridad de la versión activa y del .env (RS-012, RS-022; Anexo D, D.3):
 *
 *   php cli/verificar_integridad.php                 código 1 si hay alertas
 *   php cli/verificar_integridad.php --registrar-env  último paso de una rotación de claves: registra la huella del .env
 *
 * El trabajo seguridad.verificar_integridad hace lo mismo cada día a las 03:15 y notifica las alertas.
 */

use App\Operacion\VerificadorIntegridad;

require __DIR__ . '/arranque.php';

$verificador = contenedorCli()->obtener(VerificadorIntegridad::class);
if (isset(opcionesCli($argv)['registrar-env'])) {
    $huella = $verificador->registrarHuellaEnv();
    escribirLinea('Huella del .env registrada (' . substr($huella, 0, 12) . '…). Anota la rotación en el registro del runbook.');
    exit(0);
}
$resultado = $verificador->verificar();
foreach ($resultado['alertas'] as $alerta) {
    escribirLinea('  ✘ ' . $alerta);
}
escribirLinea($resultado['alertas'] === [] ? "Integridad verificada ({$resultado['archivos']} archivos del manifiesto)." : count($resultado['alertas']) . ' alertas de integridad.');
exit($resultado['alertas'] === [] ? 0 : 1);
