<?php

declare(strict_types=1);

namespace App\Intermediarios;

use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Solicitud;
use App\Repositorios\RepositorioUsuarios;
use App\Servicios\ServicioAuditoria;

/**
 * Resuelve el usuario de la sesión (RAC-017, RAC-018). La identidad, los roles y los permisos se leen de la base en
 * cada solicitud: desactivar a un usuario o quitarle un permiso tiene efecto inmediato.
 *
 * - Si la cuenta exige el segundo factor y la sesión aún está en nivel «contraseña», el usuario queda como
 *   «usuario_pendiente»: solo puede completar la 2FA o el enrolamiento (RAC-009).
 * - Si cambió la versión de permisos del usuario, el ID de sesión se regenera (RAC-018).
 * - Una sesión de un usuario desactivado se cierra.
 */
final class Autenticar implements Intermediario
{
    public function __construct(private readonly Sesion $sesion, private readonly RepositorioUsuarios $usuarios, private readonly ServicioAuditoria $auditoria)
    {
    }

    public function procesar(Solicitud $solicitud, callable $siguiente): Respuesta
    {
        $usuarioId = $this->sesion->existe() ? $this->sesion->usuarioId() : null;
        if ($usuarioId === null) {
            return $siguiente($solicitud);
        }
        $identidad = $this->usuarios->identidad($usuarioId);
        if ($identidad === null) {
            $this->auditoria->registrar($solicitud, 'sesion.revocar', 'exito', null, ['motivo' => 'usuario_inactivo', 'usuario' => $usuarioId]);
            $this->sesion->cerrar();
            return $siguiente($solicitud);
        }
        if ($identidad->exigeSegundoFactor() && $this->sesion->nivel() !== '2fa') {
            return $siguiente($solicitud->conAtributo('usuario_pendiente', $identidad));
        }
        $version = $this->sesion->obtener('_version_permisos');
        if ($version !== null && (int) $version !== $identidad->versionPermisos) {
            $this->sesion->regenerar();
            $this->sesion->guardar('_version_permisos', $identidad->versionPermisos);
            $this->auditoria->registrar($solicitud->conAtributo('usuario', $identidad), 'sesion.regenerar', 'exito', null, ['motivo' => 'permisos_cambiados']);
        }
        return $siguiente($solicitud->conAtributo('usuario', $identidad));
    }
}
