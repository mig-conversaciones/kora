<?php

declare(strict_types=1);

/**
 * Medición local e indicativa del tiempo de servidor por flujo (RNF-PERF-003; Anexo H). NO es la prueba de carga: corre
 * en proceso (sin Apache, red ni concurrencia), en el equipo de desarrollo y una solicitud a la vez. Sirve para detectar
 * regresiones entre versiones y para dimensionar antes de k6 en staging (RNF-PERF-018: no se extrapola a producción).
 *
 *   php tests/carga/medir_local.php --base=kora_carga --usuarios=almacenamiento/carga/usuarios.json [--repeticiones=30]
 *                                   [--vigencia=2026] [--salida=tests/carga/resultados/0.8.0-local]
 */

use App\Nucleo\BaseDatos;
use App\Nucleo\Entorno;
use Pruebas\Integracion\NavegadorPrueba;

define('RAIZ', dirname(__DIR__, 2));
require RAIZ . '/vendor/autoload.php';
Entorno::cargar(RAIZ . '/.env');
date_default_timezone_set('UTC');

$opciones = getopt('', ['base:', 'usuarios:', 'repeticiones:', 'vigencia:', 'salida:']);
$base = (string) ($opciones['base'] ?? 'kora_carga');
if (preg_match('/^[a-z0-9_]+_(carga|pruebas)$/', $base) !== 1) {
    fwrite(STDERR, "Solo sobre una base «*_carga» o «*_pruebas».\n");
    exit(2);
}
Entorno::establecer('BD_NOMBRE', $base);
$repeticiones = max(5, (int) ($opciones['repeticiones'] ?? 30));
$vigencia = (int) ($opciones['vigencia'] ?? gmdate('Y'));
$version = trim((string) @file_get_contents(RAIZ . '/VERSION')) ?: 'sin-version';
$salida = (string) ($opciones['salida'] ?? RAIZ . "/tests/carga/resultados/{$version}-local");
$usuarios = json_decode((string) file_get_contents((string) ($opciones['usuarios'] ?? RAIZ . '/almacenamiento/carga/usuarios.json')), true, 8, JSON_THROW_ON_ERROR);
$bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
$bd->ejecutar('UPDATE versiones_datos SET version = :v', ['v' => random_int(1_000_000, 2_000_000_000)]);

$cuenta = static function (string $rol, ?string $unidad = null) use ($usuarios): array {
    foreach ($usuarios as $u) {
        if ($u['rol'] === $rol && $u['centro'] === '9124' && ($unidad === null || $u['unidadPropia'] === $unidad)) {
            return $u;
        }
    }
    throw new RuntimeException("No hay cuenta de carga con el rol {$rol}.");
};
$ingresar = static function (array $usuario): NavegadorPrueba {
    $n = new NavegadorPrueba(null, '10.30.' . random_int(1, 250) . '.' . random_int(1, 250));
    $n->get('/ingresar');
    $n->post('/ingresar', ['correo' => $usuario['correo'], 'contrasena' => $usuario['contrasena']]);
    return $n;
};
$percentil = static function (array $valores, float $p): float {
    sort($valores);
    return round($valores[(int) min(count($valores) - 1, max(0, ceil($p * count($valores)) - 1))], 1);
};

