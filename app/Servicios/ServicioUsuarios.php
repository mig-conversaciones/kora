<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;
use App\Politicas\RecursoUsuario;

/**
 * Gestión de usuarios desde la interfaz (RF-009, RF-010, RF-012):
 *   - El Superadministrador gestiona todas las cuentas; el Administrador, solo las de su centro y nunca las de un
 *     Superadministrador. Nadie modifica sus propios roles, unidades o estado (PoliticaUsuarios, 403 auditado).
 *   - Los roles y las unidades se asignan con reautenticación reciente (la ruta la exige). Un líder de unidad tiene
 *     al menos una unidad; un Superadministrador no tiene centro, unidades ni roles de negocio.
 *   - Cambiar el acceso incrementa version_permisos: la sesión del afectado se regenera y aplica los permisos
 *     nuevos en su siguiente solicitud (RF-011, RAC-018). Desactivar revoca todas sus sesiones al instante.
 * Cada cambio queda auditado con el estado anterior y el nuevo.
 */
final class ServicioUsuarios
{
    public const ESTADOS = ['invitado' => 'Invitado', 'activo' => 'Activo', 'desactivado' => 'Desactivado'];

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly AutorizacionRecurso $autorizacion,
        private readonly ServicioCuentas $cuentas,
        private readonly ServicioTokensCuenta $tokens,
        private readonly ServicioNotificacionesCuenta $notificaciones,
        private readonly ServicioAuditoria $auditoria,
    ) {
    }

    /**
     * @param array{buscar:string,estado:string,rol:string} $filtros
     * @return array{filas:list<array<string,mixed>>,hayMas:bool}
     */
    public function listar(Solicitud $solicitud, array $filtros, int $pagina, int $porPagina = 25): array
    {
        $actor = AutorizacionRecurso::usuario($solicitud);
        $condiciones = ['u.anonimizado_en IS NULL'];
        $parametros = [];
        if (!$actor->esSuperadministrador()) {
            $condiciones[] = 'u.centro_id = :centro';
            $parametros['centro'] = (int) $actor->centroId;
        }
        if ($filtros['buscar'] !== '') {
            $condiciones[] = '(u.nombre LIKE :buscar OR u.correo LIKE :buscar2)';
            $parametros['buscar'] = $parametros['buscar2'] = '%' . addcslashes($filtros['buscar'], '%_\\') . '%';
        }
        if (isset(self::ESTADOS[$filtros['estado']])) {
            $condiciones[] = 'u.estado = :estado';
            $parametros['estado'] = $filtros['estado'];
        }
        if ($filtros['rol'] !== '') {
            $condiciones[] = 'EXISTS (SELECT 1 FROM usuarios_roles ur2 JOIN roles r2 ON r2.id = ur2.rol_id WHERE ur2.usuario_id = u.id AND r2.codigo = :rol)';
            $parametros['rol'] = $filtros['rol'];
        }
        $filas = $this->bd->consultar(
            'SELECT u.id, u.nombre, u.correo, u.estado, u.centro_id, u.ultimo_acceso_en, u.totp_activado_en,
                    (SELECT GROUP_CONCAT(r.nombre ORDER BY r.id SEPARATOR \', \') FROM usuarios_roles ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = u.id) AS roles,
                    (SELECT GROUP_CONCAT(r.codigo) FROM usuarios_roles ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = u.id) AS codigos_rol,
                    (SELECT GROUP_CONCAT(up.codigo ORDER BY up.codigo SEPARATOR \', \') FROM usuarios_unidades uu JOIN unidades_productivas up ON up.id = uu.unidad_id WHERE uu.usuario_id = u.id) AS unidades
               FROM usuarios u WHERE ' . implode(' AND ', $condiciones) . '
              ORDER BY u.estado = \'desactivado\', u.nombre, u.id LIMIT ' . ($porPagina + 1) . ' OFFSET ' . ((max(1, $pagina) - 1) * $porPagina),
            $parametros
        );
        return ['filas' => array_slice($filas, 0, $porPagina), 'hayMas' => count($filas) > $porPagina];
    }

    /**
     * Cuenta con su rol y sus unidades, si el actor puede gestionarla.
     *
     * @return array<string,mixed>
     */
    public function obtener(Solicitud $solicitud, int $id): array
    {
        $fila = $this->bd->fila('SELECT id, ulid, nombre, correo, estado, centro_id, version_permisos, ultimo_acceso_en, totp_activado_en, creado_en FROM usuarios WHERE id = :id AND anonimizado_en IS NULL', ['id' => $id]);
        if ($fila === null) {
            throw new ErrorHttp(404);
        }
        $fila['roles'] = array_map('strval', array_column($this->bd->consultar('SELECT r.codigo FROM usuarios_roles ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = :id ORDER BY r.id', ['id' => $id]), 'codigo'));
        $fila['unidades'] = array_map('intval', array_column($this->bd->consultar('SELECT unidad_id FROM usuarios_unidades WHERE usuario_id = :id ORDER BY unidad_id', ['id' => $id]), 'unidad_id'));
        $this->autorizacion->exigir($solicitud, 'usuarios.gestionar', self::recurso($fila));
        return $fila;
    }

    /** @return list<array<string,mixed>> roles que el actor puede asignar */
    public function rolesAsignables(Solicitud $solicitud): array
    {
        $roles = $this->bd->consultar('SELECT id, codigo, nombre, alcance, requiere_2fa FROM roles ORDER BY id');
        if (AutorizacionRecurso::usuario($solicitud)->esSuperadministrador()) {
            return $roles;
        }
        return array_values(array_filter($roles, static fn (array $r): bool => $r['alcance'] !== 'global'));
    }

    /** @param array<string,mixed> $datos */
    public function invitar(Solicitud $solicitud, array $datos): int
    {
        $actor = AutorizacionRecurso::usuario($solicitud);
        $rol = (string) ($datos['rol'] ?? '');
        $esSuper = $rol === 'superadministrador';
        $centro = $esSuper ? null : ($actor->esSuperadministrador() ? $this->centroUnico() : $actor->centroId);
        $this->autorizacion->exigir($solicitud, 'usuarios.gestionar', new RecursoUsuario(null, $centro, $esSuper));
        $unidades = $this->unidadesValidas($centro, $datos['unidades'] ?? []);
        return $this->cuentas->invitar($solicitud, (string) ($datos['correo'] ?? ''), (string) ($datos['nombre'] ?? ''), $rol, $centro, $unidades);
    }

    /**
     * Reemplaza el rol y las unidades (RF-010). La ruta exige reautenticación reciente.
     *
     * @param array<string,mixed> $datos
     */
    public function actualizarAcceso(Solicitud $solicitud, int $id, array $datos): void
    {
        $actual = $this->obtener($solicitud, $id);
        $rol = (string) ($datos['rol'] ?? '');
        $filaRol = $this->bd->fila('SELECT id, codigo, nombre, alcance FROM roles WHERE codigo = :c', ['c' => $rol]);
        $nombre = Entrada::texto($datos['nombre'] ?? '', 150);
        $errores = [];
        if ($nombre === null || $nombre === '') {
            $errores['nombre'] = 'Escribe el nombre completo (máximo 150 caracteres).';
        }
        if ($filaRol === null) {
            $errores['rol'] = 'Elige un rol.';
        } elseif (($filaRol['alcance'] === 'global') !== ($actual['centro_id'] === null)) {
            // Separación de funciones (RF-012): una cuenta global no recibe roles de negocio ni al revés.
            $errores['rol'] = $actual['centro_id'] === null
                ? 'Una cuenta de superadministrador no puede tener roles de negocio. Invita otra cuenta para ese rol.'
                : 'Una cuenta de negocio no puede convertirse en superadministrador. Invita una cuenta aparte.';
        }
        $centro = $actual['centro_id'] === null ? null : (int) $actual['centro_id'];
        $unidades = $this->unidadesValidas($centro, $datos['unidades'] ?? []);
        if ($filaRol !== null && $filaRol['alcance'] === 'unidad' && $unidades === []) {
            $errores['unidades'] = 'El rol de líder de unidad exige al menos una unidad.';
        }
        if ($filaRol !== null && $filaRol['alcance'] === 'global' && $unidades !== []) {
            $errores['unidades'] = 'El superadministrador no tiene unidades.';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        /** @var array{id:int|string,codigo:string,nombre:string,alcance:string} $filaRol */
        $antes = ['nombre' => $actual['nombre'], 'roles' => $actual['roles'], 'unidades' => $actual['unidades']];
        $despues = ['nombre' => $nombre, 'roles' => [$filaRol['codigo']], 'unidades' => $unidades];
        if ($antes == $despues) {
            return;
        }
        $actorId = AutorizacionRecurso::usuario($solicitud)->id;
        $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $id, $centro, $filaRol, $unidades, $nombre, $antes, $despues, $actorId): void {
            $bd->ejecutar('UPDATE usuarios SET nombre = :nombre, version_permisos = version_permisos + 1 WHERE id = :id', ['nombre' => $nombre, 'id' => $id]);
            $bd->ejecutar('DELETE FROM usuarios_roles WHERE usuario_id = :id', ['id' => $id]);
            $bd->ejecutar(
                'INSERT INTO usuarios_roles (usuario_id, rol_id, centro_id, asignado_por) VALUES (:u, :r, :c, :a)',
                ['u' => $id, 'r' => (int) $filaRol['id'], 'c' => $centro, 'a' => $actorId]
            );
            $bd->ejecutar('DELETE FROM usuarios_unidades WHERE usuario_id = :id', ['id' => $id]);
            foreach ($unidades as $unidad) {
                $bd->ejecutar('INSERT INTO usuarios_unidades (usuario_id, unidad_id, asignado_por) VALUES (:u, :un, :a)', ['u' => $id, 'un' => $unidad, 'a' => $actorId]);
            }
            $this->auditoria->registrar($solicitud, 'usuarios.asignar_acceso', 'exito', new RecursoAuditado('usuarios', $id), [], $antes, $despues);
        });
    }

    /** Desactiva (revoca todas sus sesiones) o reactiva una cuenta (RF-009). */
    public function cambiarEstado(Solicitud $solicitud, int $id, bool $activar, string $motivo): void
    {
        $actual = $this->obtener($solicitud, $id);
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 500) {
            throw new ErrorValidacion(['motivo' => 'Indica el motivo (entre 5 y 500 caracteres).']);
        }
        if ($activar === ($actual['estado'] !== 'desactivado')) {
            throw new ErrorHttp(409, $activar ? 'La cuenta ya está activa.' : 'La cuenta ya está desactivada.');
        }
        $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $id, $activar, $motivo, $actual): void {
            if ($activar) {
                // Vuelve a «activo» si ya había definido contraseña; si no, sigue pendiente de su invitación.
                $bd->ejecutar("UPDATE usuarios SET estado = IF(hash_contrasena IS NULL, 'invitado', 'activo'), version_permisos = version_permisos + 1 WHERE id = :id", ['id' => $id]);
            } else {
                $bd->ejecutar("UPDATE usuarios SET estado = 'desactivado', version_permisos = version_permisos + 1 WHERE id = :id", ['id' => $id]);
                $bd->ejecutar('UPDATE sesiones SET revocada_en = UTC_TIMESTAMP(3) WHERE usuario_id = :id AND revocada_en IS NULL', ['id' => $id]);
                $bd->ejecutar('UPDATE tokens_cuenta SET usado_en = UTC_TIMESTAMP(3) WHERE usuario_id = :id AND usado_en IS NULL', ['id' => $id]);
            }
            $nuevo = (string) $bd->valor('SELECT estado FROM usuarios WHERE id = :id', ['id' => $id]);
            $this->auditoria->registrar($solicitud, $activar ? 'usuarios.reactivar' : 'usuarios.desactivar', 'exito', new RecursoAuditado('usuarios', $id), [], ['estado' => $actual['estado']], ['estado' => $nuevo], $motivo);
        });
    }

    /** Sesiones abiertas de una cuenta, para el detalle del usuario (ASVS V7.4.5). */
    public function sesionesActivas(int $id): int
    {
        return (int) $this->bd->valor(
            'SELECT COUNT(*) FROM sesiones WHERE usuario_id = :id AND revocada_en IS NULL AND expira_absoluta_en > UTC_TIMESTAMP(3)',
            ['id' => $id]
        );
    }

    /**
     * Cierra todas las sesiones de una cuenta sin desactivarla (ASVS V7.4.5): por ejemplo, ante un equipo perdido. La
     * persona puede volver a ingresar. Nadie lo hace sobre su propia cuenta: para eso está Mi cuenta.
     */
    public function cerrarSesiones(Solicitud $solicitud, int $id, string $motivo): int
    {
        $this->obtener($solicitud, $id);
        if ($id === AutorizacionRecurso::usuario($solicitud)->id) {
            throw new ErrorValidacion(['motivo' => 'Para tus propias sesiones usa Mi cuenta.']);
        }
        $motivo = self::motivo($motivo);
        $cerradas = $this->bd->ejecutar('UPDATE sesiones SET revocada_en = UTC_TIMESTAMP(3) WHERE usuario_id = :id AND revocada_en IS NULL', ['id' => $id]);
        $this->auditoria->registrar($solicitud, 'sesion.revocar_usuario', 'exito', new RecursoAuditado('usuarios', $id), ['cerradas' => $cerradas], null, null, $motivo);
        return $cerradas;
    }

    /**
     * Cierra las sesiones de todos los usuarios menos la de quien lo hace (ASVS V7.4.5; runbook «Incidente de
     * seguridad»). Solo el superadministrador.
     */
    public function cerrarTodasLasSesiones(Solicitud $solicitud, ?string $huellaActual, string $motivo): int
    {
        if (!AutorizacionRecurso::usuario($solicitud)->esSuperadministrador()) {
            throw new ErrorHttp(403);
        }
        $motivo = self::motivo($motivo);
        $cerradas = $this->bd->ejecutar('UPDATE sesiones SET revocada_en = UTC_TIMESTAMP(3) WHERE revocada_en IS NULL AND id <> :actual', ['actual' => $huellaActual ?? '']);
        $this->auditoria->registrar($solicitud, 'sesion.revocar_todas', 'exito', null, ['cerradas' => $cerradas], null, null, $motivo);
        return $cerradas;
    }

    private static function motivo(string $motivo): string
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 500) {
            throw new ErrorValidacion(['motivo' => 'Indica el motivo (entre 5 y 500 caracteres).']);
        }
        return $motivo;
    }

    /** Reenvía la invitación de una cuenta que aún no la activó; el enlace anterior deja de valer. */
    public function reenviarInvitacion(Solicitud $solicitud, int $id): void
    {
        $actual = $this->obtener($solicitud, $id);
        if ($actual['estado'] !== 'invitado') {
            throw new ErrorHttp(409, 'Solo se reenvía la invitación de una cuenta que aún no la activó.');
        }
        $rol = $this->bd->fila('SELECT r.nombre, r.requiere_2fa FROM usuarios_roles ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = :id ORDER BY r.id LIMIT 1', ['id' => $id]);
        $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $id, $rol): void {
            $bd->ejecutar("UPDATE tokens_cuenta SET usado_en = UTC_TIMESTAMP(3) WHERE usuario_id = :id AND tipo = 'invitacion' AND usado_en IS NULL", ['id' => $id]);
            $token = $this->tokens->emitir($id, 'invitacion');
            $this->notificaciones->invitacion($id, $token, (string) ($rol['nombre'] ?? ''), (bool) (int) ($rol['requiere_2fa'] ?? 0));
            $this->auditoria->registrar($solicitud, 'usuarios.reenviar_invitacion', 'exito', new RecursoAuditado('usuarios', $id));
        });
    }

    /** @param array<string,mixed> $fila */
    public static function recurso(array $fila): RecursoUsuario
    {
        return new RecursoUsuario(
            (int) $fila['id'],
            $fila['centro_id'] === null ? null : (int) $fila['centro_id'],
            in_array('superadministrador', (array) ($fila['roles'] ?? []), true) || $fila['centro_id'] === null,
        );
    }

    /**
     * Unidades enviadas que pertenecen al centro y están activas; las demás se descartan sin error (la lista la arma
     * el servidor).
     *
     * @return list<int>
     */
    private function unidadesValidas(?int $centro, mixed $unidades): array
    {
        if ($centro === null || !is_array($unidades) || $unidades === []) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map(static fn ($u): int => (int) $u, $unidades), static fn (int $u): bool => $u > 0)));
        if ($ids === []) {
            return [];
        }
        $marcadores = [];
        $parametros = ['centro' => $centro];
        foreach ($ids as $i => $u) {
            $marcadores[] = ":u{$i}";
            $parametros["u{$i}"] = $u;
        }
        return array_map('intval', array_column($this->bd->consultar(
            "SELECT id FROM unidades_productivas WHERE centro_id = :centro AND activa = 1 AND codigo <> 'SIN' AND id IN (" . implode(', ', $marcadores) . ') ORDER BY id',
            $parametros
        ), 'id'));
    }

    /** La v1 opera un centro (S-19): el superadministrador invita los roles de negocio a ese centro. */
    private function centroUnico(): int
    {
        $centro = $this->bd->valor('SELECT MIN(id) FROM centros WHERE activo = 1');
        if ($centro === null) {
            throw new ErrorValidacion(['rol' => 'No hay un centro activo; siembra los datos de configuración primero.']);
        }
        return (int) $centro;
    }
}
