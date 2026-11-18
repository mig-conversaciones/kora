<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Cola\DespachadorCola;
use App\Nucleo\BaseDatos;
use App\Nucleo\Registro;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Programador de tareas periódicas (ROB-010; tabla D.4 del Anexo D). cli/programador.php lo ejecuta cada minuto.
 * Cada tarea guarda su último periodo ejecutado en almacenamiento/estado/programador.json, de modo que una
 * ejecución repetida o atrasada no la duplica. Las tareas pesadas se encolan; las ligeras se hacen directo.
 *
 * Tareas:
 *   - Volcado de eventos pendientes de la CLI a la auditoría (cada minuto, RF-MAN-012).
 *   - Sellado de la auditoría en la cadena de hash (cada minuto, RAU-004, D-39). [I2]
 *   - Monitor de la cola con alertas silenciadas 30 min por condición (cada minuto, RF-Q-016).
 *   - Monitor de seguridad: patrones anómalos (cada 5 min, RAC-031). [I2]
 *   - Limpieza horaria y diaria a la 01:00 (trabajo mantenimiento.limpieza, RF-Q-015, RDL-010).
 *   - Verificación de la cadena a la 01:30 y exportación del lote del día anterior a las 02:00 (RAU-004, RAU-005). [I2]
 *   - Rotación del registro técnico: 30 días (diaria, RAU-008).
 * La verificación de integridad, el precalentamiento de la caché y el archivo se agregan en su iteración.
 */
