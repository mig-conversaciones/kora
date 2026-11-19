<?php

declare(strict_types=1);

/**
 * Genera docs/seguridad/asvs.md (RS-001, RGO-009) a partir del texto oficial de ASVS 5.0.0 y del estado de cada
 * requisito (docs/seguridad/asvs-estado.csv). Con --verificar no escribe: termina con 1 si el archivo no coincide.
 *
 *   php herramientas/generar-asvs.php [--verificar]
 */

$raiz = dirname(__DIR__);
require_once $raiz . '/herramientas/ListaAsvs.php';

$lista = new ListaAsvs($raiz);
$errores = $lista->errores();
if ($errores !== []) {
    fwrite(STDERR, "La lista ASVS está incompleta:\n  - " . implode("\n  - ", $errores) . "\n");
    exit(1);
}
$contenido = $lista->markdown();
$destino = $raiz . '/docs/seguridad/asvs.md';
if (in_array('--verificar', $argv, true)) {
    $actual = is_file($destino) ? (string) file_get_contents($destino) : '';
    if (str_replace("\r\n", "\n", $actual) !== $contenido) {
        fwrite(STDERR, "docs/seguridad/asvs.md no coincide con su fuente: ejecuta php herramientas/generar-asvs.php\n");
        exit(1);
    }
    fwrite(STDOUT, "docs/seguridad/asvs.md al día.\n");
    exit(0);
}
file_put_contents($destino, $contenido);
fwrite(STDOUT, 'Escrito docs/seguridad/asvs.md: ' . $lista->resumenTexto() . "\n");
