<?php

declare(strict_types=1);

/**
 * Inventario de consultas con EXPLAIN (RNF-PERF-007 a RNF-PERF-009; Anexo H, H.1) sobre el dataset sintético.
 *
 *   php tests/carga/inventario_consultas.php --base=kora_carga --usuarios=almacenamiento/carga/usuarios.json
 *                                            [--vigencia=2026] [--salida=tests/carga/resultados/0.8.0-local]
 *
 * Inicia sesión con las cuentas de carga (cli/generar_dataset.php), recorre los tableros y los listados con el
 * navegador en proceso de las pruebas, captura cada consulta con el observador de BaseDatos y ejecuta EXPLAIN sobre las
 * lecturas distintas. También captura las consultas de la cola y de la auditoría llamando a sus servicios. Escribe
 * consultas.json (detalle) y consultas.md (resumen con el número de consultas por solicitud frente a su máximo).
 *
 * En MySQL 5.7 el plan es estimado (EXPLAIN); en MariaDB 10.6 de staging se repite con ANALYZE FORMAT=JSON (H.1).
 */

use App\Cola\ConductorCola;
use App\Nucleo\BaseDatos;
use App\Nucleo\Contenedor;
use App\Nucleo\Entorno;
use App\Servicios\ServicioConsultaAuditoria;
use Pruebas\Integracion\NavegadorPrueba;

define('RAIZ', dirname(__DIR__, 2));
require RAIZ . '/vendor/autoload.php';
Entorno::cargar(RAIZ . '/.env');
date_default_timezone_set('UTC');

$opciones = getopt('', ['base:', 'usuarios:', 'vigencia:', 'salida:']);
$base = (string) ($opciones['base'] ?? 'kora_carga');
if (preg_match('/^[a-z0-9_]+_(carga|pruebas)$/', $base) !== 1) {
    fwrite(STDERR, "Solo sobre una base «*_carga» o «*_pruebas».\n");
    exit(2);
}
Entorno::establecer('BD_NOMBRE', $base);
$vigencia = (int) ($opciones['vigencia'] ?? gmdate('Y'));
$version = trim((string) @file_get_contents(RAIZ . '/VERSION')) ?: 'sin-version';
$salida = (string) ($opciones['salida'] ?? RAIZ . "/tests/carga/resultados/{$version}-local");
$usuarios = json_decode((string) file_get_contents((string) ($opciones['usuarios'] ?? RAIZ . '/almacenamiento/carga/usuarios.json')), true, 8, JSON_THROW_ON_ERROR);
$bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
// La caché vive en archivos: una versión de datos aleatoria garantiza que la medición «en frío» no lea entradas viejas.
$bd->ejecutar('UPDATE versiones_datos SET version = :v', ['v' => random_int(1_000_000, 2_000_000_000)]);

$cuenta = static function (string $rol, ?string $unidad = null) use ($usuarios): array {
    foreach ($usuarios as $u) {
        if ($u['rol'] === $rol && $u['centro'] === '9124' && ($unidad === null || $u['unidadPropia'] === $unidad)) {
            return $u;
        }
    }
    throw new RuntimeException("No hay cuenta de carga con el rol {$rol}" . ($unidad ? " y la unidad {$unidad}" : '') . '.');
};

/** @var list<array{sql:string,parametros:array<int|string,scalar|null>,ms:float}> $capturadas */
$capturadas = [];
$observar = static function (Contenedor $c) use (&$capturadas): void {
    $c->obtener(BaseDatos::class)->observar(static function (string $sql, array $parametros, float $ms) use (&$capturadas): void {
        $capturadas[] = ['sql' => $sql, 'parametros' => $parametros, 'ms' => $ms];
    });
};
$navegadores = [];
$navegador = static function (array $usuario) use (&$navegadores, $observar): NavegadorPrueba {
    if (!isset($navegadores[$usuario['alias']])) {
        $n = new NavegadorPrueba(Closure::fromCallable($observar), '10.20.' . random_int(1, 250) . '.' . random_int(1, 250));
        $n->get('/ingresar');
        $n->post('/ingresar', ['correo' => $usuario['correo'], 'contrasena' => $usuario['contrasena']]);
        if ($n->ultima?->estado !== 303 || preg_match('#^/(ingresar|2fa)#', $n->destino()) === 1) {
            throw new RuntimeException("No se pudo iniciar sesión con {$usuario['alias']}: " . $n->destino());
        }
        $navegadores[$usuario['alias']] = $n;
    }
    return $navegadores[$usuario['alias']];
};

