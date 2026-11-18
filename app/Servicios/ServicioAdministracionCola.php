<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\GeneradorUlid;
use App\Nucleo\Solicitud;

/**
 * Operaciones del panel de colas (RF-Q-014): reintentar un fallido, cancelar un pendiente y descartar un fallido
 * revisado. Exigen motivo y quedan en la auditoría. En la I1 se usan desde cli/cola.php; el panel web para el
 * superadministrador llega con las sesiones (I2) y reutiliza este servicio.
 */
final class ServicioAdministracionCola
{
    public function __construct(private readonly BaseDatos $bd, private readonly ServicioAuditoria $auditoria)
    {
    }

    /** Fallido → pendiente con los intentos reiniciados (criterio de RF-Q-014). */
    public function reintentar(Solicitud $solicitud, string $ulid, string $motivo): void
    {
        $this->operar($solicitud, $ulid, $motivo, 'cola.reintentar', ['fallido'], function (int $id): int {
            $filas = $this->bd->ejecutar(
                "UPDATE trabajos SET estado = 'pendiente', intentos = 0, mensaje_error = NULL, reservado_por = NULL,
                        bloqueado_hasta = NULL, finalizado_en = NULL, disponible_en = UTC_TIMESTAMP(3)
                  WHERE id = :id AND estado = 'fallido'",
                ['id' => $id]
            );
            $this->marcarRevisado($id);
            return $filas;
        });
    }

    /** Pendiente → cancelado (no se ejecutará). */
    public function cancelar(Solicitud $solicitud, string $ulid, string $motivo): void
    {
        $this->operar($solicitud, $ulid, $motivo, 'cola.cancelar', ['pendiente'], fn (int $id): int => $this->bd->ejecutar(
            "UPDATE trabajos SET estado = 'cancelado', finalizado_en = UTC_TIMESTAMP(3) WHERE id = :id AND estado = 'pendiente'",
            ['id' => $id]
        ));
    }

    /** Fallido revisado que no se reintentará: se marca como revisado y queda para la limpieza de 90 días. */
    public function descartar(Solicitud $solicitud, string $ulid, string $motivo): void
    {
        $this->operar($solicitud, $ulid, $motivo, 'cola.descartar', ['fallido'], fn (int $id): int => $this->marcarRevisado($id));
    }

    /**
     * @param list<string> $estadosPermitidos
     * @param callable(int):int $operacion devuelve las filas afectadas
     */
    private function operar(Solicitud $solicitud, string $ulid, string $motivo, string $accion, array $estadosPermitidos, callable $operacion): void
    {
        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo) > 500) {
            throw new ErrorValidacion(['motivo' => 'Indica el motivo (máximo 500 caracteres).']);
        }
        if (!GeneradorUlid::esValido($ulid)) {
            throw new ErrorHttp(404);
        }
        $this->bd->transaccion(function () use ($solicitud, $ulid, $motivo, $accion, $estadosPermitidos, $operacion): void {
            $trabajo = $this->bd->fila('SELECT id, tipo, estado, intentos FROM trabajos WHERE ulid = :ulid FOR UPDATE', ['ulid' => $ulid]);
            if ($trabajo === null) {
                throw new ErrorHttp(404);
            }
            if (!in_array($trabajo['estado'], $estadosPermitidos, true) || $operacion((int) $trabajo['id']) < 1) {
                throw new ErrorHttp(409, "El trabajo está en estado «{$trabajo['estado']}»; la operación no aplica.");
            }
            $this->auditoria->registrar($solicitud, $accion, 'exito', null, ['trabajo' => $ulid, 'tipo' => $trabajo['tipo']], [
                'estado' => $trabajo['estado'],
                'intentos' => (int) $trabajo['intentos'],
            ], null, $motivo);
        });
    }

    private function marcarRevisado(int $trabajoId): int
    {
        return $this->bd->ejecutar(
            'UPDATE trabajos_fallidos SET revisado_en = UTC_TIMESTAMP(3) WHERE trabajo_id = :id AND revisado_en IS NULL',
            ['id' => $trabajoId]
        ) + 1;
    }
}
