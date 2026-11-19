<?php

declare(strict_types=1);

/**
 * Pruebas de humo tras un despliegue o una reversión (ROB-001 paso 12, ROB-004). Corren en proceso, sin red, contra la
 * versión que apunta `current`, de modo que funcionan igual en Hostinger, en la CI y en el MAMP local:
 *
 *   php cli/humo.php [--url=https://dominio]     con --url también consulta /health/live por HTTP
 *
 *   1. El arranque carga la configuración y las rutas.
 *   2. GET /health/live responde 200 {"estado":"vivo"}.
 *   3. GET /health/ready con el token de monitoreo: base de datos, esquema esperado y almacenamiento escribible.
 *      (La cola, el trabajador y el programador pueden estar en espera justo tras el despliegue: no hacen fallar.)
 *   4. GET /ingresar responde 200 con la CSP y sin errores.
 *   5. Los activos con huella del manifiesto existen.
 * Código de salida 0 solo si todo pasa.
 */

use App\Nucleo\Aplicacion;
use App\Nucleo\Entorno;
use App\Nucleo\Solicitud;

require __DIR__ . '/arranque.php';

$opciones = opcionesCli($argv);
$fallos = [];
$pasar = static function (string $nombre, bool $correcto, string $detalle = '') use (&$fallos): void {
    escribirLinea(($correcto ? '  ✔ ' : '  ✘ ') . $nombre . ($detalle !== '' ? " — {$detalle}" : ''));
    if (!$correcto) {
        $fallos[] = $nombre;
    }
};
$solicitar = static function (string $ruta, array $cabeceras = []): App\Nucleo\Respuesta {
    $aplicacion = Aplicacion::crear(require RAIZ . '/config/app.php');
    return $aplicacion->manejar(new Solicitud('GET', $ruta, [], [], $cabeceras + ['user-agent' => 'KORA-humo'], [], '127.0.0.1'));
};

try {
    $vivo = $solicitar('/health/live');
    $pasar('/health/live', $vivo->estado === 200 && str_contains($vivo->cuerpo, '"vivo"'), "HTTP {$vivo->estado}");

    $listo = $solicitar('/health/ready', ['x-token-monitoreo' => Entorno::texto('TOKEN_MONITOREO')]);
    $estado = json_decode($listo->cuerpo, true);
    $comprobaciones = is_array($estado['comprobaciones'] ?? null) ? $estado['comprobaciones'] : [];
    foreach (['base_datos', 'esquema', 'disco', 'almacenamiento'] as $critica) {
        $pasar("/health/ready: {$critica}", ($comprobaciones[$critica] ?? false) === true);
    }
    if (($estado['mantenimiento'] ?? false) === true) {
        escribirLinea('  · El mantenimiento está activo: /health/ready responde 503 hasta desactivarlo (esperado en un despliegue con mantenimiento).');
    }

    $ingreso = $solicitar('/ingresar');
    $pasar('/ingresar', in_array($ingreso->estado, [200, 503], true) && isset($ingreso->cabeceras['Content-Security-Policy']), "HTTP {$ingreso->estado}");

    $manifiesto = json_decode((string) @file_get_contents(RAIZ . '/public/assets/manifest.json'), true);
    $faltan = [];
    foreach (is_array($manifiesto) ? $manifiesto : [] as $huella) {
        if (!is_file(RAIZ . '/public/assets/' . ltrim((string) $huella, '/'))) {
            $faltan[] = (string) $huella;
        }
    }
    $pasar('Activos con huella', is_array($manifiesto) && $manifiesto !== [] && $faltan === [], $faltan === [] ? count((array) $manifiesto) . ' archivos' : 'faltan ' . implode(', ', array_slice($faltan, 0, 3)));

    if (is_string($opciones['url'] ?? null)) {
        $contexto = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $cuerpo = @file_get_contents(rtrim($opciones['url'], '/') . '/health/live', false, $contexto);
        $pasar('HTTP ' . $opciones['url'] . '/health/live', is_string($cuerpo) && str_contains($cuerpo, '"vivo"'));
    }
} catch (Throwable $error) {
    $pasar('Arranque de la aplicación', false, $error::class . ': ' . mb_substr($error->getMessage(), 0, 160));
}

escribirLinea($fallos === [] ? 'Pruebas de humo: todo en verde.' : 'Pruebas de humo con fallos: ' . implode(', ', $fallos) . '.');
exit($fallos === [] ? 0 : 1);
