<?php

declare(strict_types=1);

namespace App\Trabajos;

use App\Cola\ContextoTrabajo;
use App\Cola\CortaCircuitos;
use App\Cola\ErrorDefinitivo;
use App\Cola\ErrorTransitorio;
use App\Cola\ManejadorTrabajo;
use App\Nucleo\Cifrador;
use App\Servicios\CorreoRechazado;
use App\Servicios\ServicioCorreo;
use App\Servicios\ServicioNotificacionesCuenta;
use Throwable;

/**
 * Envía un correo de plantilla (tipos correo.enviar y correo.sistema; base: 04-04b). La carga lleva el id del
 * destinatario, no su correo (minimización de PII); solo correo.sistema puede dirigirse al buzón de
 * «operaciones». Idempotencia: el Message-ID se guarda como punto de control inmediatamente después de
 * entregarlo; un reintento lo detecta y no reenvía. Queda una ventana mínima de duplicado si el proceso cae
 * entre la entrega y el latido, aceptada y documentada (al menos una vez, RF-Q-010).
 */
final class EnviarCorreo implements ManejadorTrabajo
{
    public function __construct(
        private readonly ServicioCorreo $correo,
        private readonly CortaCircuitos $circuitos,
        private readonly Cifrador $cifrador,
    ) {
    }

    public function ejecutar(array $trabajo, ContextoTrabajo $contexto): array
    {
        $punto = json_decode((string) ($trabajo['punto_control'] ?? 'null'), true);
        if (is_array($punto) && !empty($punto['mensaje_id'])) {
            return ['mensaje_id' => $punto['mensaje_id'], 'reenviado' => false];
        }
        /** @var array<string,mixed> $carga */
        $carga = $trabajo['carga'];
        $destinatario = $carga['destinatario_id'] ?? null;
        if ($destinatario === 'operaciones' && $trabajo['tipo'] !== 'correo.sistema') {
            throw new ErrorDefinitivo('Solo los correos del sistema pueden dirigirse al buzón de operaciones.');
        }
        if (!is_int($destinatario) && $destinatario !== 'operaciones') {
            throw new ErrorDefinitivo('La carga del correo no tiene un destinatario válido.');
        }
        if (!$this->circuitos->permite('smtp')) {
            throw new ErrorTransitorio('El servicio de correo está en pausa por fallos recientes; se reintentará.', 60);
        }
        try {
            $mensajeId = $this->correo->enviarPlantilla((string) ($carga['plantilla'] ?? ''), $destinatario, $this->datos($carga));
        } catch (CorreoRechazado $error) {
            throw new ErrorDefinitivo('El destinatario o la plantilla del correo no son válidos.', 0, $error);
        } catch (Throwable $error) {
            $this->circuitos->registrarFallo('smtp');
            throw new ErrorTransitorio('No fue posible contactar el servidor de correo.');
        }
        $this->circuitos->registrarExito('smtp');
        $contexto->latido(100, 'Correo entregado al servidor de correo.', ['mensaje_id' => $mensajeId]);
        return ['mensaje_id' => $mensajeId];
    }

    /**
     * Datos de la plantilla. Los que llevan enlaces con token llegan cifrados en la carga (RAU-007); una carga
     * alterada o cifrada con otra clave es un error definitivo.
     *
     * @param array<string,mixed> $carga
     * @return array<string,mixed>
     */
    private function datos(array $carga): array
    {
        if (!isset($carga['datos_cifrados'])) {
            return (array) ($carga['datos'] ?? []);
        }
        try {
            $datos = json_decode($this->cifrador->descifrar((string) $carga['datos_cifrados'], ServicioNotificacionesCuenta::CONTEXTO_CIFRADO), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new CorreoRechazado('Los datos cifrados del correo no son válidos.');
        }
        return is_array($datos) ? $datos : [];
    }
}
