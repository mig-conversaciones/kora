<?php

declare(strict_types=1);

/**
 * Plantilla «prueba»: verifica la configuración de correo (cli/correo.php). Sin datos sensibles.
 *
 * @var array<string,mixed> $datos
 */

return [
    'asunto' => 'Prueba de correo de KORA',
    'texto' => "Hola:\n\n"
        . "Este es un mensaje de prueba enviado por la cola de KORA para verificar la configuración del correo.\n\n"
        . 'Enviado el ' . gmdate('Y-m-d H:i') . " UTC desde {$datos['url_aplicacion']}.\n\n"
        . "Si no esperabas este correo, puedes ignorarlo.\n\n"
        . '— KORA · SENA, Centro Agropecuario de Buga',
];