final class Programador
{
    /** @param array<string,mixed> $configCola config/cola.php */
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly DespachadorCola $despachador,
        private readonly ServicioMonitorCola $monitor,
        private readonly ServicioAuditoria $auditoria,
        private readonly Registro $registro,
        private readonly array $configCola,
        private readonly string $directorioEstado,
        private readonly string $directorioRegistros,
        private readonly string $zonaHoraria,
        private readonly bool $hayBuzonOperaciones,
    ) {
    }

    /**
     * @return array<string,mixed> resumen de lo ejecutado (para el registro y las pruebas)
     */
    public function ejecutar(?DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new DateTimeImmutable('now', new DateTimeZone($this->zonaHoraria));
        $local = $ahora->setTimezone(new DateTimeZone($this->zonaHoraria));
        $estado = $this->leerJson('programador.json');
        $resumen = [];

        $this->monitor->registrarLatido(ServicioMonitorCola::LATIDO_PROGRAMADOR);
        $resumen['eventos_volcados'] = $this->auditoria->volcarPendientes($this->directorioEstado . '/eventos_pendientes.jsonl');
        $resumen['eventos_sellados'] = $this->auditoria->sellarPendientes();
        $resumen['alertas'] = $this->vigilarCola($ahora);
        $cincoMinutos = $local->format('Y-m-d H:') . str_pad((string) (intdiv((int) $local->format('i'), 5) * 5), 2, '0', STR_PAD_LEFT);
        if (($estado['monitor_seguridad'] ?? '') !== $cincoMinutos) {
            $resumen['alertas_seguridad'] = $this->vigilarSeguridad($ahora);
            $estado['monitor_seguridad'] = $cincoMinutos;
        }

        $hora = $local->format('Y-m-d H');
        if (($estado['limpieza_horaria'] ?? '') !== $hora) {
            $resumen['limpieza_horaria'] = $this->encolarLimpieza('horaria', $hora);
            $estado['limpieza_horaria'] = $hora;
        }
        $dia = $local->format('Y-m-d');
        if ((int) $local->format('G') >= 1 && ($estado['limpieza_diaria'] ?? '') !== $dia) {
            $resumen['limpieza_diaria'] = $this->encolarLimpieza('diaria', $dia);
            $resumen['registros_rotados'] = $this->rotarRegistros((int) $this->configCola['retencion']['registros_dias']);
            $estado['limpieza_diaria'] = $dia;
        }
        $minutoDelDia = (int) $local->format('G') * 60 + (int) $local->format('i');
        if ($minutoDelDia >= 90 && ($estado['verificar_cadena'] ?? '') !== $dia) {
            $resumen['verificar_cadena'] = $this->despachador->encolar('auditoria.verificar_cadena', ['fecha' => $dia])['ulid'];
            $estado['verificar_cadena'] = $dia;
        }
        if ($minutoDelDia >= 120 && ($estado['exportar_lote'] ?? '') !== $dia) {
            $ayerUtc = $ahora->setTimezone(new DateTimeZone('UTC'))->modify('-1 day')->format('Y-m-d');
            $resumen['exportar_lote'] = $this->despachador->encolar('auditoria.exportar_lote', ['fecha' => $ayerUtc])['ulid'];
            $estado['exportar_lote'] = $dia;
        }

        $this->escribirJson('programador.json', $estado);
        $this->registro->info('programador.ejecucion', $resumen);
        return $resumen;
    }

    /**
     * Condiciones de alerta de la cola (RF-Q-016). Cada una se notifica como máximo una vez cada 30 min.
     *
     * @return list<string> condiciones notificadas en esta ejecución
     */
    public function vigilarCola(DateTimeImmutable $ahora): array
    {
        $umbrales = $this->configCola['alertas'];
        $estadisticas = $this->monitor->estadisticas();
        $condiciones = [];
        if ($estadisticas['pendiente_mas_antiguo_segundos'] > (int) $umbrales['pendiente_max_segundos']) {
            $condiciones['cola_atascada'] = "El trabajo pendiente más antiguo espera {$estadisticas['pendiente_mas_antiguo_segundos']} s.";
        }
        if ($estadisticas['fallidos_ultima_hora'] > (int) $umbrales['fallidos_por_hora']) {
            $condiciones['fallidos_excesivos'] = "{$estadisticas['fallidos_ultima_hora']} trabajos fallaron en la última hora.";
        }
        if ($estadisticas['latido_trabajador_segundos'] > (int) $umbrales['latido_max_segundos']) {
            $edad = $estadisticas['latido_trabajador_segundos'] === PHP_INT_MAX ? 'nunca' : "hace {$estadisticas['latido_trabajador_segundos']} s";
            $condiciones['trabajador_sin_latido'] = "El último latido del trabajador fue {$edad}. Revisa el cron.";
        }
        $silencio = $this->leerJson('alertas.json');
        $notificadas = [];
        foreach ($condiciones as $condicion => $detalle) {
            $ultima = (int) ($silencio[$condicion] ?? 0);
            if ($ahora->getTimestamp() - $ultima < (int) $umbrales['silencio_minutos'] * 60) {
                continue;
            }
            $this->notificar($condicion, $detalle);
            $silencio[$condicion] = $ahora->getTimestamp();
            $notificadas[] = $condicion;
        }
        $this->escribirJson('alertas.json', $silencio);
        return $notificadas;
    }

    /**
     * Patrones de seguridad anómalos en los últimos 5 minutos (RAC-031), con el mismo silencio de 30 min:
     *   - Muchas cuentas distintas con fallos de ingreso desde una misma IP (relleno de credenciales).
     *   - Fallos de OTP repetidos.
     *   - Restablecimientos de la 2FA por un administrador.
     *   - Ingresos de cuentas privilegiadas a horas inusuales (22:00 a 05:00, hora de Colombia).
     *
     * @return list<string>
     */
    public function vigilarSeguridad(DateTimeImmutable $ahora): array
    {
        $desde = $ahora->setTimezone(new DateTimeZone('UTC'))->modify('-5 minutes')->format('Y-m-d H:i:s');
        $condiciones = [];
        $ipAtacante = $this->bd->valor(
            "SELECT COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(metadatos, '$.correo_hmac'))) AS cuentas FROM eventos_auditoria
              WHERE accion = 'sesion.ingresar' AND resultado = 'denegado' AND ocurrido_en >= :desde AND ip_hmac IS NOT NULL
              GROUP BY ip_hmac ORDER BY cuentas DESC LIMIT 1",
            ['desde' => $desde]
        );
        if ((int) $ipAtacante >= 5) {
            $condiciones['cuentas_atacadas_desde_una_ip'] = "{$ipAtacante} cuentas distintas fallaron el ingreso desde una misma red en 5 minutos.";
        }
        $fallosOtp = (int) $this->bd->valor(
            "SELECT COUNT(*) FROM eventos_auditoria WHERE accion = 'sesion.2fa' AND resultado = 'denegado' AND ocurrido_en >= :desde",
            ['desde' => $desde]
        );
        if ($fallosOtp >= 10) {
            $condiciones['fallos_otp_repetidos'] = "{$fallosOtp} códigos de verificación fallidos en 5 minutos.";
        }
        $restablecimientos = (int) $this->bd->valor(
            "SELECT COUNT(*) FROM eventos_auditoria WHERE accion = '2fa.restablecer' AND ocurrido_en >= :desde",
            ['desde' => $desde]
        );
        if ($restablecimientos > 0) {
            $condiciones['restablecimiento_2fa'] = "Se restableció la verificación en dos pasos de {$restablecimientos} cuenta(s).";
        }
        $hora = (int) $ahora->setTimezone(new DateTimeZone('America/Bogota'))->format('G');
        if ($hora >= 22 || $hora < 5) {
            $privilegiados = (int) $this->bd->valor(
                "SELECT COUNT(*) FROM eventos_auditoria
                  WHERE accion IN ('sesion.2fa', 'sesion.ingresar') AND resultado = 'exito' AND ocurrido_en >= :desde
                    AND actor_rol IN ('superadministrador', 'administrador')",
                ['desde' => $desde]
            );
            if ($privilegiados > 0) {
                $condiciones['ingreso_privilegiado_hora_inusual'] = 'Una cuenta privilegiada ingresó fuera del horario habitual.';
            }
        }
        $silencio = $this->leerJson('alertas.json');
        $notificadas = [];
        foreach ($condiciones as $condicion => $detalle) {
            if ($ahora->getTimestamp() - (int) ($silencio[$condicion] ?? 0) < (int) $this->configCola['alertas']['silencio_minutos'] * 60) {
                continue;
            }
            $this->notificar($condicion, $detalle);
            $silencio[$condicion] = $ahora->getTimestamp();
            $notificadas[] = $condicion;
        }
        $this->escribirJson('alertas.json', $silencio);
        return $notificadas;
    }

    /**
     * Notificación de una alerta: registro técnico siempre, notificación en la aplicación a los
     * superadministradores y correo al buzón de operaciones si está configurado.
     */
    private function notificar(string $condicion, string $detalle): void
    {
        $this->registro->error('alerta.' . $condicion, ['detalle' => $detalle]);
        try {
            $this->bd->ejecutar(
                "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje)
                 SELECT DISTINCT ur.usuario_id, 'alerta_operativa', :titulo, :mensaje
                   FROM usuarios_roles ur JOIN roles r ON r.id = ur.rol_id JOIN usuarios u ON u.id = ur.usuario_id
                  WHERE r.codigo = 'superadministrador' AND u.estado = 'activo'",
                ['titulo' => 'Alerta operativa: ' . str_replace('_', ' ', $condicion), 'mensaje' => mb_substr($detalle, 0, 500)]
            );
            if ($this->hayBuzonOperaciones) {
                $this->despachador->encolar('correo.sistema', [
                    'plantilla' => 'alerta_operativa',
                    'destinatario_id' => 'operaciones',
                    'datos' => ['condicion' => str_replace('_', ' ', $condicion), 'detalle' => $detalle],
                ]);
            }
        } catch (Throwable $error) {
            $this->registro->error('alerta.notificacion_fallida', ['condicion' => $condicion, 'clase' => $error::class]);
        }
    }

    /** @return array{trabajo:string,duplicado:bool} */
    private function encolarLimpieza(string $alcance, string $periodo): array
    {
        $trabajo = $this->despachador->encolar('mantenimiento.limpieza', ['alcance' => $alcance, 'periodo' => $periodo]);
        return ['trabajo' => $trabajo['ulid'], 'duplicado' => $trabajo['duplicado']];
    }

    private function rotarRegistros(int $dias): int
    {
        $limite = time() - $dias * 86400;
        $borrados = 0;
        foreach (glob($this->directorioRegistros . '/*.log') ?: [] as $archivo) {
            if (filemtime($archivo) < $limite && @unlink($archivo)) {
                $borrados++;
            }
        }
        return $borrados;
    }

    /** @return array<string,mixed> */
    private function leerJson(string $nombre): array
    {
        $ruta = $this->directorioEstado . '/' . $nombre;
        $datos = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;
        return is_array($datos) ? $datos : [];
    }

    /** @param array<string,mixed> $datos */
    private function escribirJson(string $nombre, array $datos): void
    {
        $ruta = $this->directorioEstado . '/' . $nombre;
        $temporal = $ruta . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($temporal, json_encode($datos, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
        if (!@rename($temporal, $ruta)) {
            @unlink($temporal);
        }
    }
}
