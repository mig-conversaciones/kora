<?php

declare(strict_types=1);

/**
 * Recuperación del modo mantenimiento sin panel (RF-MAN-012; base: 06-03-anexo-c). Por SSH y solo para el titular
 * de la cuenta:
 *
 *   php cli/mantenimiento.php estado
 *   php cli/mantenimiento.php activar --motivo="Migración 007" [--titulo="..."] [--mensaje="..."] [--hasta="2027-04-29T22:00:00-05:00"]
 *   php cli/mantenimiento.php desactivar --motivo="Fin de la migración" [--forzar-sin-bd]
 *   php cli/mantenimiento.php drenaje        (código 0 cuando no quedan trabajos de negocio reservados)
 *
 * Con la base de datos caída opera solo sobre la señal mínima y deja el evento en
 * almacenamiento/estado/eventos_pendientes.jsonl; el programador lo vuelca a la auditoría al recuperarse.
 */

use App\Servicios\ServicioMantenimiento;

require __DIR__ . '/arranque.php';

$config = require RAIZ . '/config/mantenimiento.php';
$accion = $argv[1] ?? 'estado';
$opciones = opcionesCli($argv);
$texto = static fn (string $clave): ?string => isset($opciones[$clave]) && is_string($opciones[$clave]) ? $opciones[$clave] : null;
$ahora = gmdate('c');

if ($accion === 'estado') {
    $senal = ServicioMantenimiento::leerSenal($config['senal']);
    escribirLinea($senal === null ? 'Mantenimiento: inactivo' : 'Mantenimiento: ACTIVO desde ' . ($senal['activado_en'] ?? '¿?') . ' (' . ($senal['titulo'] ?? '') . ')');
    exit(0);
}

if ($accion === 'drenaje') {
    $drenaje = contenedorCli()->obtener(ServicioMantenimiento::class)->estadoDrenaje();
    escribirLinea($drenaje['listo_para_migrar'] ? 'listo' : "en_curso {$drenaje['en_curso']}");
    exit($drenaje['listo_para_migrar'] ? 0 : 3);
}

$motivo = trim((string) $texto('motivo'));
if (!in_array($accion, ['activar', 'desactivar'], true) || $motivo === '') {
    fwrite(STDERR, "Uso: php cli/mantenimiento.php activar|desactivar|estado|drenaje --motivo=\"...\"\n");
    exit(2);
}

try {
    $servicio = contenedorCli()->obtener(ServicioMantenimiento::class);
    $solicitud = $servicio->solicitudDeSistema('cli', $motivo);
    $version = (int) $servicio->estadoActual()['version'];
    $resultado = $accion === 'activar'
        ? $servicio->activar($solicitud, ['motivo' => $motivo, 'titulo' => $texto('titulo'), 'mensaje' => $texto('mensaje'), 'estimado_hasta' => $texto('hasta'), 'version' => $version])
        : $servicio->desactivar($solicitud, ['motivo' => $motivo, 'version' => $version]);
    escribirLinea($resultado['mensaje']);
    foreach ($resultado['comprobaciones'] ?? [] as $comprobacion) {
        escribirLinea(sprintf('  %s %s: %s', $comprobacion['correcta'] ? '✔' : '✘', $comprobacion['nombre'], $comprobacion['detalle']));
    }
    exit($resultado['exito'] ? 0 : 1);
} catch (App\Nucleo\ErrorValidacion $error) {
    foreach ($error->errores() as $campo => $mensaje) {
        fwrite(STDERR, "{$campo}: {$mensaje}\n");
    }
    exit(2);
} catch (PDOException) {
    if ($accion === 'desactivar' && !isset($opciones['forzar-sin-bd'])) {
        fwrite(STDERR, "Sin base de datos no se pueden ejecutar las comprobaciones de salida. Usa --forzar-sin-bd solo si es imprescindible.\n");
        exit(1);
    }
    ServicioMantenimiento::escribirSenalEn($config['senal'], $accion === 'activar'
        ? ['activo' => true, 'activado_en' => $ahora, 'titulo' => $texto('titulo') ?? $config['aviso_por_defecto']['titulo'], 'mensaje' => $texto('mensaje') ?? $config['aviso_por_defecto']['mensaje'], 'estimado_hasta' => $texto('hasta'), 'origen' => 'cli']
        : ['activo' => false, 'desactivado_en' => $ahora, 'origen' => 'cli']);
    $evento = ['accion' => "mantenimiento.{$accion}", 'motivo' => $motivo, 'operador' => get_current_user() ?: 'cli', 'ocurrido_en' => $ahora, 'sin_bd' => true];
    file_put_contents(RAIZ . '/almacenamiento/estado/eventos_pendientes.jsonl', json_encode($evento, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    escribirLinea("Señal de mantenimiento actualizada sin base de datos ({$accion}). Evento pendiente de auditar.");
    exit(0);
}
