<?php

declare(strict_types=1);

/**
 * Arranque común de la CLI. Solo se ejecuta desde la línea de comandos (por SSH en Hostinger o cron), nunca por
 * la web: cli/ queda fuera de public/.
 */

use App\Nucleo\Entorno;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!defined('RAIZ')) {
    define('RAIZ', dirname(__DIR__));
}

require RAIZ . '/vendor/autoload.php';

Entorno::cargar(RAIZ . '/.env');
date_default_timezone_set('UTC');

// Gancho de las pruebas de integración (procesos concurrentes del trabajador): solo admite bases «*_pruebas»,
// de modo que nunca puede redirigir la CLI a la base de desarrollo o de producción.
$basePruebas = getenv('KORA_BD_PRUEBAS');
if (is_string($basePruebas) && preg_match('/^[a-z0-9_]+_pruebas$/', $basePruebas) === 1) {
    Entorno::establecer('BD_NOMBRE', $basePruebas);
}

/**
 * Opciones --clave=valor y banderas --clave.
 *
 * @param list<string> $argumentos
 * @return array<string,string|true>
 */
function opcionesCli(array $argumentos): array
{
    $opciones = [];
    foreach (array_slice($argumentos, 1) as $argumento) {
        if (preg_match('/^--([a-z0-9_-]+)(?:=(.*))?$/i', $argumento, $m) === 1) {
            $opciones[$m[1]] = $m[2] ?? true;
        }
    }
    return $opciones;
}

function escribirLinea(string $texto = ''): void
{
    fwrite(STDOUT, $texto . PHP_EOL);
}

/** Contenedor con las mismas fábricas que la web (config/app.php). */
function contenedorCli(): App\Nucleo\Contenedor
{
    return App\Nucleo\Contenedor::desdeConfiguracion(require RAIZ . '/config/app.php');
}

/**
 * Toma una de $ranuras ranuras exclusivas con flock (sin esperar). Devuelve el recurso o null si todas están
 * ocupadas: así se limita el número de procesos simultáneos de un mismo tipo (RF-Q-005).
 *
 * @return resource|null
 */
function tomarRanura(string $nombre, int $ranuras)
{
    $directorio = RAIZ . '/almacenamiento/bloqueos';
    if (!is_dir($directorio)) {
        mkdir($directorio, 0750, true);
    }
    for ($i = 1; $i <= $ranuras; $i++) {
        $archivo = fopen("{$directorio}/{$nombre}-{$i}.lock", 'c');
        if ($archivo !== false && flock($archivo, LOCK_EX | LOCK_NB)) {
            return $archivo;
        }
        if ($archivo !== false) {
            fclose($archivo);
        }
    }
    return null;
}