$escenarios = [
    ['Tablero central (centro)', 'tablero', 15, $cuenta('lider_produccion_centro'), "/tableros/central?vigencia={$vigencia}", false],
    ['Tablero central (centro, filtro de fechas, HTMX)', 'filtro', 15, $cuenta('lider_produccion_centro'), "/tableros/central?vigencia={$vigencia}&desde={$vigencia}-03-01&hasta={$vigencia}-06-30", true],
    ['Tablero central (líder de GAN)', 'tablero', 15, $cuenta('lider_unidad', 'GAN'), "/tableros/central?vigencia={$vigencia}", false],
    ['Tablero técnico GAN (líder de GAN)', 'tablero', 15, $cuenta('lider_unidad', 'GAN'), "/tableros/unidad/GAN?vigencia={$vigencia}", false],
    ['Listado de salidas (centro)', 'listado', 10, $cuenta('lider_produccion_centro'), '/produccion/salidas', false],
    ['Listado de salidas, página 5 (líder de GAN)', 'listado', 10, $cuenta('lider_unidad', 'GAN'), '/produccion/salidas?pagina=5', true],
    ['Listado de recepciones (centro)', 'listado', 10, $cuenta('lider_produccion_centro'), '/produccion/recepciones', false],
    ['Listado de recaudos (Grupo de Recaudo)', 'listado', 10, $cuenta('grupo_recaudo'), '/recaudos', false],
    ['Bandeja de clasificación de recaudos', 'listado', 10, $cuenta('grupo_recaudo'), '/recaudos/clasificar', false],
    ['Listado de gastos (centro)', 'listado', 10, $cuenta('coordinacion_administrativa'), "/finanzas/gastos?vigencia={$vigencia}", false],
    ['Formulario de salida nueva (líder de GAN)', 'formulario', 6, $cuenta('lider_unidad', 'GAN'), '/produccion/salidas/nueva', true],
];

/** @var list<array{escenario:string,tipo:string,maximo:?int,ruta:string,medidas:array<string,array{estado_http:int,consultas:int,ms:float,consultas_capturadas:list<array{sql:string,parametros:array<int|string,scalar|null>,ms:float}>}>}> $resultados */
$resultados = [];
foreach ($escenarios as [$nombre, $tipo, $maximo, $usuario, $ruta, $htmx]) {
    $n = $navegador($usuario);
    $medidas = [];
    foreach (['fria', 'caliente'] as $estado) {
        if ($estado === 'fria') {
            $bd->ejecutar('UPDATE versiones_datos SET version = version + 1');
        }
        $capturadas = [];
        $inicio = hrtime(true);
        $respuesta = $htmx ? $n->getHtmx($ruta) : $n->get($ruta);
        $ms = (hrtime(true) - $inicio) / 1e6;
        $medidas[$estado] = ['estado_http' => $respuesta->estado, 'consultas' => count($capturadas), 'ms' => round($ms, 1), 'consultas_capturadas' => $capturadas];
    }
    $resultados[] = ['escenario' => $nombre, 'tipo' => $tipo, 'maximo' => $maximo, 'ruta' => $ruta, 'medidas' => $medidas];
    fwrite(STDOUT, sprintf("%-55s %3d / %3d consultas (fría / caliente, máx. %d) · %6.1f / %6.1f ms · HTTP %d\n", $nombre, $medidas['fria']['consultas'], $medidas['caliente']['consultas'], $maximo, $medidas['fria']['ms'], $medidas['caliente']['ms'], $medidas['fria']['estado_http']));
}

