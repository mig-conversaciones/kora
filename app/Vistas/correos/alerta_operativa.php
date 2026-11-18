<?php

declare(strict_types=1);

/**
 * Plantilla «alerta_operativa» (Anexo I; RF-Q-016, RS-022, RAC-031). Devuelve asunto y texto plano; el servicio
 * arma la versión HTML. Sin datos sensibles. Variables: $datos['condicion'], ['detalle'], ['url_aplicacion'].
 *
 * @var array<string,mixed> $datos
 */

$condicion = (string) ($datos['condicion'] ?? 'Condición operativa');
$detalle = (string) ($datos['detalle'] ?? '');

return [
    'asunto' => "Alerta de KORA: {$condicion}",
    'texto' => "Hola:\n\n"
        . "KORA detectó una condición que requiere revisión: {$condicion}.\n\n"
        . ($detalle !== '' ? "Detalle: {$detalle}\n\n" : '')
        . "Revisa el estado en {$datos['url_aplicacion']}/health/ready (con el token de monitoreo) y el registro técnico del servidor.\n\n"
        . "Esta alerta no se repetirá durante los próximos 30 minutos para la misma condición.\n\n"
        . '— KORA · SENA, Centro Agropecuario de Buga',
];
