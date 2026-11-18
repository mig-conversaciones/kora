<?php

declare(strict_types=1);

namespace App\Cola;

use App\Nucleo\Contenedor;
use App\Nucleo\Registro;
use App\Servicios\ServicioMantenimiento;
use Throwable;

/**
 * Ciclo del trabajador de la cola (RF-Q-005 a RF-Q-011, RF-Q-017; base: cli/trabajador_cron.php del 04-04b):
 * recuperar reservas vencidas → reclamar un lote de forma atómica → ejecutar fuera de la transacción → marcar
 * el resultado verificando la reserva. Se detiene por presupuesto de tiempo, memoria o falta de trabajo; en
 * mantenimiento solo ejecuta los tipos técnicos.
 *
 * Es una clase (y no solo el script de cron) para poder probarla y ejecutarla con presupuestos cortos.
 */
final class Trabajador
{
    /** @param array<string,mixed> $config config/cola.php */
    public function __construct(
        private readonly ConductorCola $conductor,
        private readonly Contenedor $contenedor,
        private readonly Registro $registro,
        private readonly ServicioMantenimiento $mantenimiento,
        private readonly array $config,
    ) {
    }

    /**
     * @param string|null $cola null = todas las colas registradas
     * @param float|null $presupuestoSegundos null = el de la configuración
     * @return array{procesados:int,completados:int,continuados:int,reintentos:int,fallidos:int,recuperados:int,liberados:int,duracion_ms:int}
     */
    public function ejecutar(?string $cola = null, ?float $presupuestoSegundos = null): array
    {
        $inicio = microtime(true);
        $limite = $inicio + ($presupuestoSegundos ?? (float) $this->config['presupuesto_segundos']);
        $margen = min(5.0, ($limite - $inicio) / 10);
        /** @var array<string,array{manejador:class-string,cola:string,max_intentos:int,reserva_segundos:int,tecnico:bool,prioridad:int}> $tipos */
        $tipos = $this->config['tipos'];
        $colas = $cola === null ? array_values(array_unique(array_column($tipos, 'cola'))) : [$cola];
        $estadisticas = ['procesados' => 0, 'completados' => 0, 'continuados' => 0, 'reintentos' => 0, 'fallidos' => 0, 'recuperados' => 0, 'liberados' => 0, 'duracion_ms' => 0];
        $estadisticas['recuperados'] = $this->conductor->recuperarVencidos();

        while (microtime(true) < $limite - $margen && memory_get_usage(true) < (int) $this->config['memoria_max_mb'] * 1048576) {
            // La señal de mantenimiento se relee en cada vuelta: un cambio se respeta en el siguiente lote.
            $tiposPermitidos = $this->mantenimiento->activo()
                ? array_keys(array_filter($tipos, static fn (array $definicion): bool => $definicion['tecnico'] === true))
                : null;
            $lote = $this->mantenimiento->enReanudacion() ? (int) $this->config['lote_reanudacion'] : (int) $this->config['lote'];
            $reclamados = [];
            foreach ($colas as $nombreCola) {
                $token = bin2hex(random_bytes(16));
                foreach ($this->conductor->reclamar($nombreCola, $lote, $token, (int) $this->config['reserva_segundos'], $tiposPermitidos) as $fila) {
                    $reclamados[] = [$fila, $token];
                }
            }
            if ($reclamados === []) {
                break;
            }
            foreach ($reclamados as $indice => [$trabajo, $token]) {
                $resultado = $this->procesar($trabajo, $token, $tipos, $limite);
                $estadisticas['procesados']++;
                $estadisticas[$resultado]++;
                // Un trabajo que pide continuar agotó el presupuesto de esta ejecución: se detiene aquí para no
                // volver a reclamarlo de inmediato; la siguiente ejecución del cron lo retoma desde su punto de control.
                if ($resultado === 'continuados' || microtime(true) >= $limite - $margen) {
                    $estadisticas['liberados'] = $this->liberar(array_slice($reclamados, $indice + 1));
                    break 2;
                }
            }
        }
        $estadisticas['duracion_ms'] = (int) ((microtime(true) - $inicio) * 1000);
        $this->registro->info('cola.ejecucion', $estadisticas);
        return $estadisticas;
    }

    /**
     * Devuelve a pendiente, sin consumir intento, los trabajos reclamados que esta ejecución no alcanzó a procesar;
     * sin esto quedarían reservados hasta vencer su reserva (120 s) y gastarían un intento.
     *
     * @param list<array{0:array<string,mixed>,1:string}> $pendientes
     */
    private function liberar(array $pendientes): int
    {
        $liberados = 0;
        foreach ($pendientes as [$trabajo, $token]) {
            $liberados += $this->conductor->continuar((int) $trabajo['id'], $token) ? 1 : 0;
        }
        return $liberados;
    }

