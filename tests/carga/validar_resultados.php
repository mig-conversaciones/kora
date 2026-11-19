<?php

declare(strict_types=1);

/**
 * Validación de negocio tras una prueba de carga (RNF-PERF-005; Anexo H, H.3 y H.4). Se ejecuta en el servidor de
 * staging, antes y después de k6:
 *
 *   php tests/carga/validar_resultados.php --antes=almacenamiento/carga/cifras_antes.json --registrar   (antes de k6)
 *   php tests/carga/validar_resultados.php --antes=almacenamiento/carga/cifras_antes.json               (después)
 *
 * Después de la prueba compara las cifras de control (CifrasControl) con las de antes: solo pueden cambiar los gastos
 * de la prueba (descripción «Gasto sintético de prueba de carga») y la auditoría. Además verifica que no haya claves de
 * idempotencia duplicadas, que la cola vuelva a 0 pendientes (RNF-PERF-006) y que la cadena de auditoría siga íntegra.
 * Código 0 solo si todo coincide.
 */

use App\Nucleo\BaseDatos;
use App\Nucleo\Entorno;
use App\Operacion\CifrasControl;

define('RAIZ', dirname(__DIR__, 2));
require RAIZ . '/vendor/autoload.php';
Entorno::cargar(RAIZ . '/.env');
if (Entorno::texto('APP_ENTORNO', 'produccion') === 'produccion') {
    fwrite(STDERR, "Las pruebas de carga y su validación son solo de staging.\n");
    exit(2);
}

$opciones = getopt('', ['antes:', 'registrar']);
$archivo = (string) ($opciones['antes'] ?? RAIZ . '/almacenamiento/carga/cifras_antes.json');
$bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
$gastosDePrueba = static fn (): array => $bd->fila(
    "SELECT COUNT(*) AS n, COALESCE(SUM(valor), 0) AS suma FROM gastos_ejecutados WHERE descripcion = 'Gasto sintético de prueba de carga' AND origen_tipo = 'manual'"
) ?? ['n' => 0, 'suma' => '0'];
$cifras = (new CifrasControl($bd))->calcular();

if (isset($opciones['registrar'])) {
    if (!is_dir(dirname($archivo))) {
        mkdir(dirname($archivo), 0750, true);
    }
    file_put_contents($archivo, json_encode(['cifras' => $cifras, 'gastos_prueba' => $gastosDePrueba()], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    fwrite(STDOUT, "Cifras de control registradas en {$archivo} (huella {$cifras['huella']}).\n");
    exit(0);
}

$antes = json_decode((string) file_get_contents($archivo), true, 32, JSON_THROW_ON_ERROR);
$fallos = [];
// Los gastos de la prueba y la auditoría cambian por diseño: se descuentan de la comparación.
$sinPrueba = static function (array $c): array {
    unset($c['tablas']['gastos_ejecutados'], $c['auditoria']['eventos'], $c['auditoria']['sellados']);
    return $c;
};
foreach (CifrasControl::diferencias($sinPrueba($antes['cifras']), $sinPrueba($cifras)) as $diferencia) {
    $fallos[] = 'Cambio inesperado: ' . $diferencia;
}
$despues = $gastosDePrueba();
$nuevos = (int) $despues['n'] - (int) $antes['gastos_prueba']['n'];
fwrite(STDOUT, "Gastos de prueba registrados: {$nuevos}.\n");
$duplicados = (int) $bd->valor('SELECT COUNT(*) FROM (SELECT usuario_id, clave FROM claves_idempotencia GROUP BY usuario_id, clave HAVING COUNT(*) > 1) d');
if ($duplicados > 0) {
    $fallos[] = "Claves de idempotencia duplicadas: {$duplicados}.";
}
$pendientes = (int) $bd->valor("SELECT COUNT(*) FROM trabajos WHERE estado IN ('pendiente', 'reservado')");
if ($pendientes > 0) {
    $fallos[] = "La cola no se vació: {$pendientes} trabajos pendientes o reservados (RNF-PERF-006: 0 en 10 min tras el pico).";
}

if ($fallos !== []) {
    fwrite(STDERR, implode(PHP_EOL, $fallos) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Integridad, idempotencia, cadena de auditoría y vaciado de la cola verificados tras la prueba de carga.\n");
