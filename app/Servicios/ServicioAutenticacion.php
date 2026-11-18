<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Cifrador;
use App\Nucleo\Sesion;
use App\Nucleo\Solicitud;
use App\Politicas\UsuarioSesion;
use App\Repositorios\RepositorioUsuarios;

/**
 * Inicio de sesión, segundo factor, reautenticación y cierre (FP-01; RAC-001 a RAC-005, RAC-009, RAC-011 a
 * RAC-013, RAC-018, RAC-021, RAC-026, RAC-029).
 *
 * - Mensajes genéricos y tiempo homogéneo: con un correo inexistente se verifica igual un hash ficticio (RAC-005).
 * - Límites por cuenta (5 en 15 min, espera progresiva) y por IP (20 en 15 min); OTP: 5 en 5 min por cuenta.
 * - La sesión se regenera al autenticarse y al completar la 2FA; el nivel queda en la sesión del servidor.
 */
final class ServicioAutenticacion
{
    public const MENSAJE_GENERICO = 'Correo o contraseña incorrectos.';
    public const MENSAJE_CODIGO = 'El código no es válido o ya se usó. Revisa la hora de tu teléfono e intenta con el siguiente.';

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly RepositorioUsuarios $usuarios,
        private readonly ServicioContrasenas $contrasenas,
        private readonly ServicioTotp $totp,
        private readonly ServicioCodigosRespaldo $codigos,
        private readonly ServicioLimiteTasa $limites,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioNotificacionesCuenta $notificaciones,
        private readonly Cifrador $cifrador,
    ) {
    }

    /**
     * @return array{estado:'ok'|'segundo_factor'|'enrolar'|'rechazado'|'bloqueado',mensaje:string,espera?:int}
     */
    public function ingresar(Solicitud $solicitud, Sesion $sesion, string $correo, string $contrasena): array
    {
        $correo = RepositorioUsuarios::normalizarCorreo($correo);
        $espera = max($this->limites->espera('login_ip', 'ip', $solicitud->ip), $this->limites->espera('login_cuenta', 'correo', $correo));
        if ($espera > 0) {
            $this->auditoria->registrar($solicitud, 'sesion.ingresar', 'denegado', null, ['motivo' => 'limite', 'correo_hmac' => $this->auditoria->hmacPii($correo)]);
            return ['estado' => 'bloqueado', 'mensaje' => "Demasiados intentos. Espera {$espera} segundos antes de volver a intentarlo.", 'espera' => $espera];
        }
        $fila = $correo === '' || mb_strlen($correo) > 190 ? null : $this->usuarios->porCorreo($correo);
        $hash = is_array($fila) && is_string($fila['hash_contrasena']) ? $fila['hash_contrasena'] : $this->contrasenas->hashFicticio();
        // La verificación del hash se hace siempre, exista o no la cuenta: tiempo homogéneo (RAC-005).
        $coincide = $this->contrasenas->verificar($contrasena, $hash);
        $this->limites->registrar('login_ip', 'ip', $solicitud->ip);
        if (!$coincide || $fila === null || $fila['estado'] !== 'activo') {
            $this->limites->registrar('login_cuenta', 'correo', $correo, true);
            $this->auditoria->registrar($solicitud, 'sesion.ingresar', 'denegado', null, ['correo_hmac' => $this->auditoria->hmacPii($correo)]);
            return ['estado' => 'rechazado', 'mensaje' => self::MENSAJE_GENERICO];
        }
        $this->limites->reiniciar('login_cuenta', 'correo', $correo);
        $id = (int) $fila['id'];
        if ($this->contrasenas->necesitaRehash($hash)) {
            $this->usuarios->actualizarHash($id, $this->contrasenas->hashear($contrasena));
        }
        $identidad = $this->usuarios->identidad($id);
        if ($identidad === null) {
            return ['estado' => 'rechazado', 'mensaje' => self::MENSAJE_GENERICO];
        }
        $sesion->iniciarAutenticada($id, 'contrasena', $identidad->privilegiado);
        $sesion->guardar('_version_permisos', $identidad->versionPermisos);
        $conUsuario = $solicitud->conAtributo('usuario', $identidad);
        if ($identidad->exigeSegundoFactor()) {
            $this->auditoria->registrar($conUsuario, 'sesion.ingresar', 'exito', null, ['nivel' => 'contrasena', 'pendiente' => $identidad->tiene2fa ? '2fa' : 'enrolamiento']);
            return $identidad->tiene2fa
                ? ['estado' => 'segundo_factor', 'mensaje' => 'Escribe el código de tu aplicación de autenticación.']
                : ['estado' => 'enrolar', 'mensaje' => 'Tu rol exige la verificación en dos pasos. Configúrala para continuar.'];
        }
        $this->usuarios->registrarAcceso($id);
        $this->auditoria->registrar($conUsuario, 'sesion.ingresar', 'exito', null, ['nivel' => 'contrasena']);
        return ['estado' => 'ok', 'mensaje' => ''];
    }

    /**
     * Segundo paso del inicio de sesión: TOTP o código de respaldo (RAC-009, RAC-011 a RAC-013).
     *
     * @return array{estado:'ok'|'rechazado'|'bloqueado',mensaje:string}
     */
    public function verificarSegundoFactor(Solicitud $solicitud, Sesion $sesion, string $codigo): array
    {
        $usuarioId = $sesion->usuarioId();
        $identidad = $usuarioId === null ? null : $this->usuarios->identidad($usuarioId);
        if ($identidad === null || $sesion->nivel() !== 'contrasena' || !$identidad->tiene2fa) {
            return ['estado' => 'rechazado', 'mensaje' => 'La sesión venció. Ingresa de nuevo.'];
        }
        $conUsuario = $solicitud->conAtributo('usuario', $identidad);
        if (!$this->verificarCodigo($conUsuario, $identidad, $codigo)['valido']) {
            return $this->limites->espera('otp', 'usuario', (string) $identidad->id) > 0
                ? ['estado' => 'bloqueado', 'mensaje' => 'Demasiados códigos incorrectos. Espera 15 minutos o usa un código de respaldo más tarde.']
                : ['estado' => 'rechazado', 'mensaje' => self::MENSAJE_CODIGO];
        }
        $sesion->elevar('2fa');
        $this->usuarios->registrarAcceso($identidad->id);
        $this->auditoria->registrar($conUsuario, 'sesion.2fa', 'exito');
        return ['estado' => 'ok', 'mensaje' => ''];
    }

    /**
     * Reautenticación para operaciones sensibles (RAC-026): con 2FA activa exige un código; si no, la contraseña.
     */
    public function reautenticar(Solicitud $solicitud, Sesion $sesion, UsuarioSesion $usuario, string $secreto): bool
    {
        if ($usuario->tiene2fa) {
            $valido = $this->verificarCodigo($solicitud, $usuario, $secreto)['valido'];
        } else {
            $fila = $this->usuarios->porId($usuario->id);
            $valido = is_array($fila) && is_string($fila['hash_contrasena']) && $this->contrasenas->verificar($secreto, $fila['hash_contrasena']);
            if (!$valido) {
                $this->limites->registrar('login_cuenta', 'correo', $usuario->correo, true);
            }
        }
        $this->auditoria->registrar($solicitud, 'sesion.reautenticar', $valido ? 'exito' : 'denegado');
        if ($valido) {
            $sesion->marcarReautenticada();
        }
        return $valido;
    }

    public function cerrarSesion(Solicitud $solicitud, Sesion $sesion): void
    {
        if ($sesion->usuarioId() !== null) {
            $this->auditoria->registrar($solicitud, 'sesion.cerrar', 'exito');
        }
        $sesion->cerrar();
    }

    /**
     * Verifica un TOTP (con anti-reutilización) o un código de respaldo, con el límite de OTP por cuenta.
     *
     * @return array{valido:bool,respaldo:bool}
     */
    public function verificarCodigo(Solicitud $solicitud, UsuarioSesion $usuario, string $codigo): array
    {
        if ($this->limites->espera('otp', 'usuario', (string) $usuario->id) > 0) {
            $this->auditoria->registrar($solicitud, 'sesion.2fa', 'denegado', null, ['motivo' => 'limite']);
            return ['valido' => false, 'respaldo' => false];
        }
        $codigo = trim($codigo);
        $fila = $this->usuarios->porId($usuario->id);
        $valido = false;
        $respaldo = false;
        if (is_array($fila) && is_string($fila['totp_secreto_cifrado'])) {
            if (preg_match('/^\d{6}$/', $codigo) === 1) {
                $semilla = $this->cifrador->descifrar($fila['totp_secreto_cifrado'], 'usuarios.totp:' . $usuario->id);
                $ultimo = $fila['totp_ultimo_paso'] === null ? null : (int) $fila['totp_ultimo_paso'];
                $paso = $this->totp->verificar($semilla, $codigo, $ultimo);
                // Actualización condicional: dos solicitudes con el mismo código no pueden aceptarse ambas (RAC-012).
                $valido = $paso !== null && $this->bd->ejecutar(
                    'UPDATE usuarios SET totp_ultimo_paso = :paso WHERE id = :id AND (totp_ultimo_paso IS NULL OR totp_ultimo_paso < :paso_minimo)',
                    ['paso' => $paso, 'id' => $usuario->id, 'paso_minimo' => $paso]
                ) === 1;
            } elseif (ServicioCodigosRespaldo::pareceCodigo($codigo)) {
                $valido = $this->codigos->usar($usuario->id, $codigo);
                $respaldo = $valido;
            }
        }
        if (!$valido) {
            $this->limites->registrar('otp', 'usuario', (string) $usuario->id);
            $this->auditoria->registrar($solicitud, 'sesion.2fa', 'denegado');
        } else {
            $this->limites->reiniciar('otp', 'usuario', (string) $usuario->id);
            if ($respaldo) {
                $restantes = $this->codigos->restantes($usuario->id);
                $this->auditoria->registrar($solicitud, '2fa.codigo_respaldo_usado', 'exito', null, ['restantes' => $restantes]);
                $this->notificaciones->segundoFactor($usuario->id, 'uso de un código de respaldo', $restantes);
            }
        }
        return ['valido' => $valido, 'respaldo' => $respaldo];
    }
}
