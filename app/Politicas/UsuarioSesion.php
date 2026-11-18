<?php

declare(strict_types=1);

namespace App\Politicas;

/**
 * Usuario autenticado de la solicitud (atributo «usuario»). Lo construye el intermediario Autenticar en cada
 * solicitud a partir de la base de datos (RAC-017): la identidad, los roles, los permisos y las unidades nunca se
 * toman del cliente ni se cachean en la sesión. Es un valor inmutable.
 */
final class UsuarioSesion
{
    /**
     * @param list<string> $permisos códigos recurso.accion de todos sus roles
     * @param list<string> $roles códigos de rol
     * @param list<int> $unidades unidades productivas asignadas
     * @param array<string,string> $preferencias tema, densidad, menu (RF-008)
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $centroId,
        public readonly string $rol,
        private readonly array $permisos = [],
        public readonly array $roles = [],
        public readonly array $unidades = [],
        public readonly string $nombre = '',
        public readonly string $correo = '',
        public readonly bool $privilegiado = false,
        public readonly bool $requiere2fa = false,
        public readonly bool $tiene2fa = false,
        public readonly bool $alcanceCentro = false,
        public readonly array $preferencias = [],
        public readonly int $versionPermisos = 1,
    ) {
    }

    public function esSuperadministrador(): bool
    {
        return in_array('superadministrador', $this->roles, true) || $this->rol === 'superadministrador';
    }

    public function tieneRol(string $rol): bool
    {
        return in_array($rol, $this->roles, true) || $this->rol === $rol;
    }

    /** Denegación por defecto (RAC-023): solo los permisos asignados a sus roles. */
    public function tienePermiso(string $permiso): bool
    {
        return $permiso !== '' && in_array($permiso, $this->permisos, true);
    }

    /** @return list<string> */
    public function permisos(): array
    {
        return $this->permisos;
    }

    /** La sesión exige el segundo factor si el rol lo obliga (RAC-009) o si el usuario lo activó. */
    public function exigeSegundoFactor(): bool
    {
        return $this->requiere2fa || $this->tiene2fa;
    }

    public function nombreRol(): string
    {
        return [
            'superadministrador' => 'Superadministrador',
            'administrador' => 'Administrador funcional',
            'coordinacion_administrativa' => 'Coordinación administrativa',
            'grupo_recaudo' => 'Grupo de Recaudo',
            'lider_produccion_centro' => 'Líder de producción de centro',
            'lider_unidad' => 'Líder de unidad productiva',
            'coordinacion_academica' => 'Coordinación académica',
        ][$this->rol] ?? $this->rol;
    }
}
