<?php

declare(strict_types=1);

/**
 * Recifrado tras una rotación de claves (RS-013, D-15; runbook «Rotación de claves»):
 *
 *   php cli/recifrar.php estado      lo que sigue cifrado con una clave anterior; código 1 si queda algo
 *   php cli/recifrar.php ejecutar    recifra ahora, por bloques, hasta terminar
 *   php cli/recifrar.php encolar     lo deja al trabajo seguridad.recifrar de la cola
 *
 * Cuando «estado» informa 0, la clave anterior se puede retirar de CLAVES_CIFRADO.
 */

use App\Cola\DespachadorCola;
use App\Operacion\ServicioRecifrado;

require __DIR__ . '/arranque.php';

$accion = $argv[1] ?? 'estado';
$contenedor = contenedorCli();
$servicio = $contenedor->obtener(ServicioRecifrado::class);

if ($accion === 'ejecutar') {
    do {
        $resultado = $servicio->recifrar(500);
        escribirLinea(sprintf('Recifrados: %d secretos TOTP, %d correos, %d lotes de auditoría. Pendientes: %d.', $resultado['totp'], $resultado['correos'], $resultado['lotes_auditoria'], $resultado['pendientes']));
    } while ($resultado['pendientes'] > 0 && $resultado['totp'] + $resultado['correos'] + $resultado['lotes_auditoria'] > 0);
    exit($resultado['pendientes'] === 0 ? 0 : 1);
}
if ($accion === 'encolar') {
    $contenedor->obtener(DespachadorCola::class)->encolar('seguridad.recifrar', [], ['clave_idempotencia' => 'recifrar-' . gmdate('YmdHi')]);
    escribirLinea('Trabajo seguridad.recifrar encolado; sigue su avance en Sistema > Colas de trabajos.');
    exit(0);
}
$pendientes = $servicio->pendientes();
escribirLinea(sprintf('Con una clave anterior: %d secretos TOTP, %d correos en cola, %d lotes de auditoría (total %d).', $pendientes['totp'], $pendientes['correos'], $pendientes['lotes_auditoria'], $pendientes['total']));
escribirLinea($pendientes['total'] === 0 ? 'Nada pendiente: la clave anterior se puede retirar del llavero.' : 'Ejecuta «php cli/recifrar.php ejecutar» antes de retirar la clave anterior.');
exit($pendientes['total'] === 0 ? 0 : 1);
