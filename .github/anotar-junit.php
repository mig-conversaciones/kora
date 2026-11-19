<?php

declare(strict_types=1);

/**
 * Convierte los fallos de un informe JUnit de PHPUnit en anotaciones de GitHub Actions (::error), para que el motivo
 * de una prueba fallida quede visible en el resumen del trabajo y en la API pública de check-runs, sin descargar el
 * registro completo. Solo se ejecuta en la CI, cuando falla el paso de pruebas.
 *
 *   php .github/anotar-junit.php junit.xml
 */

$archivo = $argv[1] ?? 'junit.xml';
if (!is_file($archivo)) {
    fwrite(STDERR, "No existe el informe {$archivo}.\n");
    exit(0);
}
$xml = simplexml_load_file($archivo);
if ($xml === false) {
    exit(0);
}
$escapar = static fn (string $texto): string => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $texto);
// En las propiedades (file, title) también se escapan «:» y «,», que separan la anotación.
$propiedad = static fn (string $texto): string => str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $texto);
$total = 0;
foreach ($xml->xpath('//testcase[failure or error]') ?: [] as $caso) {
    $nodo = $caso->failure ?? $caso->error;
    $detalle = trim((string) ($nodo[0] ?? ''));
    $titulo = (string) $caso['class'] . '::' . (string) $caso['name'];
    $ruta = (string) ($caso['file'] ?? '');
    $linea = (int) ($caso['line'] ?? 0);
    // El detalle trae el mensaje y la traza; las primeras líneas bastan para diagnosticar.
    $mensaje = implode("\n", array_slice(explode("\n", $detalle), 0, 12));
    $ubicacion = $ruta !== '' ? 'file=' . $propiedad(str_replace(getcwd() . '/', '', $ruta)) . ($linea > 0 ? ",line={$linea}" : '') . ',' : '';
    echo '::error ' . $ubicacion . 'title=' . $propiedad($titulo) . '::' . $escapar(mb_substr($mensaje, 0, 3000)) . "\n";
    if (++$total >= 20) {
        break;
    }
}
echo "{$total} pruebas con fallo anotadas.\n";
