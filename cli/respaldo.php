<?php

declare(strict_types=1);

/**
 * Copias de seguridad cifradas (RDL-003 a RDL-005; Anexo D, D.2). Por SSH o cron:
 *
 *   php cli/respaldo.php llaves --salida=DIR              par de llaves del custodio (la privada sale del servidor)
 *   php cli/respaldo.php crear [--tipo=diaria] [--motivo="..."]
 *   php cli/respaldo.php listar
 *   php cli/respaldo.php verificar --id=kora-diaria-...    SHA-256 de los archivos, sin descifrar
 *   php cli/respaldo.php restaurar --id=... --base=NOMBRE --identidad=ARCHIVO_PAR [--almacenamiento=DIR] [--acepto-perdida]
 *   php cli/respaldo.php podar                             retención: 30 diarias y 12 mensuales
 *   php cli/respaldo.php marcar-externo --id=...           tras copiar fuera de Hostinger (scripts/backup.sh)
 *   php cli/respaldo.php directorio                        ruta del directorio de copias (para el rsync)
 *   php cli/respaldo.php simulacro --identidad=ARCHIVO_PAR --base-destino=kora_restauracion [--acta=RUTA] [--base-origen=kora_carga]
 *
 * El simulacro es la prueba de restauración (RDL-005): cifras de control de la base activa, copia, restauración en una
 * base aislada («*_restauracion», que se vacía antes), cifras de la restaurada con la cadena de auditoría, tiempo medido
 * frente al RTO de 4 h y un acta en Markdown. Código de salida 0 solo si todo coincide.
 */

use App\Nucleo\BaseDatos;
use App\Nucleo\Entorno;
use App\Operacion\CifrasControl;
use App\Operacion\ServicioRespaldo;
use App\Operacion\SobreCifrado;

require __DIR__ . '/arranque.php';

$accion = $argv[1] ?? 'listar';
$opciones = opcionesCli($argv);
$texto = static fn (string $clave): ?string => isset($opciones[$clave]) && is_string($opciones[$clave]) && $opciones[$clave] !== '' ? $opciones[$clave] : null;
$fallar = static function (string $mensaje, int $codigo = 1): never {
    fwrite(STDERR, $mensaje . PHP_EOL);
    exit($codigo);
};
$leerPar = static function (?string $ruta) use ($fallar): string {
    if ($ruta === null || !is_file($ruta)) {
        $fallar('Indica --identidad con el archivo del par de llaves del custodio.', 2);
    }
    return trim((string) file_get_contents($ruta));
};
// Ensayos fuera de staging: el simulacro puede tomar como origen una base sintética («*_carga» o «*_pruebas»), nunca otra.
if ($accion === 'simulacro' && ($origen = $texto('base-origen')) !== null) {
    if (preg_match('/^[a-z0-9_]+_(carga|pruebas)$/', $origen) !== 1) {
        $fallar('--base-origen solo admite una base sintética «*_carga» o «*_pruebas».', 2);
    }
    Entorno::establecer('BD_NOMBRE', $origen);
}
$baseDatosPara = static function (string $nombre): BaseDatos {
    $config = require RAIZ . '/config/base_datos.php';
    $config['dsn'] = (string) preg_replace('/dbname=[^;]*/', 'dbname=' . $nombre, $config['dsn']);
    return new BaseDatos($config);
};

