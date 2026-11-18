<?php

declare(strict_types=1);

/**
 * Administración de la cola por SSH (RF-Q-014; el panel web del superadministrador llega en la I2):
 *
 *   php cli/cola.php estado
 *   php cli/cola.php fallidos [--limite=20]
 *   php cli/cola.php reintentar ULID --motivo="..."
 *   php cli/cola.php cancelar ULID --motivo="..."
 *   php cli/cola.php descartar ULID --motivo="..."
 */

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;
use App\Servicios\ServicioAdministracionCola;
use App\Servicios\ServicioMonitorCola;

require __DIR__ . '/arranque.php';

$contenedor = contenedorCli();
$accion = $argv[1] ?? 'estado';
$opciones = opcionesCli($argv);

if ($accion === 'estado') {
    $e = $contenedor->obtener(ServicioMonitorCola::class)->estadisticas();
    $latido = static fn (int $s): string => $s === PHP_INT_MAX ? 'nunca' : "hace {$s} s";
    escribirLinea('Cola de trabajos');
    foreach ($e['por_estado'] as $estado => $total) {
        escribirLinea(sprintf('  %-11s %d', $estado, $total));
    }
    escribirLinea("  Pendiente más antiguo: {$e['pendiente_mas_antiguo_segundos']} s");
    escribirLinea("  Fallidos en la última hora: {$e['fallidos_ultima_hora']}");
    escribirLinea('  Duración media (24 h): ' . ($e['duracion_media_ms'] === null ? 'sin datos' : "{$e['duracion_media_ms']} ms"));
    escribirLinea('  Latido del trabajador: ' . $latido($e['latido_trabajador_segundos']));
    escribirLinea('  Latido del programador: ' . $latido($e['latido_programador_segundos']));
    exit(0);
}

if ($accion === 'fallidos') {
    $limite = max(1, min(200, (int) ($opciones['limite'] ?? 20)));
    $filas = $contenedor->obtener(BaseDatos::class)->consultar(
        "SELECT ulid, tipo, intentos, clase_error, mensaje_error, fallido_en, revisado_en FROM trabajos_fallidos
          ORDER BY fallido_en DESC LIMIT {$limite}"
    );
    foreach ($filas as $f) {
        escribirLinea(sprintf('%s  %-22s %-20s intentos=%d %s%s', $f['ulid'], $f['tipo'], $f['clase_error'], $f['intentos'], $f['fallido_en'], $f['revisado_en'] ? ' (revisado)' : ''));
        escribirLinea('    ' . $f['mensaje_error']);
    }
    escribirLinea(count($filas) . ' trabajos fallidos.');
    exit(0);
}

if (!in_array($accion, ['reintentar', 'cancelar', 'descartar'], true) || !isset($argv[2])) {
    fwrite(STDERR, "Uso: php cli/cola.php estado|fallidos|reintentar|cancelar|descartar [ULID] --motivo=\"...\"\n");
    exit(2);
}

$motivo = isset($opciones['motivo']) && is_string($opciones['motivo']) ? $opciones['motivo'] : '';
$solicitud = (new Solicitud('CLI', '/cli/cola', [], [], [], [], ''))
    ->conAtributo('request_id', bin2hex(random_bytes(16)))
    ->conAtributo('origen', 'cli');
try {
    $contenedor->obtener(ServicioAdministracionCola::class)->{$accion}($solicitud, strtoupper($argv[2]), $motivo);
    escribirLinea("Operación «{$accion}» aplicada al trabajo {$argv[2]}.");
    exit(0);
} catch (ErrorValidacion $error) {
    fwrite(STDERR, implode(PHP_EOL, $error->errores()) . PHP_EOL);
    exit(2);
} catch (ErrorHttp $error) {
    fwrite(STDERR, ($error->getMessage() !== '' ? $error->getMessage() : 'No existe un trabajo con ese identificador.') . PHP_EOL);
    exit(1);
}
