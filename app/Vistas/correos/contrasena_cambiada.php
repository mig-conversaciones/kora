<?php

declare(strict_types=1);

/**
 * Plantilla «contrasena_cambiada» (Anexo I; RAC-006, RAC-008). Variables: nombre, fecha, enlace_recuperar.
 *
 * @var array<string,mixed> $datos
 */

return [
    'asunto' => 'Tu contraseña de KORA cambió',
    'texto' => "Hola {$datos['nombre']}:\n\n"
        . "La contraseña de tu cuenta se cambió el {$datos['fecha']} (hora de Colombia).\n\n"
        . "Si fuiste tú, no necesitas hacer nada.\n\n"
        . "Si no reconoces este cambio, restablece tu contraseña de inmediato desde {$datos['enlace_recuperar']} y avisa a la coordinación del centro.\n\n"
        . '— KORA · SENA, Centro Agropecuario de Buga',
];
