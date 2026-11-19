<?php

declare(strict_types=1);

/**
 * Registro de despliegues y reversiones (ROB-013): fila en `despliegues` y evento `sistema.desplegar` (o
 * `sistema.revertir`) en la auditoría, con la versión, el commit, el responsable, las migraciones aplicadas por el
 * despliegue, la copia previa, el resultado y la duración. Lo llaman scripts/desplegar.sh y scripts/rollback.sh:
 *
 *   php cli/registrar_despliegue.php --version=v1.0.0 --tipo=despliegue|reversion [--desde=v0.9.0] [--commit=abc123]
 *        [--respaldo=kora-predespliegue-...] [--resultado=exito|fallido] [--duracion=SEGUNDOS] [--responsable=NOMBRE]
 *
 * Las migraciones del despliegue son las aplicadas durante su duración (aplicada_en dentro de la ventana).
 */

use App\Nucleo\BaseDatos;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioMantenimiento;

require __DIR__ . '/arranque.php';

$opciones = opcionesCli($argv);
$texto = static fn (string $clave, ?string $defecto = null): ?string => isset($opciones[$clave]) && is_string($opciones[$clave]) && $opciones[$clave] !== '' ? $opciones[$clave] : $defecto;
$version = (string) $texto('version', '');
$tipo = (string) $texto('tipo', 'despliegue');
$resultado = (string) $texto('resultado', 'exito');
if (preg_match('/^[A-Za-z0-9._-]{1,40}$/', $version) !== 1 || !in_array($tipo, ['despliegue', 'reversion'], true) || !in_array($resultado, ['exito', 'fallido'], true)) {
    fwrite(STDERR, "Uso: php cli/registrar_despliegue.php --version=vX.Y.Z --tipo=despliegue|reversion [--resultado=exito|fallido] [opciones]\n");
    exit(2);
}
$duracion = max(0, (int) $texto('duracion', '0'));
$responsable = mb_substr((string) $texto('responsable', getenv('USER') ?: (get_current_user() ?: 'cli')), 0, 120);
$commit = $texto('commit') ?? (is_file(RAIZ . '/COMMIT') ? trim((string) file_get_contents(RAIZ . '/COMMIT')) : null);

$contenedor = contenedorCli();
$bd = $contenedor->obtener(BaseDatos::class);
$ventana = max($duracion, 60) + 60;
$migraciones = array_map('strval', array_column($bd->consultar(
    'SELECT nombre FROM migraciones_aplicadas WHERE aplicada_en >= UTC_TIMESTAMP(3) - INTERVAL ' . $ventana . ' SECOND ORDER BY nombre'
), 'nombre'));
$datos = [
    'version' => $version, 'tipo' => $tipo, 'desde_version' => $texto('desde'), 'commit_git' => $commit === null ? null : mb_substr($commit, 0, 40),
    'responsable' => $responsable, 'migraciones' => json_encode($migraciones, JSON_THROW_ON_ERROR), 'respaldo' => $texto('respaldo'),
    'resultado' => $resultado, 'duracion_s' => $duracion,
];
$bd->ejecutar(
    'INSERT INTO despliegues (version, tipo, desde_version, commit_git, responsable, migraciones, respaldo, resultado, duracion_s)
     VALUES (:version, :tipo, :desde_version, :commit_git, :responsable, :migraciones, :respaldo, :resultado, :duracion_s)',
    $datos
);
$solicitud = $contenedor->obtener(ServicioMantenimiento::class)->solicitudDeSistema('cli', "Registro de {$tipo} {$version}");
$contenedor->obtener(ServicioAuditoria::class)->registrar(
    $solicitud,
    $tipo === 'despliegue' ? 'sistema.desplegar' : 'sistema.revertir',
    $resultado === 'exito' ? 'exito' : 'error',
    null,
    ['version' => $version, 'desde' => $datos['desde_version'], 'commit' => $datos['commit_git'], 'responsable' => $responsable,
        'migraciones' => $migraciones, 'respaldo' => $datos['respaldo'], 'duracion_s' => $duracion]
);
escribirLinea(sprintf('Registrado: %s %s (%s), %d migraciones, %d s.', $tipo, $version, $resultado, count($migraciones), $duracion));
exit(0);
