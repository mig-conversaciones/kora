<?php

declare(strict_types=1);

namespace App\Politicas;

/**
 * Gestión de usuarios con separación de funciones (RF-009, RF-012):
 *   - Nadie modifica su propia cuenta desde la gestión (roles, unidades o estado).
 *   - Solo un Superadministrador crea o modifica Superadministradores.
 *   - Un Administrador solo gestiona cuentas de su centro.
 * Las denegaciones se responden con 403 y el servicio las audita con resultado «denegado».
 */
final class PoliticaUsuarios implements Politica
{
    public function decidir(UsuarioSesion $usuario, string $accion, ?object $recurso, AlcanceDatos $alcance): Decision
    {
        if (!$usuario->tienePermiso($accion)) {
            return Decision::denegar('Permiso no asignado al rol.');
        }
        if ($recurso === null) {
            return Decision::permitir();
        }
        if (!$recurso instanceof RecursoUsuario) {
            return Decision::denegar('Recurso no reconocido por la política.');
        }
        if ($recurso->id !== null && $recurso->id === $usuario->id) {
            return Decision::denegar('Nadie puede modificar sus propios roles, unidades o estado (RF-012).');
        }
        if ($usuario->esSuperadministrador()) {
            return Decision::permitir();
        }
        if ($recurso->esSuperadministrador) {
            return Decision::denegar('Solo un superadministrador gestiona cuentas de superadministrador (RF-012).');
        }
        if ($usuario->centroId === null || $recurso->centroId !== $usuario->centroId) {
            return Decision::fueraDeAlcance();
        }
        return Decision::permitir();
    }
}
