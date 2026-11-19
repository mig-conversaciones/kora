<?php

declare(strict_types=1);

namespace App\Cola;

use App\Nucleo\BaseDatos;
use App\Nucleo\GeneradorUlid;
use PDOException;

/**
 * Cola durable sobre MariaDB/MySQL (RF-Q-001, RF-Q-006 a RF-Q-008). El reclamo nunca hace
 * «SELECT pendiente → UPDATE» sin protección: usa FOR UPDATE SKIP LOCKED dentro de una transacción corta o,
 * como alternativa, una actualización condicional con ORDER BY ... LIMIT y token de reserva.
 */
final class ConductorColaMysql implements ConductorCola
{
    /** Reintentos del reclamo ante un interbloqueo o una espera de bloqueo agotada. */
    private const REINTENTOS_BLOQUEO = 5;

    public function __construct(private readonly BaseDatos $bd, private readonly string $estrategia = 'skip_locked')
    {
    }

    public function encolar(array $datos): array
    {
        // Trabajos sin centro (sistema, demostración): el índice único no deduplica porque MySQL y MariaDB tratan
        // los NULL como distintos. Se busca primero la clave; la ventana de carrera restante solo afecta a
        // trabajos técnicos y se acepta (al menos una vez, RF-Q-010).
        if ($datos['centro_id'] === null && $datos['clave_idempotencia'] !== null) {
            $existente = $this->buscarPorClave(null, $datos['tipo'], $datos['clave_idempotencia']);
            if ($existente !== null) {
                return $existente;
            }
        }
        $ulid = GeneradorUlid::generar();
        try {
            $id = $this->bd->insertar(
                'INSERT INTO trabajos (ulid, cola, tipo, carga, prioridad, max_intentos, disponible_en, clave_idempotencia,
                                       actor_id, centro_id, unidad_id, alcance, correlacion_id)
                 VALUES (:ulid, :cola, :tipo, :carga, :prioridad, :max_intentos, UTC_TIMESTAMP(3) + INTERVAL :retardo SECOND,
                         :clave, :actor, :centro, :unidad, :alcance, :correlacion)',
                [
                    'ulid' => $ulid,
                    'cola' => $datos['cola'],
                    'tipo' => $datos['tipo'],
                    'carga' => json_encode($datos['carga'], JSON_THROW_ON_ERROR),
                    'prioridad' => $datos['prioridad'],
                    'max_intentos' => $datos['max_intentos'],
                    'retardo' => max(0, $datos['retardo_segundos']),
                    'clave' => $datos['clave_idempotencia'],
                    'actor' => $datos['actor_id'],
                    'centro' => $datos['centro_id'],
                    'unidad' => $datos['unidad_id'],
                    'alcance' => $datos['alcance'] === null ? null : json_encode($datos['alcance'], JSON_THROW_ON_ERROR),
                    'correlacion' => $datos['correlacion_id'],
                ]
            );
            return ['id' => $id, 'ulid' => $ulid, 'estado' => 'pendiente', 'duplicado' => false];
        } catch (PDOException $error) {
            // 23000: violación de unicidad. Con la misma clave de idempotencia se devuelve el trabajo original,
            // también cuando dos solicitudes concurrentes compiten por insertar (RF-Q-003).
            if ($error->getCode() !== '23000' || $datos['clave_idempotencia'] === null) {
                throw $error;
            }
            $existente = $this->buscarPorClave($datos['centro_id'], $datos['tipo'], $datos['clave_idempotencia']);
            if ($existente === null) {
                throw $error;
            }
            return $existente;
        }
    }

    /** @return array{id:int,ulid:string,estado:string,duplicado:bool}|null */
    private function buscarPorClave(?int $centro, string $tipo, string $clave): ?array
    {
        $existente = $this->bd->fila(
            'SELECT id, ulid, estado FROM trabajos WHERE centro_id <=> :centro AND tipo = :tipo AND clave_idempotencia = :clave',
            ['centro' => $centro, 'tipo' => $tipo, 'clave' => $clave]
        );
        return $existente === null
            ? null
            : ['id' => (int) $existente['id'], 'ulid' => (string) $existente['ulid'], 'estado' => (string) $existente['estado'], 'duplicado' => true];
    }

    /**
     * Reclamo con reintento ante conflictos de bloqueo. Con SKIP LOCKED, dos trabajadores simultáneos pueden
     * interbloquearse en MariaDB (error 1213: el SELECT bloquea entradas del índice secundario que el UPDATE del otro
     * necesita); InnoDB revierte a uno de los dos, y lo correcto es repetir la transacción. Tras agotar los reintentos
     * no se reclama nada en esta vuelta: los trabajos siguen pendientes y los toma la siguiente ejecución.
     */
    public function reclamar(string $cola, int $cantidad, string $token, int $reservaSegundos, ?array $tiposPermitidos = null): array
    {
        for ($intento = 1; ; $intento++) {
            try {
                return $this->reclamarUnaVez($cola, $cantidad, $token, $reservaSegundos, $tiposPermitidos);
            } catch (PDOException $error) {
                if (!self::esConflictoDeBloqueo($error) || $this->bd->enTransaccion()) {
                    throw $error;
                }
                if ($intento >= self::REINTENTOS_BLOQUEO) {
                    return [];
                }
                usleep(random_int(20_000, 80_000) * $intento);
            }
        }
    }

    /** Interbloqueo (1213) o espera de bloqueo agotada (1205): la transacción se puede repetir. */
    public static function esConflictoDeBloqueo(PDOException $error): bool
    {
        return in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true);
    }

    /**
     * @param list<string>|null $tiposPermitidos
     * @return list<array<string,mixed>>
     */
    private function reclamarUnaVez(string $cola, int $cantidad, string $token, int $reservaSegundos, ?array $tiposPermitidos): array
    {
        $cantidad = max(1, min(50, $cantidad));
        [$filtroTipos, $parametrosTipos] = $this->filtroTipos($tiposPermitidos);
        if ($this->estrategia !== 'skip_locked') {
            // Alternativa portable: una sola sentencia atómica marca el lote con el token de esta ejecución.
            $this->bd->ejecutar(
                "UPDATE trabajos
                    SET estado = 'reservado', reservado_por = :token, intentos = intentos + 1,
                        bloqueado_hasta = UTC_TIMESTAMP(3) + INTERVAL :reserva SECOND,
                        iniciado_en = COALESCE(iniciado_en, UTC_TIMESTAMP(3))
                  WHERE cola = :cola AND estado = 'pendiente' AND disponible_en <= UTC_TIMESTAMP(3) {$filtroTipos}
                  ORDER BY prioridad, disponible_en, id
                  LIMIT {$cantidad}",
                ['token' => $token, 'reserva' => $reservaSegundos, 'cola' => $cola] + $parametrosTipos
            );
            return $this->bd->consultar("SELECT * FROM trabajos WHERE reservado_por = :token AND estado = 'reservado'", ['token' => $token]);
        }
        return $this->bd->transaccion(function (BaseDatos $bd) use ($cola, $cantidad, $token, $reservaSegundos, $filtroTipos, $parametrosTipos): array {
            $ids = array_map('intval', array_column($bd->consultar(
                "SELECT id FROM trabajos
                  WHERE cola = :cola AND estado = 'pendiente' AND disponible_en <= UTC_TIMESTAMP(3) {$filtroTipos}
                  ORDER BY prioridad, disponible_en, id
                  LIMIT {$cantidad}
                  FOR UPDATE SKIP LOCKED",
                ['cola' => $cola] + $parametrosTipos
            ), 'id'));
            if ($ids === []) {
                return [];
            }
            [$enIds, $parametrosIds] = $this->listaIds($ids);
            $bd->ejecutar(
                "UPDATE trabajos
                    SET estado = 'reservado', reservado_por = :token, intentos = intentos + 1,
                        bloqueado_hasta = UTC_TIMESTAMP(3) + INTERVAL :reserva SECOND,
                        iniciado_en = COALESCE(iniciado_en, UTC_TIMESTAMP(3))
                  WHERE id IN ({$enIds}) AND estado = 'pendiente'",
                ['token' => $token, 'reserva' => $reservaSegundos] + $parametrosIds
            );
            return $bd->consultar(
                "SELECT * FROM trabajos WHERE reservado_por = :token AND estado = 'reservado' AND id IN ({$enIds})",
                ['token' => $token] + $parametrosIds
            );
        });
    }

    /** @param array<string,mixed>|null $puntoControl */
    public function ampliarReserva(int $id, string $token, int $segundos, int $progreso, ?string $mensaje = null, ?array $puntoControl = null): bool
    {
        return $this->bd->ejecutar(
            "UPDATE trabajos
                SET bloqueado_hasta = UTC_TIMESTAMP(3) + INTERVAL :segundos SECOND, progreso = :progreso,
                    mensaje_progreso = :mensaje, punto_control = COALESCE(:punto, punto_control)
              WHERE id = :id AND reservado_por = :token AND estado = 'reservado'",
            [
                'segundos' => $segundos,
                'progreso' => max(0, min(100, $progreso)),
                'mensaje' => $mensaje === null ? null : mb_substr($mensaje, 0, 255),
                'punto' => $puntoControl === null ? null : json_encode($puntoControl, JSON_THROW_ON_ERROR),
                'id' => $id,
                'token' => $token,
            ]
        ) === 1;
    }

    public function completar(int $id, string $token, ?array $resultado = null): bool
    {
        return $this->bd->ejecutar(
            "UPDATE trabajos
                SET estado = 'completado', progreso = 100, resultado = :resultado, reservado_por = NULL,
                    bloqueado_hasta = NULL, finalizado_en = UTC_TIMESTAMP(3)
              WHERE id = :id AND reservado_por = :token AND estado = 'reservado'",
            ['resultado' => $resultado === null ? null : json_encode($resultado, JSON_THROW_ON_ERROR), 'id' => $id, 'token' => $token]
        ) === 1;
    }

    public function reprogramar(int $id, string $token, int $retardoSegundos, string $mensajeError): bool
    {
        return $this->bd->ejecutar(
            "UPDATE trabajos
                SET estado = 'pendiente', reservado_por = NULL, bloqueado_hasta = NULL, mensaje_error = :error,
                    disponible_en = UTC_TIMESTAMP(3) + INTERVAL :retardo SECOND
              WHERE id = :id AND reservado_por = :token AND estado = 'reservado'",
            ['error' => mb_substr($mensajeError, 0, 1000), 'retardo' => max(1, $retardoSegundos), 'id' => $id, 'token' => $token]
        ) === 1;
    }

    public function continuar(int $id, string $token): bool
    {
        return $this->bd->ejecutar(
            "UPDATE trabajos
                SET estado = 'pendiente', reservado_por = NULL, bloqueado_hasta = NULL,
                    disponible_en = UTC_TIMESTAMP(3), intentos = GREATEST(intentos - 1, 0)
              WHERE id = :id AND reservado_por = :token AND estado = 'reservado'",
            ['id' => $id, 'token' => $token]
        ) === 1;
    }

    public function fallar(int $id, string $token, string $mensajeError, string $claseError): bool
    {
        return $this->bd->transaccion(function (BaseDatos $bd) use ($id, $token, $mensajeError, $claseError): bool {
            $filas = $bd->ejecutar(
                "UPDATE trabajos
                    SET estado = 'fallido', reservado_por = NULL, bloqueado_hasta = NULL, mensaje_error = :error,
                        finalizado_en = UTC_TIMESTAMP(3)
                  WHERE id = :id AND reservado_por = :token AND estado = 'reservado'",
                ['error' => mb_substr($mensajeError, 0, 1000), 'id' => $id, 'token' => $token]
            );
            if ($filas !== 1) {
                return false;
            }
            $bd->ejecutar(
                'INSERT INTO trabajos_fallidos (trabajo_id, ulid, cola, tipo, carga, intentos, actor_id, centro_id, mensaje_error, clase_error, correlacion_id)
                 SELECT id, ulid, cola, tipo, carga, intentos, actor_id, centro_id, mensaje_error, :clase, correlacion_id
                   FROM trabajos WHERE id = :id',
                ['clase' => $claseError, 'id' => $id]
            );
            return true;
        });
    }

    public function recuperarVencidos(): int
    {
        // Reservas vencidas sin intentos restantes: fallo definitivo por reserva perdida.
        $agotados = $this->bd->consultar(
            "SELECT id, reservado_por FROM trabajos
              WHERE estado = 'reservado' AND bloqueado_hasta < UTC_TIMESTAMP(3) AND intentos >= max_intentos
              LIMIT 100"
        );
        foreach ($agotados as $trabajo) {
            $this->fallar((int) $trabajo['id'], (string) $trabajo['reservado_por'], 'La reserva venció sin respuesta del trabajador y se agotaron los intentos.', 'reserva_perdida');
        }
        // Reservas vencidas con intentos: vuelven a la cola. Un trabajador lento que despierte después no
        // podrá marcarlas porque su token ya no coincide (RF-Q-008).
        $liberados = $this->bd->ejecutar(
            "UPDATE trabajos
                SET estado = 'pendiente', reservado_por = NULL, bloqueado_hasta = NULL, disponible_en = UTC_TIMESTAMP(3),
                    mensaje_error = 'Reserva vencida: el trabajo se reintentará.'
              WHERE estado = 'reservado' AND bloqueado_hasta < UTC_TIMESTAMP(3) AND intentos < max_intentos"
        );
        return count($agotados) + $liberados;
    }

    /**
     * @param list<string>|null $tipos
     * @return array{0:string,1:array<string,string>}
     */
    private function filtroTipos(?array $tipos): array
    {
        if ($tipos === null) {
            return ['', []];
        }
        if ($tipos === []) {
            return ['AND 1 = 0', []];
        }
        $marcadores = [];
        $parametros = [];
        foreach ($tipos as $i => $tipo) {
            $marcadores[] = ":tipo_{$i}";
            $parametros["tipo_{$i}"] = $tipo;
        }
        return ['AND tipo IN (' . implode(', ', $marcadores) . ')', $parametros];
    }

    /**
     * @param list<int> $ids
     * @return array{0:string,1:array<string,int>}
     */
    private function listaIds(array $ids): array
    {
        $marcadores = [];
        $parametros = [];
        foreach ($ids as $i => $id) {
            $marcadores[] = ":id_{$i}";
            $parametros["id_{$i}"] = $id;
        }
        return [implode(', ', $marcadores), $parametros];
    }
}