    /**
     * Espera exponencial con variación aleatoria para evitar reintentos sincronizados (RF-Q-009).
     *
     * @param array{base_segundos:int,maximo_segundos:int,variacion:float} $espera
     */
    public static function calcularEspera(int $intento, array $espera): int
    {
        $base = min((int) $espera['maximo_segundos'], (int) $espera['base_segundos'] * (2 ** max(0, $intento - 1)));
        return (int) round($base * (1 + (mt_rand() / mt_getrandmax()) * (float) $espera['variacion']));
    }

    /**
     * @param array<string,mixed> $trabajo
     * @param array<string,array{manejador:class-string,cola:string,max_intentos:int,reserva_segundos:int,tecnico:bool,prioridad:int}> $tipos
     * @return 'completados'|'continuados'|'reintentos'|'fallidos'
     */
    private function procesar(array $trabajo, string $token, array $tipos, float $limite): string
    {
        $id = (int) $trabajo['id'];
        $intentos = (int) $trabajo['intentos'];
        $agotado = $intentos >= (int) $trabajo['max_intentos'];
        $definicion = $tipos[(string) $trabajo['tipo']] ?? null;
        $inicio = microtime(true);
        $resultado = 'completados';
        try {
            if ($definicion === null) {
                throw new ErrorDefinitivo('Tipo de trabajo no registrado.');
            }
            $trabajo['carga'] = json_decode((string) $trabajo['carga'], true, 32, JSON_THROW_ON_ERROR);
            $contexto = new ContextoTrabajo($this->conductor, $id, $token, $limite, $definicion['reserva_segundos']);
            /** @var ManejadorTrabajo $manejador */
            $manejador = $this->contenedor->obtener($definicion['manejador']);
            // Fuera de la transacción de reclamo: ningún bloqueo de la base se mantiene durante llamadas externas.
            $salida = $manejador->ejecutar($trabajo, $contexto);
            if ($contexto->debeContinuar()) {
                $this->conductor->continuar($id, $token);
                $resultado = 'continuados';
            } elseif (!$this->conductor->completar($id, $token, $salida)) {
                $this->registro->aviso('cola.reserva_perdida', ['trabajo' => $trabajo['ulid'], 'momento' => 'completar']);
            }
        } catch (ReservaPerdida) {
            $this->registro->aviso('cola.reserva_perdida', ['trabajo' => $trabajo['ulid'], 'momento' => 'latido']);
            $resultado = 'reintentos';
        } catch (ErrorDefinitivo $error) {
            $this->conductor->fallar($id, $token, $error->getMessage(), 'definitivo');
            $resultado = 'fallidos';
        } catch (ErrorTransitorio $error) {
            if ($agotado) {
                $this->conductor->fallar($id, $token, $error->getMessage(), 'transitorio_agotado');
                $resultado = 'fallidos';
            } else {
                $this->conductor->reprogramar($id, $token, $error->retardoSugerido ?? self::calcularEspera($intentos, $this->config['espera']), $error->getMessage());
                $resultado = 'reintentos';
            }
        } catch (Throwable $error) {
            // Error no clasificado: transitorio con mensaje sanitizado; el detalle va al registro técnico con la
            // correlación (RS-010, RAU-009).
            $mensaje = 'Error inesperado al procesar el trabajo. Código de soporte: ' . ($trabajo['correlacion_id'] ?? $trabajo['ulid']);
            if ($agotado) {
                $this->conductor->fallar($id, $token, $mensaje, 'transitorio_agotado');
                $resultado = 'fallidos';
            } else {
                $this->conductor->reprogramar($id, $token, self::calcularEspera($intentos, $this->config['espera']), $mensaje);
                $resultado = 'reintentos';
            }
            $this->registro->error('cola.error_inesperado', [
                'trabajo' => $trabajo['ulid'],
                'correlacion' => $trabajo['correlacion_id'],
                'clase' => $error::class,
                'mensaje' => mb_substr($error->getMessage(), 0, 300),
            ]);
        }
        $this->registro->info('cola.trabajo', [
            'trabajo' => $trabajo['ulid'],
            'tipo' => $trabajo['tipo'],
            'intento' => $intentos,
            'resultado' => $resultado,
            'duracion_ms' => (int) ((microtime(true) - $inicio) * 1000),
        ]);
        return $resultado;
    }
}
