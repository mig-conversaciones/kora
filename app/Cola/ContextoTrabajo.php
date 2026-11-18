<?php

declare(strict_types=1);

namespace App\Cola;

/**
 * Contexto de ejecución de un trabajo: latido que amplía la reserva e informa progreso, control del
 * presupuesto de tiempo de la ejecución del trabajador y continuación de trabajos por bloques (RF-Q-011).
 */
final class ContextoTrabajo
{
    private bool $continuar = false;

    public function __construct(
        private readonly ConductorCola $conductor,
        private readonly int $id,
        private readonly string $token,
        private readonly float $limite,
        private readonly int $reservaSegundos,
    ) {
    }

    /** @param array<string,mixed>|null $puntoControl */
    public function latido(int $progreso, ?string $mensaje = null, ?array $puntoControl = null): void
    {
        if (!$this->conductor->ampliarReserva($this->id, $this->token, $this->reservaSegundos, $progreso, $mensaje, $puntoControl)) {
            throw new ReservaPerdida('La reserva del trabajo se perdió; se detiene sin efectos adicionales.');
        }
    }

    public function quedaTiempo(float $segundosNecesarios = 5.0): bool
    {
        return microtime(true) + $segundosNecesarios < $this->limite;
    }

    /** El manejador guardó su punto de control y pide seguir en la próxima ejecución sin consumir intento. */
    public function solicitarContinuacion(): void
    {
        $this->continuar = true;
    }

    public function debeContinuar(): bool
    {
        return $this->continuar;
    }
}
