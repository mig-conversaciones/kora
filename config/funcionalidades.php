<?php

declare(strict_types=1);

/**
 * Funcionalidades configurables (feature flags, ROB-007). Valores por defecto restrictivos (PoD, RS-023).
 * Cada interruptor documenta su propósito, responsable y fecha prevista de retiro para no acumular deuda.
 * El .env puede sobrescribir cualquier valor con FUNCIONALIDAD_<CLAVE_EN_MAYUSCULAS>=true|false.
 * Una ruta asociada a una funcionalidad inactiva responde 404 (intermediario Autorizar).
 */

return [
    'recaudos_alta_manual' => [
        'activa' => false,
        'descripcion' => 'Alta manual excepcional de recaudos por el Grupo de Recaudo con motivo y soporte (RF-033).',
        'responsable' => 'Coordinación administrativa',
        'creada' => '2026-11-17',
        'retiro_previsto' => null,
    ],
    'hibp_habilitado' => [
        'activa' => false,
        'descripcion' => 'Consulta k-anonimato de contraseñas filtradas (RAC-004); requiere evaluación de privacidad.',
        'responsable' => 'Líder técnico',
        'creada' => '2026-11-17',
        'retiro_previsto' => null,
    ],
    'captcha_tras_umbral' => [
        'activa' => false,
        'descripcion' => 'Desafío adicional tras superar el umbral de fallos (RAC-030); evaluar accesibilidad WCAG 3.3.8.',
        'responsable' => 'Líder técnico',
        'creada' => '2026-11-17',
        'retiro_previsto' => null,
    ],
    'vista_soporte' => [
        'activa' => false,
        'descripcion' => 'Vista de soporte de solo lectura como otro usuario (RF-013).',
        'responsable' => 'Superadministrador',
        'creada' => '2026-11-17',
        'retiro_previsto' => null,
    ],
    'tablero_otras_unidades' => [
        'activa' => false,
        'descripcion' => 'Tableros técnicos de unidades distintas a Ganadería (RF-079, versión futura).',
        'responsable' => 'Líder de producción de centro',
        'creada' => '2026-11-17',
        'retiro_previsto' => '2027-12-31',
    ],
    'exportacion_pdf' => [
        'activa' => true,
        'descripcion' => 'Exportación de tableros a PDF mediante trabajos (RF-081).',
        'responsable' => 'Líder técnico',
        'creada' => '2026-11-17',
        'retiro_previsto' => null,
    ],
    'sugerencias_conciliacion' => [
        'activa' => true,
        'descripcion' => 'Sugerencias automáticas de clasificación y conciliación de recaudos (RF-031, RF-032).',
        'responsable' => 'Grupo de Recaudo',
        'creada' => '2026-11-17',
        'retiro_previsto' => null,
    ],
    'transiciones_vista' => [
        'activa' => true,
        'descripcion' => 'View Transitions en navegaciones HTMX (RUI-019); se desactiva si genera problemas.',
        'responsable' => 'UX/UI',
        'creada' => '2026-11-17',
        'retiro_previsto' => null,
    ],
];