try {
    if ($accion === 'llaves') {
        $salida = $texto('salida') ?? $fallar('Indica --salida con un directorio fuera del repositorio.', 2);
        if (!is_dir($salida)) {
            mkdir($salida, 0700, true);
        }
        $llaves = SobreCifrado::generarLlaves();
        foreach (['custodio.publica' => $llaves['publica'], 'custodio.par' => $llaves['par']] as $nombre => $contenido) {
            $ruta = rtrim($salida, '/\\') . '/' . $nombre;
            if (file_exists($ruta)) {
                $fallar("Ya existe {$ruta}: no se sobrescriben llaves.");
            }
            file_put_contents($ruta, $contenido . PHP_EOL);
            chmod($ruta, 0600);
        }
        escribirLinea('Llaves generadas. Copia la pública en el .env:');
        escribirLinea('  RESPALDO_LLAVE_PUBLICA=' . $llaves['publica']);
        escribirLinea('Entrega custodio.par al custodio y bórralo de este equipo: sin él no se restaura ninguna copia.');
        exit(0);
    }

    $respaldo = contenedorCli()->obtener(ServicioRespaldo::class);

    switch ($accion) {
        case 'crear':
            $resultado = $respaldo->crear($texto('tipo') ?? 'diaria', $texto('motivo'));
            escribirLinea(sprintf('Copia completada en %.1f s (%s bytes cifrados).', $resultado['duracion_ms'] / 1000, number_format($resultado['bytes'], 0, ',', '.')));
            escribirLinea($resultado['identificador']);
            exit(0);
        case 'listar':
            foreach ($respaldo->listar((int) ($texto('limite') ?? 20)) as $fila) {
                escribirLinea(sprintf(
                    '%s  %-14s %-10s %12s B  externo:%s  %s%s',
                    $fila['creado_en'],
                    $fila['tipo'],
                    $fila['estado'],
                    number_format((int) $fila['bytes'], 0, ',', '.'),
                    (int) $fila['externo'] === 1 ? 'sí' : 'no',
                    $fila['identificador'],
                    $fila['eliminado_en'] !== null ? ' (podada)' : ''
                ));
            }
            exit(0);
        case 'verificar':
            $problemas = $respaldo->verificar($texto('id') ?? $fallar('Indica --id.', 2));
            escribirLinea($problemas === [] ? 'Copia completa: los archivos conservan su SHA-256.' : implode(PHP_EOL, $problemas));
            exit($problemas === [] ? 0 : 1);
        case 'restaurar':
            $base = $texto('base') ?? $fallar('Indica --base con la base de destino.', 2);
            $resultado = $respaldo->restaurar($texto('id') ?? $fallar('Indica --id.', 2), $base, $leerPar($texto('identidad')), isset($opciones['acepto-perdida']), $texto('almacenamiento'));
            escribirLinea("Restauración completada en {$resultado['segundos']} s en «{$base}». Ejecuta las cifras de control y las pruebas de humo.");
            exit(0);
        case 'podar':
            $podados = $respaldo->podar();
            escribirLinea(count($podados) . ' copias podadas por la retención.');
            exit(0);
        case 'directorio':
            escribirLinea($respaldo->directorio());
            exit(0);
        case 'marcar-externo':
            $respaldo->marcarExterno($texto('id') ?? $fallar('Indica --id.', 2));
            exit(0);
        case 'simulacro':
            $par = $leerPar($texto('identidad'));
            $destino = $texto('base-destino') ?? 'kora_restauracion';
            if (preg_match('/^[a-z0-9_]+_restauracion$/', $destino) !== 1 || $destino === $respaldo->baseActiva()) {
                $fallar('La base del simulacro debe llamarse «*_restauracion» y ser distinta de la activa: se vacía antes de restaurar.', 2);
            }
            $origen = $baseDatosPara($respaldo->baseActiva());
            $aislada = $baseDatosPara($destino);
            $aislada->ejecutar('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($aislada->consultar('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $tabla) {
                $aislada->ejecutar('DROP TABLE `' . str_replace('`', '', (string) $tabla['t']) . '`');
            }
            escribirLinea('1/4 Cifras de control de la base activa…');
            $inicioCifras = microtime(true);
            $antes = (new CifrasControl($origen))->calcular();
            $segundosCifras = round(microtime(true) - $inicioCifras, 1);
            escribirLinea('2/4 Copia cifrada…');
            $copia = $respaldo->crear('manual', 'Prueba de restauración (RDL-005)', SobreCifrado::publicaDelPar($par));
            escribirLinea("3/4 Restauración en «{$destino}»…");
            $directorioAlmacenamiento = sys_get_temp_dir() . '/kora-simulacro-' . bin2hex(random_bytes(4));
            $restauracion = $respaldo->restaurar($copia['identificador'], $destino, $par, false, $directorioAlmacenamiento);
            escribirLinea('4/4 Cifras de control de la base restaurada y cadena de auditoría…');
            $despues = (new CifrasControl($baseDatosPara($destino)))->calcular();
            // La fila de la propia copia se registra después del volcado: se descarta de la comparación.
            $diferencias = CifrasControl::diferencias($antes, $despues);
            $filas = array_sum(array_map(static fn (array $t): int => array_sum(array_column($t, 'filas')), $antes['tablas']));
            $version = is_file(RAIZ . '/VERSION') ? trim((string) file_get_contents(RAIZ . '/VERSION')) : 'sin versión';
            $acta = implode(PHP_EOL, [
                '# Acta de prueba de restauración (RDL-005)',
                '',
                '| Campo | Valor |',
                '|---|---|',
                '| Fecha (UTC) | ' . gmdate('Y-m-d H:i') . ' |',
                '| Entorno | ' . (Entorno::texto('APP_ENTORNO', 'produccion') === 'local' ? 'local (ensayo: no sustituye la prueba trimestral en staging)' : Entorno::texto('APP_ENTORNO', 'produccion')) . ' · base `' . Entorno::texto('BD_NOMBRE') . '` · ' . (string) $origen->valor('SELECT VERSION()') . ' · PHP ' . PHP_VERSION . ' |',
                '| Versión de KORA | ' . $version . ' · migración ' . ($antes['migracion'] ?? '—') . ' |',
                '| Copia | `' . $copia['identificador'] . '` · ' . number_format($copia['bytes'] / 1048576, 1, ',', '.') . ' MB cifrados · ' . number_format($copia['duracion_ms'] / 1000, 1, ',', '.') . ' s |',
                '| Base aislada | `' . $destino . '` (vaciada antes de restaurar) |',
                '| Tiempo de restauración | ' . number_format($restauracion['segundos'], 1, ',', '.') . ' s, frente al RTO de 4 h: ' . ($restauracion['segundos'] <= 14400 ? 'cumple' : 'NO cumple') . ' |',
                '| Filas comparadas | ' . number_format($filas, 0, ',', '.') . ' en ' . count($antes['tablas']) . ' tablas, por centro, con sus sumas de control |',
                '| Auditoría | ' . number_format($despues['auditoria']['eventos'], 0, ',', '.') . ' eventos; cadena ' . ($despues['auditoria']['cadena_integra'] ? 'íntegra, recalculada evento por evento' : 'NO íntegra: ' . $despues['auditoria']['detalle']) . ' |',
                '| Huella de las cifras | origen `' . substr($antes['huella'], 0, 16) . '…` · restaurada `' . substr($despues['huella'], 0, 16) . '…` |',
                '| Cálculo de las cifras | ' . number_format($segundosCifras, 1, ',', '.') . ' s |',
                '| Resultado | ' . ($diferencias === [] ? '**Conforme**: conteos, sumas, migración y cadena coinciden.' : '**No conforme**') . ' |',
                '',
                '## Diferencias',
                '',
                $diferencias === [] ? 'Ninguna.' : '- ' . implode(PHP_EOL . '- ', $diferencias),
                '',
                '## Acciones correctivas',
                '',
                $diferencias === [] ? 'Ninguna.' : 'Pendiente: revisar el proceso de copia (runbook «Backup corrupto»).',
                '',
                '## Firmas',
                '',
                '- Ejecutó: ______________________ · Revisó (contraparte): ______________________',
                '',
            ]);
            $rutaActa = $texto('acta') ?? RAIZ . '/almacenamiento/actas/restauracion-' . gmdate('Ymd-His') . '.md';
            if (!is_dir(dirname($rutaActa))) {
                mkdir(dirname($rutaActa), 0750, true);
            }
            file_put_contents($rutaActa, $acta);
            escribirLinea($diferencias === [] ? 'Simulacro conforme.' : 'Simulacro NO conforme:' . PHP_EOL . '  ' . implode(PHP_EOL . '  ', $diferencias));
            escribirLinea("Acta: {$rutaActa}");
            exit($diferencias === [] ? 0 : 1);
        default:
            $fallar('Uso: php cli/respaldo.php llaves|crear|listar|verificar|restaurar|podar|directorio|marcar-externo|simulacro [opciones]', 2);
    }
} catch (Throwable $error) {
    $fallar('Error: ' . $error->getMessage());
}
