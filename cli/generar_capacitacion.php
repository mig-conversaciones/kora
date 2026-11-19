<?php

declare(strict_types=1);

/**
 * Entorno de capacitación (RF-100, D-19; docs/uat/capacitacion.md): el escenario demostrativo del prototipo en una base
 * aislada, con una cuenta de práctica por rol.
 *
 *   php cli/generar_capacitacion.php --base=kora_capacitacion [--preparar] [--vigencia=2027] [--cuentas=ruta.json]
 *
 * Solo admite bases «*_capacitacion», «*_pruebas» o «*_carga», y nunca corre en producción. Las cuentas comparten una
 * contraseña aleatoria que solo queda en el archivo indicado (fuera del repositorio); se destruye al terminar la
 * capacitación.
 */

use App\Nucleo\BaseDatos;
use App\Nucleo\Entorno;
use App\Nucleo\GeneradorUlid;
use App\Nucleo\Migrador;
use App\Operacion\GeneradorCapacitacion;
use App\Servicios\ServicioContrasenas;

require __DIR__ . '/arranque.php';

$opciones = opcionesCli($argv);
$base = is_string($opciones['base'] ?? null) ? $opciones['base'] : '';
if (preg_match('/^[a-z0-9_]+_(capacitacion|pruebas|carga)$/', $base) !== 1) {
    fwrite(STDERR, "Indica --base con una base «*_capacitacion», «*_pruebas» o «*_carga»: nunca la de desarrollo ni la de producción.\n");
    exit(2);
}
$entorno = Entorno::texto('APP_ENTORNO', 'produccion');
if ($entorno === 'produccion') {
    fwrite(STDERR, "El escenario de capacitación nunca se genera en producción (D-19).\n");
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
    }
    $centro = (int) $bd->valor("SELECT id FROM centros WHERE codigo = '9124'");
    $vigencia = (int) ($opciones['vigencia'] ?? 2027);
    (new GeneradorCapacitacion($bd, $entorno))->generar($centro, $vigencia);
    $bd->ejecutar('UPDATE versiones_datos SET version = version + 1 WHERE centro_id = :c', ['c' => $centro]);
    escribirLinea("Escenario de capacitación generado en «{$base}», vigencia {$vigencia}.");

    $destino = is_string($opciones['cuentas'] ?? null) ? $opciones['cuentas'] : null;
    if ($destino !== null) {
        $contrasena = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'Kq'), '=') . '-7a';
        $hash = contenedorCli()->obtener(ServicioContrasenas::class)->hashear($contrasena);
        $gan = (int) $bd->valor("SELECT id FROM unidades_productivas WHERE centro_id = :c AND codigo = 'GAN'", ['c' => $centro]);
        $cuentas = [];
        foreach (['coordinacion_administrativa', 'grupo_recaudo', 'lider_produccion_centro', 'lider_unidad', 'coordinacion_academica'] as $rol) {
            $correo = str_replace('_', '.', $rol) . '@capacitacion.invalid';
            if ($bd->valor('SELECT id FROM usuarios WHERE correo = :c', ['c' => $correo]) !== null) {
                continue;
            }
            $id = $bd->insertar(
                "INSERT INTO usuarios (ulid, centro_id, correo, nombre, hash_contrasena, estado, contrasena_cambiada_en) VALUES (:u, :c, :correo, :n, :h, 'activo', UTC_TIMESTAMP(3))",
                ['u' => GeneradorUlid::generar(), 'c' => $centro, 'correo' => $correo, 'n' => 'Práctica — ' . str_replace('_', ' ', $rol), 'h' => $hash]
            );
            $bd->ejecutar('INSERT INTO usuarios_roles (usuario_id, rol_id, centro_id) SELECT :u, id, :c FROM roles WHERE codigo = :r', ['u' => $id, 'c' => $centro, 'r' => $rol]);
            if ($rol === 'lider_unidad') {
                $bd->ejecutar('INSERT INTO usuarios_unidades (usuario_id, unidad_id) VALUES (:u, :g)', ['u' => $id, 'g' => $gan]);
            }
            $cuentas[] = ['rol' => $rol, 'correo' => $correo, 'contrasena' => $contrasena];
        }
        file_put_contents($destino, json_encode($cuentas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        @chmod($destino, 0600);
        escribirLinea(count($cuentas) . " cuentas de práctica en {$destino} (no lo versiones; destrúyelo al terminar).");
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'No se generó el escenario: ' . $error->getMessage() . "\n");
    exit(1);
}