$centro = $ingresar($cuenta('lider_produccion_centro'));
$lider = $ingresar($cuenta('lider_unidad', 'GAN'));
$flujos = [
    // flujo, objetivo p95 (caché fría / caliente), navegador, ruta, mide en frío
    ['Tablero central (centro)', 'tablero', [1200, 500], $centro, "/tableros/central?vigencia={$vigencia}", true],
    ['Tablero central (líder de GAN)', 'tablero', [1200, 500], $lider, "/tableros/central?vigencia={$vigencia}", true],
    ['Tablero técnico GAN', 'tablero', [1200, 500], $lider, "/tableros/unidad/GAN?vigencia={$vigencia}", true],
    ['Fragmento de filtro (IND_002 en unidades)', 'filtro', [800, 350], $centro, "/tableros/central/procesos?vigencia={$vigencia}&modo=unidades", true],
    ['Listado de salidas (centro)', 'listado', [600, 600], $centro, '/produccion/salidas?pagina=3', false],
    ['Listado de recaudos', 'listado', [600, 600], $centro, '/recaudos', false],
];
$filas = [];
foreach ($flujos as [$nombre, $tipo, $objetivos, $navegador, $ruta, $conFrio]) {
    $tiempos = ['fria' => [], 'caliente' => []];
    $navegador->get($ruta);
    for ($i = 0; $i < $repeticiones; $i++) {
        foreach ($conFrio ? ['fria', 'caliente'] : ['caliente'] as $estado) {
            if ($estado === 'fria') {
                $bd->ejecutar('UPDATE versiones_datos SET version = version + 1');
            }
            $inicio = hrtime(true);
            $respuesta = str_contains($ruta, '/procesos') ? $navegador->getHtmx($ruta) : $navegador->get($ruta);
            $tiempos[$estado][] = (hrtime(true) - $inicio) / 1e6;
            if ($respuesta->estado !== 200) {
                throw new RuntimeException("{$ruta} respondió {$respuesta->estado}.");
            }
        }
    }
    $fila = ['flujo' => $nombre, 'ruta' => $ruta, 'objetivo_fria' => $objetivos[0], 'objetivo_caliente' => $objetivos[1]];
    foreach ($tiempos as $estado => $valores) {
        if ($valores !== []) {
            $fila[$estado] = ['p50' => $percentil($valores, 0.5), 'p95' => $percentil($valores, 0.95), 'p99' => $percentil($valores, 0.99), 'n' => count($valores)];
        }
    }
    $filas[] = $fila;
    fwrite(STDOUT, sprintf(
        "%-45s fría p50/p95 %s · caliente p50/p95 %s ms\n",
        $nombre,
        isset($fila['fria']) ? "{$fila['fria']['p50']}/{$fila['fria']['p95']}" : '—',
        "{$fila['caliente']['p50']}/{$fila['caliente']['p95']}"
    ));
}

$md = ["# Medición local del tiempo de servidor — {$version}", '',
    '**Indicativa, no es la prueba de carga** (RNF-PERF-018): en proceso, sin Apache ni red, una solicitud a la vez, en el equipo de desarrollo (MySQL ' . $bd->valor('SELECT VERSION()') . ', PHP ' . PHP_VERSION . ") sobre el dataset «{$base}». {$repeticiones} repeticiones por flujo; «fría» sube la versión de datos antes de cada solicitud.", '',
    '| Flujo | Fría p50 / p95 / p99 | Objetivo fría | Caliente p50 / p95 / p99 | Objetivo caliente |', '|---|---|---|---|---|'];
$formato = static fn (?array $m): string => $m === null ? '—' : number_format($m['p50'], 1, ',', '.') . ' / ' . number_format($m['p95'], 1, ',', '.') . ' / ' . number_format($m['p99'], 1, ',', '.') . ' ms';
foreach ($filas as $fila) {
    $md[] = sprintf('| %s | %s | %s | %s | %s |', $fila['flujo'], $formato($fila['fria'] ?? null), isset($fila['fria']) ? number_format($fila['objetivo_fria'], 0, ',', '.') . ' ms' : '—', $formato($fila['caliente']), number_format($fila['objetivo_caliente'], 0, ',', '.') . ' ms');
}
if (!is_dir($salida)) {
    mkdir($salida, 0750, true);
}
file_put_contents($salida . '/tiempos-locales.md', implode(PHP_EOL, $md) . PHP_EOL);
file_put_contents($salida . '/tiempos-locales.json', json_encode(['version' => $version, 'base' => $base, 'repeticiones' => $repeticiones, 'flujos' => $filas], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
fwrite(STDOUT, "Escrito en {$salida}/tiempos-locales.md\n");
