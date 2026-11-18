<?php

declare(strict_types=1);

/**
 * Verificación del entorno (RNF-018, RBD-001; ítems 4 y 6 del plan de verificación de Hostinger, 4.8).
 * Devuelve código distinto de cero si falta un requisito obligatorio. No imprime secretos.
 *
 *   php cli/verificar_entorno.php
 */

use App\Nucleo\BaseDatos;
use App\Nucleo\Entorno;

require __DIR__ . '/arranque.php';

$entorno = Entorno::texto('APP_ENTORNO', 'produccion');
$esLocal = $entorno === 'local';
$fallos = 0;
$avisos = 0;

$informar = static function (string $estado, string $texto) use (&$fallos, &$avisos): void {
    $marca = ['ok' => '✔', 'aviso' => '!', 'fallo' => '✘'][$estado];
    $fallos += $estado === 'fallo' ? 1 : 0;
    $avisos += $estado === 'aviso' ? 1 : 0;
    escribirLinea("  {$marca} {$texto}");
};

escribirLinea("KORA · verificación del entorno «{$entorno}»");
escribirLinea('');
escribirLinea('PHP');
$informar(version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'fallo', 'PHP ' . PHP_VERSION . ' (mínimo 8.2; objetivo 8.3)');
foreach (['pdo_mysql', 'mbstring', 'intl', 'sodium', 'zip', 'fileinfo', 'openssl', 'json'] as $extension) {
    $informar(extension_loaded($extension) ? 'ok' : 'fallo', "Extensión {$extension}");
}
$opcache = extension_loaded('Zend OPcache') || extension_loaded('opcache');
$informar($opcache ? 'ok' : ($esLocal ? 'aviso' : 'fallo'), 'Extensión opcache' . ($opcache ? '' : ($esLocal ? ' (no cargada; obligatoria en producción)' : '')));
$informar(defined('PASSWORD_ARGON2ID') ? 'ok' : 'fallo', 'PASSWORD_ARGON2ID disponible (RAC-002)');

escribirLinea('');
escribirLinea('Configuración');
foreach (['APP_URL', 'BD_NOMBRE', 'BD_USUARIO', 'BD_CONTRASENA', 'CLAVES_CIFRADO', 'CLAVE_HMAC_PII', 'CLAVE_HMAC_CODIGOS', 'TOKEN_MONITOREO'] as $clave) {
    $valor = Entorno::texto($clave, '');
    $informar($valor !== '' && !str_contains($valor, 'REEMPLAZAR') ? 'ok' : 'fallo', "{$clave} definida");
}
$https = str_starts_with(Entorno::texto('APP_URL', ''), 'https://');
$informar($https ? 'ok' : ($esLocal ? 'aviso' : 'fallo'), 'APP_URL usa HTTPS' . ($https ? '' : ($esLocal ? ' (HTTP permitido solo en local)' : '')));
if (!$esLocal && Entorno::booleano('APP_DEPURACION', false)) {
    $informar('fallo', 'APP_DEPURACION debe ser false fuera de local');
}

escribirLinea('');
escribirLinea('Base de datos');
$config = require RAIZ . '/config/base_datos.php';
try {
    $bd = new BaseDatos($config);
    $version = (string) $bd->valor('SELECT VERSION()');
    $esMariaDb = stripos($version, 'mariadb') !== false;
    $motor = $esMariaDb ? 'mariadb' : 'mysql';
    preg_match('/^\d+\.\d+\.\d+/', $version, $coincidencia);
    $numero = $coincidencia[0] ?? '0.0.0';
    $minima = $config['version_minima'][$motor];
    if (version_compare($numero, $minima, '>=')) {
        $informar('ok', "Motor {$motor} {$numero} (mínimo {$minima})");
    } elseif ($esLocal && isset($config['version_minima_local'][$motor]) && version_compare($numero, $config['version_minima_local'][$motor], '>=')) {
        $informar('aviso', "Motor {$motor} {$numero}: aceptado solo en local (ADR-002). Producción exige MariaDB ≥ 10.6");
    } else {
        $informar('fallo', "Motor {$motor} {$numero}: se requiere MariaDB ≥ 10.6 o MySQL ≥ 8.0");
    }
    $soportaSkipLocked = $esMariaDb ? version_compare($numero, '10.6.0', '>=') : version_compare($numero, '8.0.1', '>=');
    $configurado = (bool) $config['soporta_skip_locked'];
    if ($configurado && !$soportaSkipLocked) {
        $informar('fallo', 'BD_SKIP_LOCKED=true pero el motor no soporta SKIP LOCKED: usa false y COLA_ESTRATEGIA_RECLAMO=actualizacion_condicional');
    } else {
        $informar('ok', 'SKIP LOCKED ' . ($soportaSkipLocked ? 'soportado' : 'no soportado') . '; BD_SKIP_LOCKED=' . ($configurado ? 'true' : 'false'));
    }
    $juego = (string) $bd->valor('SELECT @@character_set_connection');
    $informar($juego === 'utf8mb4' ? 'ok' : 'fallo', "Conexión en {$juego}");
    $zona = (string) $bd->valor('SELECT @@session.time_zone');
    $informar($zona === '+00:00' ? 'ok' : 'fallo', "Zona horaria de la sesión {$zona} (UTC)");
    $intercalacion = (string) $bd->valor('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()');
    $informar($intercalacion === 'utf8mb4_unicode_ci' ? 'ok' : 'aviso', "Intercalación de la base {$intercalacion}");
    $existeRegistro = (int) $bd->valor("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migraciones_aplicadas'") === 1;
    $aplicada = $existeRegistro ? $bd->valor('SELECT MAX(nombre) FROM migraciones_aplicadas') : null;
    $informar($aplicada === null ? 'aviso' : 'ok', 'Última migración: ' . ($aplicada ?? 'ninguna (ejecuta php cli/migrar.php --aplicar)'));
} catch (Throwable $error) {
    $informar('fallo', 'No fue posible conectar o consultar la base de datos (' . $error::class . ')');
}

escribirLinea('');
escribirLinea('Almacenamiento');
foreach (['cache', 'registros', 'estado', 'importaciones', 'exportaciones', 'adjuntos', 'latidos', 'bloqueos'] as $directorio) {
    $ruta = RAIZ . '/almacenamiento/' . $directorio;
    $informar(is_dir($ruta) && is_writable($ruta) ? 'ok' : 'fallo', "almacenamiento/{$directorio} con escritura");
}
$informar(is_file(RAIZ . '/public/assets/manifest.json') ? 'ok' : 'fallo', 'public/assets/manifest.json presente (npm run construir)');
$informar(!is_dir(RAIZ . '/public/node_modules') ? 'ok' : 'fallo', 'Sin node_modules dentro de public/ (RNF-019)');

escribirLinea('');
escribirLinea($fallos === 0 ? "Resultado: entorno apto ({$avisos} avisos)." : "Resultado: {$fallos} requisitos sin cumplir y {$avisos} avisos.");
exit($fallos === 0 ? 0 : 1);
