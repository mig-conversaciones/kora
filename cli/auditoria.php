<?php

declare(strict_types=1);

/**
 * Auditoría por SSH (RAU-004):
 *
 *   php cli/auditoria.php sellar       Sella los eventos pendientes (también lo hace el programador cada minuto)
 *   php cli/auditoria.php verificar    Recorre la cadena completa; código 1 si detecta manipulación
 */

use App\Servicios\ServicioAuditoria;

require __DIR__ . '/arranque.php';

$auditoria = contenedorCli()->obtener(ServicioAuditoria::class);
$accion = $argv[1] ?? 'verificar';
if ($accion === 'sellar') {
    escribirLinea('Eventos sellados: ' . $auditoria->sellarPendientes(2000, 0));
    exit(0);
}
$resultado = $auditoria->verificarCadena();
escribirLinea(($resultado['integra'] ? '✔ ' : '✘ ') . $resultado['detalle'] . " Eventos verificados: {$resultado['eventos']}. Sin sellar con más de 3 min: {$resultado['sin_sellar']}.");
exit($resultado['integra'] ? 0 : 1);