// Consultas de infraestructura llamadas directamente: cola (reclamo y vencidas) y auditoría (paginación por cursor).
/** @var list<array{escenario:string,tipo:string,maximo:?int,ruta:string,medidas:array<string,array{estado_http:int,consultas:int,ms:float,consultas_capturadas:list<array{sql:string,parametros:array<int|string,scalar|null>,ms:float}>}>}> $directas */
$directas = [];
$contenedor = Contenedor::desdeConfiguracion(require RAIZ . '/config/app.php');
$bdServicios = $contenedor->obtener(BaseDatos::class);
foreach ([
    'Reclamo de la cola (predeterminada)' => static fn () => $contenedor->obtener(ConductorCola::class)->reclamar('predeterminada', 10, bin2hex(random_bytes(16)), 60),
    'Recuperación de reservas vencidas' => static fn () => $contenedor->obtener(ConductorCola::class)->recuperarVencidos(),
    'Auditoría, primera página' => static fn () => (new ServicioConsultaAuditoria($bdServicios))->buscar([], 50),
    'Auditoría por acción' => static fn () => (new ServicioConsultaAuditoria($bdServicios))->buscar(['accion' => 'salidas.crear'], 50),
] as $nombre => $llamada) {
    $capturadas = [];
    $bdServicios->observar(static function (string $sql, array $parametros, float $ms) use (&$capturadas): void {
        $capturadas[] = ['sql' => $sql, 'parametros' => $parametros, 'ms' => $ms];
    });
    $inicio = hrtime(true);
    $llamada();
    $directas[] = ['escenario' => $nombre, 'tipo' => 'infraestructura', 'maximo' => null, 'ruta' => '—',
        'medidas' => ['fria' => ['estado_http' => 0, 'consultas' => count($capturadas), 'ms' => round((hrtime(true) - $inicio) / 1e6, 1), 'consultas_capturadas' => $capturadas]]];
    fwrite(STDOUT, sprintf("%-55s %3d consultas · %6.1f ms\n", $nombre, count($capturadas), (hrtime(true) - $inicio) / 1e6));
}
$bdServicios->observar(null);

// EXPLAIN de cada lectura (o actualización) distinta, con sus mismos parámetros.
$planes = [];
foreach (array_merge($resultados, $directas) as $resultado) {
    foreach ($resultado['medidas']['fria']['consultas_capturadas'] as $consulta) {
        $sql = trim((string) preg_replace('/\s+/', ' ', $consulta['sql']));
        if (preg_match('/^(SELECT|UPDATE)\b/i', $sql) !== 1 || str_contains($sql, 'FOR UPDATE') && str_contains($sql, 'cadena_auditoria')) {
            continue;
        }
        $huella = substr(hash('sha256', $sql), 0, 12);
        $planes[$huella]['sql'] = $sql;
        $planes[$huella]['escenarios'][$resultado['escenario']] = true;
        $planes[$huella]['ms'][] = $consulta['ms'];
        if (!isset($planes[$huella]['plan'])) {
            try {
                $planes[$huella]['plan'] = $bd->consultar('EXPLAIN ' . $consulta['sql'], $consulta['parametros']);
            } catch (Throwable $error) {
                $planes[$huella]['plan'] = [['error' => mb_substr($error->getMessage(), 0, 160)]];
            }
        }
    }
}

