<?php

declare(strict_types=1);

/**
 * Migraciones de KORA (RBD-011, Anexo B).
 *
 *   php cli/migrar.php --estado           Lista aplicadas, pendientes y alteradas
 *   php cli/migrar.php --aplicar          Aplica las pendientes en orden
 *   php cli/migrar.php --revertir=N       Revierte las últimas N (pide --confirmar fuera de local)
 *   --base=NOMBRE                         Usa otra base (p. ej. kora_pruebas) en lugar de BD_NOMBRE
 */

use App\Nucleo\BaseDatos;
use App\Nucleo\Entorno;
use App\Nucleo\Migrador;

require __DIR__ . '/arranque.php';

$opciones = opcionesCli($argv);
if (isset($opciones['base']) && is_string($opciones['base'])) {
    if (preg_match('/^[a-z0-9_]+$/', $opciones['base']) !== 1) {
        fwrite(STDERR, "Nombre de base no válido.\n");
        exit(2);
    }
    Entorno::establecer('BD_NOMBRE', $opciones['base']);
}

$migrador = new Migrador(new BaseDatos(require RAIZ . '/config/base_datos.php'), RAIZ . '/bd/migraciones');
$base = Entorno::texto('BD_NOMBRE');

try {
    if (isset($opciones['aplicar'])) {
        $hechas = $migrador->aplicar(static fn (string $nombre) => escribirLinea("  ✔ {$nombre}"));
        escribirLinea($hechas === [] ? "Sin migraciones pendientes en «{$base}»." : 'Aplicadas ' . count($hechas) . " migraciones en «{$base}».");
        exit(0);
    }
    if (isset($opciones['revertir'])) {
        $cantidad = (int) $opciones['revertir'];
        if ($cantidad < 1) {
            fwrite(STDERR, "Indica cuántas migraciones revertir: --revertir=N\n");
            exit(2);
        }
        if (Entorno::texto('APP_ENTORNO', 'produccion') !== 'local' && !isset($opciones['confirmar'])) {
            fwrite(STDERR, "Fuera de local la reversión exige --confirmar y una copia previa (RDL-004).\n");
            exit(2);
        }
        $hechas = $migrador->revertir($cantidad, static fn (string $nombre) => escribirLinea("  ↶ {$nombre}"));
        escribirLinea('Revertidas ' . count($hechas) . " migraciones en «{$base}».");
        exit(0);
    }
    escribirLinea("Estado de las migraciones en «{$base}»:");
    $alteradas = 0;
    foreach ($migrador->estado() as $fila) {
        $marca = ['aplicada' => '✔', 'pendiente' => '·', 'alterada' => '✘', 'sin_archivo' => '?'][$fila['estado']];
        escribirLinea("  {$marca} {$fila['nombre']}  ({$fila['estado']})");
        $alteradas += $fila['estado'] === 'alterada' ? 1 : 0;
    }
    exit($alteradas > 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Error de migración: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
