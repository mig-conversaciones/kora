<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\Solicitud;

/**
 * Catálogo de roles y permisos (RF-011). Cualquier usuario consulta la matriz en formato recurso.accion. Solo el
 * Superadministrador la modifica, con reautenticación. Cada cambio incrementa version_permisos de los usuarios del
 * rol, que reciben los permisos nuevos en su siguiente solicitud.
 *
 * Por separación de funciones (RF-012) no se modifican desde aquí los permisos del rol superadministrador: sus
 * permisos son de plataforma y se fijan en la semilla.
 */
final class ServicioPermisos
{
    public function __construct(private readonly BaseDatos $bd, private readonly ServicioAuditoria $auditoria)
    {
    }

    /**
     * @return array{roles:list<array<string,mixed>>,recursos:array<string,list<array<string,mixed>>>,asignados:array<string,true>}
     */
    public function matriz(): array
    {
        $roles = $this->bd->consultar('SELECT id, codigo, nombre, alcance, requiere_2fa, privilegiado FROM roles ORDER BY id');
        $recursos = [];
        foreach ($this->bd->consultar('SELECT id, codigo, descripcion FROM permisos ORDER BY codigo') as $permiso) {
            $recursos[strstr((string) $permiso['codigo'], '.', true) ?: (string) $permiso['codigo']][] = $permiso;
        }
        $asignados = [];
        foreach ($this->bd->consultar('SELECT rol_id, permiso_id FROM roles_permisos') as $fila) {
            $asignados[$fila['rol_id'] . ':' . $fila['permiso_id']] = true;
        }
        return ['roles' => $roles, 'recursos' => $recursos, 'asignados' => $asignados];
    }

    public function cambiar(Solicitud $solicitud, int $rolId, int $permisoId, bool $conceder): void
    {
        $rol = $this->bd->fila('SELECT id, codigo FROM roles WHERE id = :id', ['id' => $rolId]);
        $permiso = $this->bd->fila('SELECT id, codigo FROM permisos WHERE id = :id', ['id' => $permisoId]);
        if ($rol === null || $permiso === null) {
            throw new ErrorHttp(404);
        }
        if ($rol['codigo'] === 'superadministrador') {
            $this->auditoria->registrar($solicitud, 'roles.gestionar', 'denegado', new RecursoAuditado('roles', $rolId), ['permiso' => $permiso['codigo'], 'motivo_denegacion' => 'Rol superadministrador protegido (RF-012).']);
            throw new ErrorHttp(403, 'Los permisos del superadministrador no se modifican desde la interfaz (separación de funciones).');
        }
        $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $rol, $permiso, $conceder): void {
            $filas = $conceder
                ? $bd->ejecutar('INSERT IGNORE INTO roles_permisos (rol_id, permiso_id) VALUES (:r, :p)', ['r' => $rol['id'], 'p' => $permiso['id']])
                : $bd->ejecutar('DELETE FROM roles_permisos WHERE rol_id = :r AND permiso_id = :p', ['r' => $rol['id'], 'p' => $permiso['id']]);
            if ($filas === 0) {
                return;
            }
            $afectados = $bd->ejecutar('UPDATE usuarios u JOIN usuarios_roles ur ON ur.usuario_id = u.id SET u.version_permisos = u.version_permisos + 1 WHERE ur.rol_id = :r', ['r' => $rol['id']]);
            $this->auditoria->registrar($solicitud, $conceder ? 'roles.conceder_permiso' : 'roles.retirar_permiso', 'exito', new RecursoAuditado('roles', (int) $rol['id']), ['permiso' => $permiso['codigo'], 'usuarios_afectados' => $afectados], ['asignado' => !$conceder], ['asignado' => $conceder]);
        });
    }
}
