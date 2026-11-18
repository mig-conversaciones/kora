<?php

declare(strict_types=1);

namespace App\Cola;

/**
 * Contrato del conductor de la cola (equivale a QueueDriver). La implementación de Hostinger Cloud es
 * ConductorColaMysql; una aceleración con Redis en VPS debería conservar MySQL como fuente durable y
 * reconciliar pendientes ante su caída, sin crear una segunda cola independiente (RF-Q-018).
 */
interface ConductorCola
{
    /**
     * Persiste el trabajo. Con clave de idempotencia repetida devuelve el existente (duplicado = true).
     *
     * @param array{tipo:string,cola:string,carga:array<string,mixed>,max_intentos:int,prioridad:int,clave_idempotencia:?string,actor_id:?int,centro_id:?int,unidad_id:?int,alcance:?array<string,mixed>,correlacion_id:?string,retardo_segundos:int} $datos
     * @return array{id:int,ulid:string,estado:string,duplicado:bool}
     */
    public function encolar(array $datos): array;

    /**
     * Reclama atómicamente hasta $cantidad trabajos disponibles y los deja reservados con $token.
     *
     * @param list<string>|null $tiposPermitidos null = todos (fuera de mantenimiento)
     * @return list<array<string,mixed>>
     */
    public function reclamar(string $cola, int $cantidad, string $token, int $reservaSegundos, ?array $tiposPermitidos = null): array;

    /**
     * Amplía la reserva e informa avance; false si la reserva se perdió.
     *
     * @param array<string,mixed>|null $puntoControl
     */
    public function ampliarReserva(int $id, string $token, int $segundos, int $progreso, ?string $mensaje = null, ?array $puntoControl = null): bool;

    /** @param array<string,mixed>|null $resultado */
    public function completar(int $id, string $token, ?array $resultado = null): bool;

    /** Fallo transitorio con intentos disponibles: vuelve a pendiente tras la espera indicada. */
    public function reprogramar(int $id, string $token, int $retardoSegundos, string $mensajeError): bool;

    /** Trabajo por bloques que debe seguir: vuelve a pendiente sin consumir un intento. */
    public function continuar(int $id, string $token): bool;

    /** Fallo definitivo: estado fallido y copia en trabajos_fallidos. */
    public function fallar(int $id, string $token, string $mensajeError, string $claseError): bool;

    /** Libera reservas vencidas (trabajador caído) o las envía a fallidos si agotaron intentos. */
    public function recuperarVencidos(): int;
}