$alertas = static function (array $plan): array {
    $marcas = [];
    foreach ($plan as $fila) {
        $extra = (string) ($fila['Extra'] ?? '');
        if (($fila['type'] ?? '') === 'ALL' && (int) ($fila['rows'] ?? 0) > 1000) {
            $marcas[] = 'recorrido completo de ' . $fila['table'];
        }
        if (str_contains($extra, 'Using filesort') && (int) ($fila['rows'] ?? 0) > 1000) {
            $marcas[] = 'filesort en ' . $fila['table'];
        }
        if (str_contains($extra, 'Using temporary') && (int) ($fila['rows'] ?? 0) > 1000) {
            $marcas[] = 'temporal en ' . $fila['table'];
        }
    }
    return $marcas;
};

if (!is_dir($salida)) {
    mkdir($salida, 0750, true);
}
$motor = (string) $bd->valor('SELECT VERSION()');
$conteos = [];
foreach (['salidas', 'recaudos', 'recepciones_lote', 'gastos_ejecutados', 'eventos_auditoria', 'trabajos'] as $tabla) {
    $conteos[$tabla] = (int) $bd->valor("SELECT COUNT(*) FROM {$tabla}");
}
// Costo en escritura (H.1, paso 4): 1.000 salidas en bloques de 250 dentro de una transacción que se revierte. Se toma
// la mediana de 5 repeticiones para atenuar el ruido del equipo.
$lote = $bd->fila("SELECT r.id, r.centro_id, r.unidad_id, r.producto_id, r.medida FROM recepciones_lote r JOIN centros c ON c.id = r.centro_id AND c.codigo = '9124' ORDER BY r.id LIMIT 1");
$tiemposEscritura = [];
for ($repeticion = 0; $repeticion < 5 && $lote !== null; $repeticion++) {
    $bd->pdo()->beginTransaction();
    $inicio = hrtime(true);
    for ($bloque = 0; $bloque < 4; $bloque++) {
        $filas = [];
        $parametros = [];
        for ($i = 0; $i < 250; $i++) {
            $filas[] = '(?, ?, ?, ?, ?, 1, ?, 1000, 1000, \'VENTA_DIRECTA\', ?, \'integracion\')';
            array_push($parametros, $lote['centro_id'], $lote['unidad_id'], $lote['id'], '2026-11-01', $lote['producto_id'], $lote['medida'], hash('sha256', "escritura|{$repeticion}|{$bloque}|{$i}|" . microtime(true)));
        }
        $bd->ejecutar('INSERT INTO salidas (centro_id, unidad_id, recepcion_id, fecha, producto_id, cantidad, medida, precio_unitario, valor, canal, clave_natural, origen_tipo) VALUES ' . implode(', ', $filas), $parametros);
    }
    $tiemposEscritura[] = (hrtime(true) - $inicio) / 1e6;
    $bd->pdo()->rollBack();
}
sort($tiemposEscritura);
$escritura = $tiemposEscritura === [] ? null : round($tiemposEscritura[intdiv(count($tiemposEscritura), 2)], 1);
fwrite(STDOUT, 'Costo en escritura: 1.000 salidas en ' . ($escritura ?? '—') . " ms (mediana de 5)\n");
$indices = array_map(static fn (array $f): string => (string) $f['Key_name'], $bd->consultar('SHOW INDEX FROM salidas WHERE Seq_in_index = 1'));

