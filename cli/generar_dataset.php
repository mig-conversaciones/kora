<?php

declare(strict_types=1);

/**
 * Dataset sintético para el inventario de consultas y las pruebas de carga (RNF-PERF-016; Anexo H).
 *
 *   php cli/generar_dataset.php --base=kora_carga [--escala=3a|1a|mini] [--anio=2026] [--semilla=20261119] [--preparar]
 *                               [--cuentas=30 --usuarios-k6=tests/carga/usuarios.json]
 *
 * --preparar aplica las migraciones y las semillas en la base indicada. Solo admite bases «*_carga» o «*_pruebas» y
 * nunca se ejecuta en producción (RDL-009). Las cuentas de k6 comparten una contraseña aleatoria que solo queda en el
 * archivo indicado (fuera del repositorio: tests/carga/usuarios.json está en .gitignore).
 */

use App\Nucleo\BaseDatos;
use App\Nucleo\Entorno;
use App\Nucleo\Migrador;
use App\Operacion\CifrasControl;
use App\Operacion\GeneradorDatosCarga;
use App\Servicios\ServicioContrasenas;

require __DIR__ . '/arranque.php';

$opciones = opcionesCli($argv);
$base = is_string($opciones['base'] ?? null) ? $opciones['base'] : '';
if (preg_match('/^[a-z0-9_]+_(carga|pruebas)$/', $base) !== 1) {
    fwrite(STDERR, "Indica --base con una base «*_carga» o «*_pruebas»: el generador nunca escribe en la base de desarrollo ni en la de producción.\n");
    exit(2);
}
Entorno::establecer('BD_NOMBRE', $base);
$bd = new BaseDatos(require RAIZ . '/config/base_datos.php');

try {
    if (isset($opciones['preparar'])) {
        $migrador = new Migrador($bd, RAIZ . '/bd/migraciones');
        escribirLinea('Migraciones aplicadas: ' . count($migrador->aplicar()));
        foreach (glob(RAIZ . '/bd/semillas/[0-9][0-9][0-9]_*.sql') ?: [] as $archivo) {
            $migrador->sembrar($archivo);
        }
        escribirLinea('Semillas aplicadas.');
    }
    $cuentas = (int) ($opciones['cuentas'] ?? 0);
    $destinoUsuarios = is_string($opciones['usuarios-k6'] ?? null) ? $opciones['usuarios-k6'] : null;
    $contrasena = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'Kq'), '=') . '-7a';
    $contenedor = contenedorCli();
    $hashear = static fn (string $clave): string => $contenedor->obtener(ServicioContrasenas::class)->hashear($clave);
    $inicio = microtime(true);
    $generador = new GeneradorDatosCarga($bd, Entorno::texto('APP_ENTORNO', 'produccion'), static fn (string $m) => escribirLinea('  ' . $m));
    $resultado = $generador->generar(
        is_string($opciones['escala'] ?? null) ? $opciones['escala'] : '3a',
        (int) ($opciones['anio'] ?? gmdate('Y')),
        (int) ($opciones['semilla'] ?? 20261119),
        $destinoUsuarios !== null ? max(1, $cuentas ?: 30) : 0,
        $hashear,
        $contrasena
    );
    escribirLinea(sprintf('Dataset generado en %.0f s en «%s»:', microtime(true) - $inicio, $base));
    foreach ($resultado['resumen'] as $tabla => $filas) {
        escribirLinea(sprintf('  %-14s %s', $tabla, number_format($filas, 0, ',', '.')));
    }
    $bd->ejecutar('ANALYZE TABLE salidas, recaudos, recepciones_lote, gastos_ejecutados, asignaciones_presupuestales, eventos_auditoria');
    $cifras = (new CifrasControl($bd))->calcular();
    escribirLinea('Cadena de auditoría: ' . ($cifras['auditoria']['cadena_integra'] ? 'íntegra' : 'NO íntegra — ' . $cifras['auditoria']['detalle']) . ". Huella de las cifras: {$cifras['huella']}");
    if ($destinoUsuarios !== null) {
        if (!is_dir(dirname($destinoUsuarios))) {
            mkdir(dirname($destinoUsuarios), 0750, true);
        }
        file_put_contents($destinoUsuarios, json_encode($resultado['usuarios'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @chmod($destinoUsuarios, 0600);
        escribirLinea(count($resultado['usuarios']) . " cuentas de k6 escritas en {$destinoUsuarios} (no lo versiones).");
    }
    exit($cifras['auditoria']['cadena_integra'] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
