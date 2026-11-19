<?php

declare(strict_types=1);

/**
 * Vacía la caché de aplicación (almacenamiento/cache): tras un despliegue (ROB-001 paso 11), una restauración o un
 * cambio directo en la base. La caché nunca es fuente de verdad, así que vaciarla solo cuesta la primera consulta en
 * frío; el trabajo cache.precalentar la vuelve a llenar (o: php cli/cola.php encolar cache.precalentar).
 *
 *   php cli/limpiar_cache.php
 */

require __DIR__ . '/arranque.php';

$directorio = RAIZ . '/almacenamiento/cache';
$borrados = 0;
foreach (glob($directorio . '/*', GLOB_ONLYDIR) ?: [] as $subdirectorio) {
    foreach (glob($subdirectorio . '/*') ?: [] as $archivo) {
        if (is_file($archivo) && @unlink($archivo)) {
            $borrados++;
        }
    }
    @rmdir($subdirectorio);
}
escribirLinea("Caché vaciada: {$borrados} archivos.");
exit(0);
