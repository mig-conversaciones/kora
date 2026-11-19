<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\ErrorHttp;
use App\Nucleo\Solicitud;
use App\Politicas\AlcanceDatos;
use App\Politicas\Autorizador;
use App\Politicas\UsuarioSesion;

/**
 * Verificación ABAC sobre un registro concreto, dentro de los casos de uso (RAC-023, RAC-028). El intermediario
 * Autorizar ya verificó el permiso de la ruta; aquí se decide con el recurso real (su centro, su unidad, la cuenta
 * objetivo). Una denegación queda auditada con resultado «denegado» y responde 403, o 404 si está fuera del alcance.
 */
final class AutorizacionRecurso
{
    public function __construct(private readonly Autorizador $autorizador, private readonly ServicioAuditoria $auditoria)
    {
    }

    public function exigir(Solicitud $solicitud, string $permiso, ?object $recurso = null): void
    {
        $decision = $this->autorizador->decidir(self::usuario($solicitud), $permiso, $recurso, self::alcance($solicitud));
        if ($decision->permitida()) {
            return;
        }
        $this->auditoria->registrar($solicitud, $permiso, 'denegado', $recurso, ['ruta' => $solicitud->ruta, 'motivo_denegacion' => $decision->motivo()]);
        throw new ErrorHttp($decision->fueraDeAlcanceDelUsuario() ? 404 : 403);
    }

    public function permite(Solicitud $solicitud, string $permiso, ?object $recurso = null): bool
    {
        return $this->autorizador->decidir(self::usuario($solicitud), $permiso, $recurso, self::alcance($solicitud))->permitida();
    }

    public static function usuario(Solicitud $solicitud): UsuarioSesion
    {
        $usuario = $solicitud->atributo('usuario');
        if (!$usuario instanceof UsuarioSesion) {
            throw new ErrorHttp(401);
        }
        return $usuario;
    }

    /** El alcance lo pone Autorizar; si faltara, el alcance es vacío (ningún dato, RDI-004). */
    public static function alcance(Solicitud $solicitud): AlcanceDatos
    {
        $alcance = $solicitud->atributo('alcance');
        return $alcance instanceof AlcanceDatos ? $alcance : AlcanceDatos::deUsuario(0, [], false);
    }
}
