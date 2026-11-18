<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Politicas\AlcanceDatos;
use App\Politicas\UsuarioSesion;

/**
 * Construye el AlcanceDatos de la solicitud solo en el servidor (RDI-003 a RDI-005):
 *   - Roles con alcance de centro: todas las unidades de su centro.
 *   - Roles con alcance de unidad: solo sus unidades asignadas.
 *   - Superadministrador (sin centro ni roles de negocio, RF-012): alcance vacío, es decir, ningún dato de negocio.
 * Un alcance vacío nunca significa «todos» (RDI-004).
 */
final class ServicioAlcance
{
    public function paraUsuario(?UsuarioSesion $usuario): AlcanceDatos
    {
        if ($usuario === null || $usuario->centroId === null) {
            return AlcanceDatos::deUsuario(0, [], false);
        }
        return AlcanceDatos::deUsuario($usuario->centroId, $usuario->unidades, $usuario->alcanceCentro);
    }
}
