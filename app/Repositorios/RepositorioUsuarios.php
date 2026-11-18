<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Nucleo\BaseDatos;
use App\Politicas\UsuarioSesion;

/**
 * Consultas de identidad (RAC-017, RAC-023). No hereda de RepositorioConAlcance porque la identidad es previa al
 * alcance: aquí se construye el UsuarioSesion del que luego se deriva el AlcanceDatos.
 */
final class RepositorioUsuarios
{
    public function __construct(private readonly BaseDatos $bd)
    {
    }

    /** @return array<string,mixed>|null fila de usuarios (sin anonimizados) */
    public function porCorreo(string $correo): ?array
    {
        return $this->bd->fila('SELECT * FROM usuarios WHERE correo = :correo AND anonimizado_en IS NULL', ['correo' => self::normalizarCorreo($correo)]);
    }

    /** @return array<string,mixed>|null */
    public function porId(int $id): ?array
    {
        return $this->bd->fila('SELECT * FROM usuarios WHERE id = :id AND anonimizado_en IS NULL', ['id' => $id]);
    }

    /**
     * Identidad completa para la solicitud: roles, permisos y unidades leídos de la base en cada solicitud, de modo
     * que una revocación tiene efecto inmediato. Solo usuarios activos.
     */
    public function identidad(int $id): ?UsuarioSesion
    {
        $usuario = $this->bd->fila("SELECT * FROM usuarios WHERE id = :id AND estado = 'activo' AND anonimizado_en IS NULL", ['id' => $id]);
        if ($usuario === null) {
            return null;
        }
        $roles = $this->bd->consultar(
            'SELECT r.codigo, r.alcance, r.requiere_2fa, r.privilegiado, ur.centro_id
               FROM usuarios_roles ur JOIN roles r ON r.id = ur.rol_id
              WHERE ur.usuario_id = :id
              ORDER BY r.privilegiado DESC, r.id',
            ['id' => $id]
        );
        $permisos = array_map('strval', array_column($this->bd->consultar(
            'SELECT DISTINCT p.codigo FROM usuarios_roles ur
               JOIN roles_permisos rp ON rp.rol_id = ur.rol_id JOIN permisos p ON p.id = rp.permiso_id
              WHERE ur.usuario_id = :id ORDER BY p.codigo',
            ['id' => $id]
        ), 'codigo'));
        $unidades = array_map('intval', array_column($this->bd->consultar(
            'SELECT unidad_id FROM usuarios_unidades WHERE usuario_id = :id ORDER BY unidad_id',
            ['id' => $id]
        ), 'unidad_id'));
        $codigos = array_map('strval', array_column($roles, 'codigo'));
        $preferencias = json_decode((string) ($usuario['preferencias'] ?? 'null'), true);
        return new UsuarioSesion(
            id: (int) $usuario['id'],
            centroId: $usuario['centro_id'] === null ? null : (int) $usuario['centro_id'],
            rol: $codigos[0] ?? '',
            permisos: $permisos,
            roles: $codigos,
            unidades: $unidades,
            nombre: (string) $usuario['nombre'],
            correo: (string) $usuario['correo'],
            privilegiado: in_array(1, array_map('intval', array_column($roles, 'privilegiado')), true),
            requiere2fa: in_array(1, array_map('intval', array_column($roles, 'requiere_2fa')), true),
            tiene2fa: $usuario['totp_activado_en'] !== null,
            alcanceCentro: (bool) array_intersect(['centro', 'global'], array_map('strval', array_column($roles, 'alcance'))),
            preferencias: is_array($preferencias) ? array_map('strval', $preferencias) : [],
            versionPermisos: (int) $usuario['version_permisos'],
        );
    }

    public function actualizarHash(int $id, string $hash): void
    {
        $this->bd->ejecutar(
            'UPDATE usuarios SET hash_contrasena = :hash, contrasena_cambiada_en = UTC_TIMESTAMP(3) WHERE id = :id',
            ['hash' => $hash, 'id' => $id]
        );
    }

    public function registrarAcceso(int $id): void
    {
        $this->bd->ejecutar('UPDATE usuarios SET ultimo_acceso_en = UTC_TIMESTAMP(3) WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string,string> $preferencias */
    public function guardarPreferencias(int $id, array $preferencias): void
    {
        $this->bd->ejecutar(
            'UPDATE usuarios SET preferencias = :preferencias WHERE id = :id',
            ['preferencias' => json_encode($preferencias, JSON_THROW_ON_ERROR), 'id' => $id]
        );
    }

    public static function normalizarCorreo(string $correo): string
    {
        return mb_strtolower(trim($correo));
    }
}
