<?php

declare(strict_types=1);

/**
 * Plantilla «segundo_factor» (Anexo I; RAC-011, RAC-014). Variables: nombre, fecha, evento, codigos_restantes.
 *
 * @var array<string,mixed> $datos
 */

$restantes = $datos['codigos_restantes'] ?? null;

return [
    'asunto' => 'Cambio en la verificación en dos pasos de tu cuenta',
    'texto' => "Hola {$datos['nombre']}:\n\n"
        . "El {$datos['fecha']} (hora de Colombia) se registró este cambio en la verificación en dos pasos de tu cuenta: {$datos['evento']}.\n\n"
        . ($restantes !== null ? "Te quedan {$restantes} códigos de respaldo; puedes generar nuevos en «Mi cuenta → Seguridad».\n\n" : '')
        . "Si no reconoces este cambio, contacta de inmediato a la coordinación del centro.\n\n"
        . '— KORA · SENA, Centro Agropecuario de Buga',
];
