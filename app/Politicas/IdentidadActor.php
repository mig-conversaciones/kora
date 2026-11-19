<?php

declare(strict_types=1);

namespace App\Politicas;

use App\Nucleo\BaseDatos;

/**
 * Identidad del actor de un trabajo, releída de la base al ejecutarlo (RAC-024): un trabajo encolado nunca conserva
 * los permisos ni el alcance que tenía su autor al pedirlo. Una cuenta inactiva no tiene permisos ni alcance.
 */
final class IdentidadActor
{
    /**
     * @param list<string> $permisos
     * @param list<string> $roles
     */
    private function __construct(
        public readonly int $usuarioId,
        public readonly string $nombre,
        public readonly array $permisos,
        public readonly array $roles,
        public readonly AlcanceDatos $alcance,
    ) {
    }

    public static function cargar(BaseDatos $bd, int $usuarioId, int $centroId): self
    {
        $usuario = $bd->fila('SELECT id, nombre, estado, centro_id FROM usuarios WHERE id = :u', ['u' => $usuarioId]);
        if ($usuario === null || (string) $usuario['estado'] !== 'activo' || (int) $usuario['centro_id'] !== $centroId) {
            return new self($usuarioId, '', [], [], AlcanceDatos::deUsuario(0, [], false));
        }
        $permisos = array_map('strval', array_column($bd->consultar(
            'SELECT DISTINCT p.codigo FROM usuarios_roles ur JOIN roles_permisos rp ON rp.rol_id = ur.rol_id JOIN permisos p ON p.id = rp.permiso_id WHERE ur.usuario_id = :u',
            ['u' => $usuarioId]
        ), 'codigo'));
        $roles = $bd->consultar('SELECT r.codigo, r.alcance FROM usuarios_roles ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = :u', ['u' => $usuarioId]);
        $deCentro = array_filter($roles, static fn (array $r): bool => in_array((string) $r['alcance'], ['centro', 'global'], true)) !== [];
        $unidades = array_map('intval', array_column($bd->consultar('SELECT unidad_id FROM usuarios_unidades WHERE usuario_id = :u', ['u' => $usuarioId]), 'unidad_id'));
        return new self(
            $usuarioId,
            (string) $usuario['nombre'],
            $permisos,
            array_map(static fn (array $r): string => (string) $r['codigo'], $roles),
            AlcanceDatos::deUsuario($centroId, $unidades, $deCentro)
        );
    }

    public function tienePermiso(string $permiso): bool
    {
        return in_array($permiso, $this->permisos, true);
    }
}
