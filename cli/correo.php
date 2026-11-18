<?php

declare(strict_types=1);

/**
 * Prueba de la configuración del correo a través de la cola (RI-009):
 *
 *   php cli/correo.php prueba                 (al buzón de operaciones, CORREO_ALERTAS)
 *   php cli/correo.php prueba --usuario=ID    (a un usuario existente)
 *
 * Solo encola: el trabajador lo envía. En local (CORREO_TRANSPORTE=archivo) el mensaje queda en
 * almacenamiento/correos/*.eml.
 */

use App\Cola\DespachadorCola;

require __DIR__ . '/arranque.php';

$opciones = opcionesCli($argv);
if (($argv[1] ?? '') !== 'prueba') {
    fwrite(STDERR, "Uso: php cli/correo.php prueba [--usuario=ID]\n");
    exit(2);
}
$destinatario = isset($opciones['usuario']) ? (int) $opciones['usuario'] : 'operaciones';
$trabajo = contenedorCli()->obtener(DespachadorCola::class)->encolar(
    $destinatario === 'operaciones' ? 'correo.sistema' : 'correo.enviar',
    ['plantilla' => 'prueba', 'destinatario_id' => $destinatario],
);
escribirLinea("Correo de prueba encolado: trabajo {$trabajo['ulid']}. Ejecuta el trabajador para enviarlo.");
exit(0);
