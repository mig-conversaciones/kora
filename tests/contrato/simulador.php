<?php

declare(strict_types=1);

/**
 * Simulador del receptor de KORA para el contrato de integración con el software de ventas (RF-095, anexo E). Aplica
 * el contrato de docs/api/integracion-ventas.yaml sin tocar ninguna base:
 *   - firma HMAC con X-Clave-Id, X-Fecha y X-Firma (App\Integracion\FirmaIntegracion);
 *   - clave de idempotencia: el mismo contenido devuelve el mismo lote y otro contenido con la misma clave, 409;
 *   - validación del lote (App\Integracion\ValidadorLoteVentas) con errores RFC 9457;
 *   - 503 con Retry-After si existe el archivo «mantenimiento» en el directorio de datos.
 * Sirve para las pruebas de contrato (Schemathesis) y para que el equipo del software de ventas pruebe su cliente.
 *
 *   SIMULADOR_CLAVES="cliente:SECRETO_DE_32_O_MAS" SIMULADOR_DATOS=/tmp/simulador \
 *     php -S 127.0.0.1:8090 tests/contrato/simulador.php
 */

use App\Integracion\FirmaIntegracion;
use App\Integracion\ValidadorLoteVentas;
use App\Nucleo\GeneradorUlid;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const PREFIJO = '/api/v1/integracion/ventas/lotes';

$datos = getenv('SIMULADOR_DATOS') ?: sys_get_temp_dir() . '/kora-simulador';
if (!is_dir($datos)) {
    mkdir($datos, 0700, true);
}

/**
 * @param array<string,mixed> $cuerpo
 * @param array<string,string> $cabeceras
 */
function responder(int $estado, array $cuerpo, string $tipo = 'application/json', array $cabeceras = []): void
{
    http_response_code($estado);
    header('Content-Type: ' . $tipo . '; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($cabeceras as $nombre => $valor) {
        header("{$nombre}: {$valor}");
    }
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/**
 * @param list<array<string,mixed>> $errores
 * @param array<string,string> $cabeceras
 */
function problema(int $estado, string $titulo, string $detalle, array $errores = [], array $cabeceras = []): void
{
    $cuerpo = ['type' => 'about:blank', 'title' => $titulo, 'status' => $estado, 'detail' => $detalle];
    if ($errores !== []) {
        $cuerpo['errores'] = $errores;
    }
    responder($estado, $cuerpo, 'application/problem+json', $cabeceras);
}

$metodo = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$cuerpo = (string) file_get_contents('php://input');
$cabecera = static fn (string $nombre): ?string => isset($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $nombre))]) ? (string) $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $nombre))] : null;

$coincidencia = [];
if ($ruta !== PREFIJO && preg_match('#^' . PREFIJO . '/([^/]+)$#', $ruta, $coincidencia) !== 1) {
    problema(404, 'No encontrado', 'La ruta no existe en el contrato.');
    return;
}
$esLote = $ruta !== PREFIJO;
if (($esLote && $metodo !== 'GET') || (!$esLote && $metodo !== 'POST')) {
    problema(405, 'Método no permitido', 'El contrato no admite este método en esta ruta.', [], ['Allow' => $esLote ? 'GET' : 'POST']);
    return;
}
if (is_file($datos . '/mantenimiento')) {
    problema(503, 'En mantenimiento', 'KORA está en mantenimiento: el lote no se recibió. Reinténtalo más tarde.', [], ['Retry-After' => '120']);
    return;
}
$firma = FirmaIntegracion::desdeTexto((string) getenv('SIMULADOR_CLAVES'));
$rechazo = $firma->verificar($metodo, $ruta, $cabecera('X-Clave-Id'), $cabecera('X-Fecha'), $cabecera('X-Firma'), $cuerpo);
if ($rechazo !== null) {
    problema(401, 'No autenticado', $rechazo);
    return;
}

if ($esLote) {
    $ulid = $coincidencia[1];
    $archivo = $datos . '/lote-' . $ulid . '.json';
    if (!GeneradorUlid::esValido($ulid) || !is_file($archivo)) {
        problema(404, 'Lote no encontrado', 'No hay un lote con ese identificador.');
        return;
    }
    responder(200, json_decode((string) file_get_contents($archivo), true, 8, JSON_THROW_ON_ERROR));
    return;
}

$clave = $cabecera('Idempotency-Key');
if ($clave === null || preg_match('/^[A-Za-z0-9_-]{16,120}$/', $clave) !== 1) {
    problema(400, 'Falta la clave de idempotencia', 'Idempotency-Key es obligatoria: de 16 a 120 letras, dígitos, «-» o «_».');
    return;
}
if (!str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    problema(400, 'Tipo de contenido no admitido', 'El cuerpo debe ser application/json.');
    return;
}
try {
    $lote = json_decode($cuerpo, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    problema(400, 'JSON no válido', 'El cuerpo no es un JSON válido.');
    return;
}
$errores = (new ValidadorLoteVentas())->validar($lote);
if ($errores !== []) {
    problema(422, 'El lote no cumple el contrato', count($errores) . ' error(es) de validación.', $errores);
    return;
}

// Idempotencia con bloqueo: dos envíos simultáneos con la misma clave no crean dos lotes.
$candado = fopen($datos . '/idempotencia.lock', 'c');
flock($candado, LOCK_EX);
try {
    $registro = $datos . '/clave-' . hash('sha256', $clave) . '.json';
    $huella = hash('sha256', $cuerpo);
    if (is_file($registro)) {
        $previo = json_decode((string) file_get_contents($registro), true, 4, JSON_THROW_ON_ERROR);
        if ($previo['huella'] !== $huella) {
            problema(409, 'Clave de idempotencia reutilizada', 'Esta clave ya se usó con otro contenido.');
            return;
        }
        $ulid = (string) $previo['ulid'];
    } else {
        $ulid = GeneradorUlid::generar();
        file_put_contents($datos . '/lote-' . $ulid . '.json', json_encode(['ulid' => $ulid, 'estado' => 'validado', 'filas_validas' => count($lote['recepciones']) + count($lote['salidas']), 'filas_error' => 0], JSON_THROW_ON_ERROR));
        file_put_contents($registro, json_encode(['huella' => $huella, 'ulid' => $ulid], JSON_THROW_ON_ERROR));
    }
} finally {
    flock($candado, LOCK_UN);
    fclose($candado);
}
responder(202, ['ulid' => $ulid, 'estado' => 'validando', 'url_estado' => PREFIJO . '/' . $ulid], 'application/json', ['Location' => PREFIJO . '/' . $ulid]);
