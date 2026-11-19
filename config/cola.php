<?php

declare(strict_types=1);

/**
 * Configuración de la cola (RF-Q-005, RF-Q-019; 04-04a). Los valores de capacidad se calibran con las pruebas de
 * carga (RNF-PERF-004) y los límites reales del plan Hostinger Cloud.
 *
 * Tipos registrados: correo, limpieza y demostración (I1); verificación y exportación de la auditoría (I2);
 * recálculo de la bandeja de calidad (I4). Los demás tipos de la v1 (importación, exportación, caché, seguridad,
 * capacitación) se registran en la iteración que entrega su manejador; un tipo no registrado nunca se encola
 * (ErrorDefinitivo).
 */

use App\Nucleo\Entorno;
use App\Trabajos\DemostracionProgreso;
use App\Trabajos\EnviarCorreo;
use App\Trabajos\ExportarLoteAuditoria;
use App\Trabajos\LimpiezaPeriodica;
use App\Trabajos\RecalcularCalidad;
use App\Trabajos\VerificarCadena;

$tipo = static fn (string $manejador, string $cola, int $intentos, int $reserva, bool $tecnico = false, int $prioridad = 5): array => [
    'manejador' => $manejador,
    'cola' => $cola,
    'max_intentos' => $intentos,
    'reserva_segundos' => $reserva,
    'tecnico' => $tecnico,
    'prioridad' => $prioridad,
];

return [
    // Hostinger Cloud: único conductor admitido (sin Redis, RF-Q-018).
    'conductor' => Entorno::texto('COLA_CONDUCTOR', 'mysql'),
    // 'skip_locked' (MariaDB 10.6+, MySQL 8.0+) o 'actualizacion_condicional' (MySQL 5.7 de MAMP, ADR-002).
    'estrategia_reclamo' => Entorno::texto('COLA_ESTRATEGIA_RECLAMO', 'skip_locked'),
    'lote' => Entorno::entero('COLA_LOTE', 10),
    'lote_reanudacion' => 3,
    'presupuesto_segundos' => Entorno::entero('COLA_PRESUPUESTO_SEGUNDOS', 50),
    'memoria_max_mb' => Entorno::entero('COLA_MEMORIA_MAX_MB', 192),
    'max_trabajadores' => Entorno::entero('COLA_MAX_TRABAJADORES', 2),
    'reserva_segundos' => 120,
    'max_intentos' => 5,
    'espera' => ['base_segundos' => 30, 'maximo_segundos' => 1800, 'variacion' => 0.30],
    'retencion' => ['completados_dias' => 14, 'fallidos_dias' => 90, 'registros_dias' => 30, 'correos_archivo_dias' => 7],
    'alertas' => [
        'pendiente_max_segundos' => 300,
        'fallidos_por_hora' => 5,
        'latido_max_segundos' => 180,
        'silencio_minutos' => 30,
    ],
    'consulta_periodica_segundos' => [2, 3, 5, 8, 13, 15],
    'carga_max_bytes' => 65535,
    'tipos' => [
        'correo.enviar' => $tipo(EnviarCorreo::class, 'correo', 6, 60, false, 3),
        'correo.sistema' => $tipo(EnviarCorreo::class, 'correo', 6, 60, true, 2),
        'auditoria.verificar_cadena' => $tipo(VerificarCadena::class, 'predeterminada', 3, 300, true, 7),
        'auditoria.exportar_lote' => $tipo(ExportarLoteAuditoria::class, 'predeterminada', 5, 300, true, 7),
        'mantenimiento.limpieza' => $tipo(LimpiezaPeriodica::class, 'predeterminada', 3, 300, true, 9),
        // I4: bandeja de calidad (RF-058). Se encola tras cada importación y desde la bandeja.
        'calidad.recalcular' => $tipo(RecalcularCalidad::class, 'predeterminada', 3, 300, false, 6),
        // Solo fuera de producción: ejercita progreso, latidos y bloques desde /sistema-diseno.
        'demostracion.progreso' => $tipo(DemostracionProgreso::class, 'predeterminada', 3, 60, false, 5),
    ],
];
