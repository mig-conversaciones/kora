<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\GeneradorUlid;
use App\Nucleo\Sesion;
use App\Nucleo\Solicitud;
use App\Politicas\UsuarioSesion;
use App\Repositorios\RepositorioUsuarios;

/**
 * Ciclo de vida de la cuenta (RAC-003, RAC-005 a RAC-008, RF-009):
 *   - Invitación con enlace de un solo uso (72 h) y activación con contraseña propia.
 *   - Recuperación con respuesta homogénea («si la cuenta existe…»), enlace de 30 min y cierre de todas las sesiones.
 *   - Cambio de contraseña con la actual, regeneración de la sesión y aviso por correo.
 * La gestión de usuarios desde la interfaz (editar, roles, unidades) es de la I3; aquí está el núcleo que la CLI y
 * esa interfaz comparten.
 */
final class ServicioCuentas
{
    public const MENSAJE_RECUPERACION = 'Si la cuenta existe y está activa, enviaremos instrucciones a ese correo en unos minutos.';

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly RepositorioUsuarios $usuarios,
        private readonly ServicioContrasenas $contrasenas,
        private readonly ServicioTokensCuenta $tokens,
        private readonly ServicioLimiteTasa $limites,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioNotificacionesCuenta $notificaciones,
    ) {
    }

    /**
     * Crea un usuario invitado con su rol (y unidades) y le envía el enlace de activación (outbox: usuario y correo
     * se confirman juntos, RF-Q-004). Separación de funciones (RF-012): el superadministrador no recibe roles de
     * negocio ni centro.
     *
     * @param list<int> $unidades
     */
    public function invitar(Solicitud $solicitud, string $correo, string $nombre, string $rol, ?int $centroId, array $unidades = []): int
    {
        $correo = RepositorioUsuarios::normalizarCorreo($correo);
        $nombre = trim(\Normalizer::normalize($nombre, \Normalizer::FORM_C) ?: $nombre);
        $errores = [];
        if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false || mb_strlen($correo) > 190) {
            $errores['correo'] = 'Escribe un correo válido.';
        }
        if ($nombre === '' || mb_strlen($nombre) > 150) {
            $errores['nombre'] = 'Escribe el nombre completo (máximo 150 caracteres).';
        }
        $filaRol = $this->bd->fila('SELECT id, nombre, alcance, requiere_2fa FROM roles WHERE codigo = :codigo', ['codigo' => $rol]);
        if ($filaRol === null) {
            $errores['rol'] = 'El rol no existe.';
        } elseif ($filaRol['alcance'] === 'global' && ($centroId !== null || $unidades !== [])) {
            $errores['rol'] = 'El superadministrador no tiene centro ni unidades (separación de funciones).';
        } elseif ($filaRol['alcance'] !== 'global' && $centroId === null) {
            $errores['centro'] = 'Los roles de negocio requieren un centro.';
        } elseif ($filaRol['alcance'] === 'unidad' && $unidades === []) {
            $errores['unidades'] = 'El rol de líder de unidad exige al menos una unidad.';
        }
        if ($errores === [] && $this->usuarios->porCorreo($correo) !== null) {
            $errores['correo'] = 'Ya existe una cuenta con ese correo.';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        /** @var array{id:int|string,nombre:string,requiere_2fa:int|string} $filaRol */
        return $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $correo, $nombre, $filaRol, $centroId, $unidades): int {
            $actor = $solicitud->atributo('usuario');
            $actorId = $actor instanceof UsuarioSesion ? $actor->id : null;
            $id = $bd->insertar(
                "INSERT INTO usuarios (ulid, centro_id, correo, nombre, estado, creado_por) VALUES (:ulid, :centro, :correo, :nombre, 'invitado', :actor)",
                ['ulid' => GeneradorUlid::generar(), 'centro' => $centroId, 'correo' => $correo, 'nombre' => $nombre, 'actor' => $actorId]
            );
            $bd->ejecutar(
                'INSERT INTO usuarios_roles (usuario_id, rol_id, centro_id, asignado_por) VALUES (:usuario, :rol, :centro, :actor)',
                ['usuario' => $id, 'rol' => (int) $filaRol['id'], 'centro' => $centroId, 'actor' => $actorId]
            );
            foreach (array_unique($unidades) as $unidad) {
                $bd->ejecutar(
                    'INSERT INTO usuarios_unidades (usuario_id, unidad_id, asignado_por)
                     SELECT :usuario, id, :actor FROM unidades_productivas WHERE id = :unidad AND centro_id = :centro',
                    ['usuario' => $id, 'actor' => $actorId, 'unidad' => $unidad, 'centro' => (int) $centroId]
                );
            }
            $token = $this->tokens->emitir($id, 'invitacion');
            $this->notificaciones->invitacion($id, $token, (string) $filaRol['nombre'], (bool) (int) $filaRol['requiere_2fa']);
            $this->auditoria->registrar($solicitud, 'cuenta.invitar', 'exito', null, ['usuario_creado' => $id], null, [
                'correo_hmac' => $this->auditoria->hmacPii($correo),
                'rol' => $filaRol['nombre'],
                'centro_id' => $centroId,
                'unidades' => $unidades,
            ]);
            return $id;
        });
    }

    /** @return array<string,mixed>|null usuario invitado del token vigente */
    public function invitacionVigente(string $token): ?array
    {
        $id = $this->tokens->vigente($token, 'invitacion');
        $usuario = $id === null ? null : $this->usuarios->porId($id);
        return is_array($usuario) && $usuario['estado'] === 'invitado' ? $usuario : null;
    }

    /**
     * Activa la invitación: define la contraseña y abre la sesión (nivel contraseña). Si el rol exige 2FA, el
     * controlador lleva al enrolamiento antes de cualquier otra pantalla.
     */
    public function activar(Solicitud $solicitud, Sesion $sesion, string $token, string $contrasena, string $confirmacion): void
    {
        $espera = $this->limites->espera('activacion_ip', 'ip', $solicitud->ip);
        if ($espera > 0) {
            throw new ErrorHttp(429, "Demasiados intentos de activación. Espera {$espera} segundos.");
        }
        $this->limites->registrar('activacion_ip', 'ip', $solicitud->ip);
        $usuario = $this->invitacionVigente($token);
        if ($usuario === null) {
            throw new ErrorHttp(410, 'El enlace de activación venció o ya se usó. Pide una nueva invitación a la coordinación del centro.');
        }
        $this->validarNueva($contrasena, $confirmacion, $usuario, false);
        $id = (int) $usuario['id'];
        if ($this->tokens->consumir($token, 'invitacion') !== $id) {
            throw new ErrorHttp(410, 'El enlace de activación venció o ya se usó.');
        }
        $this->bd->ejecutar(
            "UPDATE usuarios SET hash_contrasena = :hash, estado = 'activo', contrasena_cambiada_en = UTC_TIMESTAMP(3) WHERE id = :id AND estado = 'invitado'",
            ['hash' => $this->contrasenas->hashear($contrasena), 'id' => $id]
        );
        $identidad = $this->usuarios->identidad($id);
        $sesion->iniciarAutenticada($id, 'contrasena', $identidad !== null && $identidad->privilegiado);
        $sesion->guardar('_version_permisos', $identidad->versionPermisos ?? 1);
        $this->auditoria->registrar($solicitud->conAtributo('usuario', $identidad), 'cuenta.activar', 'exito');
    }

    /** Respuesta homogénea exista o no la cuenta (RAC-005); 3 solicitudes por hora por cuenta y por IP. */
    public function solicitarRecuperacion(Solicitud $solicitud, string $correo): void
    {
        $correo = RepositorioUsuarios::normalizarCorreo($correo);
        if ($this->limites->espera('recuperacion', 'ip', $solicitud->ip) > 0 || $this->limites->espera('recuperacion', 'correo', $correo) > 0) {
            $this->auditoria->registrar($solicitud, 'contrasena.recuperar_solicitud', 'denegado', null, ['motivo' => 'limite']);
            return;
        }
        $this->limites->registrar('recuperacion', 'ip', $solicitud->ip);
        $this->limites->registrar('recuperacion', 'correo', $correo);
        $usuario = filter_var($correo, FILTER_VALIDATE_EMAIL) === false ? null : $this->usuarios->porCorreo($correo);
        if (is_array($usuario) && $usuario['estado'] === 'activo') {
            $this->notificaciones->recuperacion((int) $usuario['id'], $this->tokens->emitir((int) $usuario['id'], 'recuperacion'));
        }
        $this->auditoria->registrar($solicitud, 'contrasena.recuperar_solicitud', 'exito', null, ['correo_hmac' => $this->auditoria->hmacPii($correo)]);
    }

    public function recuperacionVigente(string $token): bool
    {
        return $this->tokens->vigente($token, 'recuperacion') !== null;
    }

    /** Restablece la contraseña y cierra todas las sesiones del usuario (RAC-006). */
    public function restablecer(Solicitud $solicitud, string $token, string $contrasena, string $confirmacion): void
    {
        $id = $this->tokens->vigente($token, 'recuperacion');
        $usuario = $id === null ? null : $this->usuarios->porId($id);
        if (!is_array($usuario) || $usuario['estado'] !== 'activo') {
            throw new ErrorHttp(410, 'El enlace de recuperación venció o ya se usó. Solicita uno nuevo.');
        }
        $this->validarNueva($contrasena, $confirmacion, $usuario, $usuario['totp_activado_en'] !== null);
        if ($this->tokens->consumir($token, 'recuperacion') !== (int) $usuario['id']) {
            throw new ErrorHttp(410, 'El enlace de recuperación venció o ya se usó.');
        }
        $this->usuarios->actualizarHash((int) $usuario['id'], $this->contrasenas->hashear($contrasena));
        $this->bd->ejecutar('UPDATE sesiones SET revocada_en = UTC_TIMESTAMP(3) WHERE usuario_id = :id AND revocada_en IS NULL', ['id' => (int) $usuario['id']]);
        $this->auditoria->registrar($solicitud, 'contrasena.restablecer', 'exito', null, ['usuario_afectado' => (int) $usuario['id']]);
        $this->notificaciones->contrasenaCambiada((int) $usuario['id']);
    }

    /** Cambio con la contraseña actual (RAC-008); la sesión se regenera y opcionalmente se cierran las demás. */
    public function cambiarContrasena(Solicitud $solicitud, Sesion $sesion, UsuarioSesion $usuario, string $actual, string $nueva, string $confirmacion, bool $cerrarOtras): void
    {
        $fila = $this->usuarios->porId($usuario->id);
        if (!is_array($fila) || !is_string($fila['hash_contrasena']) || !$this->contrasenas->verificar($actual, $fila['hash_contrasena'])) {
            $this->auditoria->registrar($solicitud, 'contrasena.cambiar', 'denegado');
            throw new ErrorValidacion(['contrasena_actual' => 'La contraseña actual no es correcta.']);
        }
        $this->validarNueva($nueva, $confirmacion, $fila, $usuario->tiene2fa);
        $this->usuarios->actualizarHash($usuario->id, $this->contrasenas->hashear($nueva));
        $sesion->regenerar();
        if ($cerrarOtras) {
            $sesion->revocarOtras($usuario->id);
        }
        $this->auditoria->registrar($solicitud, 'contrasena.cambiar', 'exito', null, ['otras_sesiones_cerradas' => $cerrarOtras]);
        $this->notificaciones->contrasenaCambiada($usuario->id);
    }

    /** @param array<string,mixed> $usuario */
    private function validarNueva(string $contrasena, string $confirmacion, array $usuario, bool $con2fa): void
    {
        $errores = $this->contrasenas->validar($contrasena, ['nombre' => (string) $usuario['nombre'], 'correo' => (string) $usuario['correo']], $con2fa);
        if ($errores !== []) {
            throw new ErrorValidacion(['contrasena' => implode(' ', $errores)]);
        }
        if (!hash_equals(ServicioContrasenas::normalizar($contrasena), ServicioContrasenas::normalizar($confirmacion))) {
            throw new ErrorValidacion(['confirmacion' => 'La confirmación no coincide con la contraseña.']);
        }
    }
}
