<?php

declare(strict_types=1);

/**
 * Plantilla «invitacion» (Anexo I; RAC-007, RF-009). Variables: nombre, rol, enlace (un solo uso, 72 h), requiere_2fa.
 *
 * @var array<string,mixed> $datos
 */

return [
    'asunto' => 'Te damos la bienvenida a KORA',
    'texto' => "Hola {$datos['nombre']}:\n\n"
        . "Se creó una cuenta para ti en KORA, el sistema de gestión productiva del Centro Agropecuario de Buga, con el rol {$datos['rol']}.\n\n"
        . "Activa tu cuenta y define tu contraseña en este enlace (válido por 72 horas y de un solo uso):\n{$datos['enlace']}\n\n"
        . (!empty($datos['requiere_2fa']) ? "Por el rol asignado deberás configurar la verificación en dos pasos con una aplicación de autenticación antes de ingresar.\n\n" : '')
        . "Si no esperabas este correo, ignóralo: sin activación la cuenta no puede usarse.\n\n"
        . '— KORA · SENA, Centro Agropecuario de Buga',
];