$sinParametros = static fn (array $r): array => array_map(static function (array $m): array {
    unset($m['consultas_capturadas']);
    return $m;
}, $r['medidas']);
file_put_contents($salida . '/consultas.json', json_encode([
    'version' => $version, 'motor' => $motor, 'base' => $base, 'fecha' => gmdate('c'), 'conteos' => $conteos,
    'escritura_1000_salidas_ms' => $escritura, 'indices_salidas' => $indices,
    'escenarios' => array_map(static fn (array $r): array => ['escenario' => $r['escenario'], 'tipo' => $r['tipo'], 'maximo' => $r['maximo'], 'ruta' => $r['ruta'], 'medidas' => $sinParametros($r)], array_merge($resultados, $directas)),
    'planes' => array_map(static fn (array $p): array => ['sql' => $p['sql'], 'escenarios' => array_keys($p['escenarios']), 'ms_max' => round(max($p['ms']), 2), 'plan' => $p['plan']], $planes),
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$md = ["# Inventario de consultas — {$version} (MAMP local)", '',
    "Medición en MySQL {$motor} sobre el dataset sintético «{$base}» (" . implode(', ', array_map(static fn (string $t, int $n): string => "{$t}: " . number_format($n, 0, ',', '.'), array_keys($conteos), $conteos)) . ').',
    'Los planes son estimados (EXPLAIN de MySQL 5.7); en staging se repiten con ANALYZE FORMAT=JSON de MariaDB 10.6. Los tiempos son de un equipo de desarrollo: orientan, no demuestran el SLO (RNF-PERF-018).', '',
    '## Consultas por solicitud (RNF-PERF-009)', '', '| Escenario | Consultas en frío / caliente | Máximo | Tiempo en frío / caliente | Cumple |', '|---|---|---|---|---|'];
foreach (array_merge($resultados, $directas) as $r) {
    $fria = $r['medidas']['fria'];
    $caliente = $r['medidas']['caliente'] ?? null;
    $md[] = sprintf(
        '| %s | %s | %s | %s | %s |',
        $r['escenario'],
        $caliente === null ? (string) $fria['consultas'] : "{$fria['consultas']} / {$caliente['consultas']}",
        $r['maximo'] ?? '—',
        $caliente === null ? number_format($fria['ms'], 1, ',', '.') . ' ms' : number_format($fria['ms'], 1, ',', '.') . ' / ' . number_format($caliente['ms'], 1, ',', '.') . ' ms',
        $r['maximo'] === null ? '—' : ($fria['consultas'] <= $r['maximo'] ? 'Sí' : '**No**')
    );
}
$md[] = '';
$md[] = '## Planes de las lecturas distintas';
$md[] = '';
$md[] = '| # | Tablas: acceso, índice y filas estimadas | Extra relevante | Tiempo máx. | Observación |';
$md[] = '|---|---|---|---|---|';
$i = 0;
foreach ($planes as $huella => $p) {
    $i++;
    $accesos = [];
    $extras = [];
    foreach ($p['plan'] as $fila) {
        if (isset($fila['error'])) {
            $accesos[] = 'EXPLAIN no disponible';
            continue;
        }
        $accesos[] = sprintf('%s: %s, %s, %s', $fila['table'] ?? '—', $fila['type'] ?? '—', $fila['key'] ?? 'sin índice', number_format((int) ($fila['rows'] ?? 0), 0, ',', '.'));
        foreach (['Using filesort', 'Using temporary', 'Using index'] as $marca) {
            if (str_contains((string) ($fila['Extra'] ?? ''), $marca)) {
                $extras[$marca] = true;
            }
        }
    }
    $marcas = $alertas($p['plan']);
    $md[] = sprintf('| %d `%s` | %s | %s | %s ms | %s |', $i, $huella, implode('<br>', $accesos), implode(', ', array_keys($extras)) ?: '—', number_format(max($p['ms']), 1, ',', '.'), $marcas === [] ? 'Sin alertas' : '**' . implode('; ', $marcas) . '**');
}
$md[] = '';
$md[] = 'El texto completo de cada consulta y su plan están en `consultas.json`, con la misma huella.';
$md[] = '';
$md[] = '## Costo en escritura (H.1, paso 4)';
$md[] = '';
$md[] = 'Inserción de 1.000 salidas en bloques de 250, dentro de una transacción revertida (mediana de 5): **' . ($escritura === null ? '—' : number_format($escritura, 1, ',', '.') . ' ms') . '**, con ' . count($indices) . ' índices en `salidas` (' . implode(', ', $indices) . ').';
file_put_contents($salida . '/consultas.md', implode(PHP_EOL, $md) . PHP_EOL);
fwrite(STDOUT, "Inventario escrito en {$salida}\n");
