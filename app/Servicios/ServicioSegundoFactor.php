<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Cifrador;
use App\Nucleo\ErrorHttp;
use App\Nucleo\Sesion;
use App\Nucleo\Solicitud;
use App\Politicas\UsuarioSesion;
use App\Repositorios\RepositorioUsuarios;

/**
 * Gestión de la verificación en dos pasos (RAC-009 a RAC-014).
 *
 * - Enrolamiento: semilla de 160 bits guardada cifrada en la sesión hasta verificar un código; después se cifra
 *   con el contexto «usuarios.totp:{id}» (RS-013) y nunca vuelve a mostrarse. Se entregan 10 códigos de respaldo.
 * - Desactivar exige reautenticación reciente y un código, y no se permite si el rol la exige.
 * - El restablecimiento por un administrador (dispositivo perdido) exige motivo, cierra las sesiones del usuario,
 *   avisa por correo y genera una alerta de seguridad (RAC-014, RAC-031).
 */
final class ServicioSegundoFactor
{
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly RepositorioUsuarios $usuarios,
        private readonly ServicioTotp $totp,
        private readonly ServicioCodigosRespaldo $codigos,
        private readonly ServicioQr $qr,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioNotificacionesCuenta $notificaciones,
        private readonly Cifrador $cifrador,
        private readonly string $emisor,
    ) {
    }

    /**
     * Prepara el enrolamiento: genera (o reutiliza) la semilla pendiente de esta sesión.
     *
     * @return array{semilla:string,uri:string,qr:string}
     */
    public function iniciarEnrolamiento(Sesion $sesion, int $usuarioId, string $correo): array
    {
        $contexto = 'enrolamiento:' . $usuarioId;
        $pendiente = $sesion->obtener('_enrolamiento');
        $semilla = is_string($pendiente) ? $this->cifrador->descifrar($pendiente, $contexto) : null;
        if ($semilla === null) {
            $semilla = $this->totp->generarSemilla();
            $sesion->guardar('_enrolamiento', $this->cifrador->cifrar($semilla, $contexto));
        }
        $uri = $this->totp->uriAprovisionamiento($semilla, $correo, $this->emisor);
        return ['semilla' => trim(chunk_split($semilla, 4, ' ')), 'uri' => $uri, 'qr' => $this->qr->svgDataUri($uri)];
    }

    /**
     * Activa la 2FA con el primer código válido y devuelve los códigos de respaldo (se muestran una sola vez).
     *
     * @return list<string>|null null si el código no es válido
     */
    public function confirmarEnrolamiento(Solicitud $solicitud, Sesion $sesion, int $usuarioId, string $codigo): ?array
    {
        $pendiente = $sesion->obtener('_enrolamiento');
        if (!is_string($pendiente)) {
            return null;
        }
        $semilla = $this->cifrador->descifrar($pendiente, 'enrolamiento:' . $usuarioId);
        $paso = $this->totp->verificar($semilla, trim($codigo), null);
        if ($paso === null) {
            $this->auditoria->registrar($solicitud, '2fa.activar', 'denegado');
            return null;
        }
        $this->bd->ejecutar(
            'UPDATE usuarios SET totp_secreto_cifrado = :cifrado, totp_ultimo_paso = :paso, totp_activado_en = UTC_TIMESTAMP(3) WHERE id = :id',
            ['cifrado' => $this->cifrador->cifrar($semilla, 'usuarios.totp:' . $usuarioId), 'paso' => $paso, 'id' => $usuarioId]
        );
        $sesion->olvidar('_enrolamiento');
        $codigos = $this->codigos->generar($usuarioId);
        $sesion->elevar('2fa');
        $this->auditoria->registrar($solicitud, '2fa.activar', 'exito');
        $this->notificaciones->segundoFactor($usuarioId, 'activación', count($codigos));
        return $codigos;
    }

    /** @return list<string> */
    public function regenerarCodigos(Solicitud $solicitud, UsuarioSesion $usuario): array
    {
        if (!$usuario->tiene2fa) {
            throw new ErrorHttp(409, 'Primero activa la verificación en dos pasos.');
        }
        $codigos = $this->codigos->generar($usuario->id);
        $this->auditoria->registrar($solicitud, '2fa.codigos_regenerar', 'exito');
        return $codigos;
    }

    public function desactivar(Solicitud $solicitud, Sesion $sesion, UsuarioSesion $usuario): void
    {
        if ($usuario->requiere2fa) {
            throw new ErrorHttp(409, 'Tu rol exige la verificación en dos pasos; no se puede desactivar.');
        }
        $this->limpiar($usuario->id);
        $sesion->regenerar();
        $this->auditoria->registrar($solicitud, '2fa.desactivar', 'exito');
        $this->notificaciones->segundoFactor($usuario->id, 'desactivación');
    }

    /** Restablecimiento por un administrador tras verificar la identidad por otro canal (RAC-014). */
    public function restablecerPorAdministrador(Solicitud $solicitud, int $usuarioId, string $motivo): void
    {
        if (trim($motivo) === '' || $this->usuarios->porId($usuarioId) === null) {
            throw new ErrorHttp(422, 'Indica un usuario existente y el motivo del restablecimiento.');
        }
        $this->limpiar($usuarioId);
        $this->bd->ejecutar('UPDATE sesiones SET revocada_en = UTC_TIMESTAMP(3) WHERE usuario_id = :id AND revocada_en IS NULL', ['id' => $usuarioId]);
        $this->auditoria->registrar($solicitud, '2fa.restablecer', 'exito', null, ['usuario_afectado' => $usuarioId], null, null, $motivo);
        $this->notificaciones->segundoFactor($usuarioId, 'restablecimiento por el administrador');
    }

    private function limpiar(int $usuarioId): void
    {
        $this->bd->ejecutar(
            'UPDATE usuarios SET totp_secreto_cifrado = NULL, totp_ultimo_paso = NULL, totp_activado_en = NULL WHERE id = :id',
            ['id' => $usuarioId]
        );
        $this->codigos->eliminar($usuarioId);
    }
}
