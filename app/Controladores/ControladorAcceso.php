<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Politicas\UsuarioSesion;
use App\Repositorios\RepositorioUsuarios;
use App\Servicios\ServicioAutenticacion;
use App\Servicios\ServicioCuentas;
use App\Servicios\ServicioSegundoFactor;

/**
 * Acceso (FP-01; RAC-001 a RAC-013, RAC-026): ingreso, segundo factor, enrolamiento, reautenticación, salida,
 * activación de invitaciones y recuperación. Formularios HTML clásicos (sin HTMX) con campo _csrf, para que el
 * acceso funcione aunque falle el JavaScript. Los mensajes de error son genéricos (RAC-005).
 */
final class ControladorAcceso extends ControladorBase
{
    public function __construct(
        Vista $vista,
        private readonly Sesion $sesion,
        private readonly ServicioAutenticacion $autenticacion,
        private readonly ServicioSegundoFactor $segundoFactor,
        private readonly ServicioCuentas $cuentas,
        private readonly RepositorioUsuarios $usuarios,
    ) {
        parent::__construct($vista);
    }

    public function formularioIngreso(Solicitud $solicitud): Respuesta
    {
        if ($solicitud->atributo('usuario') instanceof UsuarioSesion) {
            return $this->redirigir($solicitud, $this->volver($solicitud));
        }
        $avisos = ['salida' => 'Cerraste tu sesión.', 'restablecida' => 'Tu contraseña cambió. Ingresa con la nueva.'];
        return $this->pantalla($solicitud, 'acceso/ingresar', 'Ingresar', [
            'volver' => $this->volver($solicitud, ''),
            'aviso' => $avisos[(string) ($solicitud->consulta['aviso'] ?? '')] ?? null,
        ]);
    }

    public function ingresar(Solicitud $solicitud): Respuesta
    {
        $correo = (string) ($solicitud->cuerpo['correo'] ?? '');
        $resultado = $this->autenticacion->ingresar($solicitud, $this->sesion, $correo, (string) ($solicitud->cuerpo['contrasena'] ?? ''));
        $volver = $this->volver($solicitud, '');
        return match ($resultado['estado']) {
            'ok' => $this->redirigir($solicitud, $volver === '' ? '/' : $volver),
            'segundo_factor' => $this->redirigir($solicitud, '/2fa' . ($volver !== '' ? '?volver=' . rawurlencode($volver) : '')),
            'enrolar' => $this->redirigir($solicitud, '/2fa/enrolar'),
            default => $this->pantalla($solicitud, 'acceso/ingresar', 'Ingresar', [
                'error' => $resultado['mensaje'],
                'correo' => $correo,
                'volver' => $volver,
            ], $resultado['estado'] === 'bloqueado' ? 429 : 422),
        };
    }

    public function formularioSegundoFactor(Solicitud $solicitud): Respuesta
    {
        $pendiente = $solicitud->atributo('usuario_pendiente');
        if (!$pendiente instanceof UsuarioSesion || !$pendiente->tiene2fa) {
            return $this->redirigir($solicitud, $solicitud->atributo('usuario') instanceof UsuarioSesion ? '/' : '/ingresar');
        }
        return $this->pantalla($solicitud, 'acceso/segundo_factor', 'Verificación en dos pasos', ['volver' => $this->volver($solicitud, '')]);
    }

    public function verificarSegundoFactor(Solicitud $solicitud): Respuesta
    {
        $resultado = $this->autenticacion->verificarSegundoFactor($solicitud, $this->sesion, (string) ($solicitud->cuerpo['codigo'] ?? ''));
        if ($resultado['estado'] === 'ok') {
            $volver = $this->volver($solicitud, '');
            return $this->redirigir($solicitud, $volver === '' ? '/' : $volver);
        }
        if ($this->sesion->usuarioId() === null) {
            return $this->redirigir($solicitud, '/ingresar');
        }
        return $this->pantalla($solicitud, 'acceso/segundo_factor', 'Verificación en dos pasos', [
            'error' => $resultado['mensaje'],
            'volver' => $this->volver($solicitud, ''),
        ], $resultado['estado'] === 'bloqueado' ? 429 : 422);
    }

    /**
     * Enrolamiento de la 2FA: obligatorio tras el primer ingreso de un rol que la exige (usuario pendiente) o
     * voluntario desde «Mi cuenta», con reautenticación reciente (RAC-026).
     */
    public function formularioEnrolamiento(Solicitud $solicitud): Respuesta
    {
        $usuario = $this->usuarioQueEnrola($solicitud);
        if ($usuario instanceof Respuesta) {
            return $usuario;
        }
        $datos = $this->segundoFactor->iniciarEnrolamiento($this->sesion, $usuario->id, $usuario->correo);
        return $this->pantalla($solicitud, 'acceso/enrolar', 'Configura la verificación en dos pasos', $datos + ['obligatorio' => $usuario->requiere2fa]);
    }

    public function confirmarEnrolamiento(Solicitud $solicitud): Respuesta
    {
        $usuario = $this->usuarioQueEnrola($solicitud);
        if ($usuario instanceof Respuesta) {
            return $usuario;
        }
        $codigos = $this->segundoFactor->confirmarEnrolamiento($solicitud->conAtributo('usuario', $usuario), $this->sesion, $usuario->id, (string) ($solicitud->cuerpo['codigo'] ?? ''));
        if ($codigos === null) {
            $datos = $this->segundoFactor->iniciarEnrolamiento($this->sesion, $usuario->id, $usuario->correo);
            return $this->pantalla($solicitud, 'acceso/enrolar', 'Configura la verificación en dos pasos', $datos + [
                'obligatorio' => $usuario->requiere2fa,
                'error' => 'El código no coincide. Revisa que la hora del teléfono sea automática y escribe el código actual.',
            ], 422);
        }
        $this->usuarios->registrarAcceso($usuario->id);
        return $this->pantalla($solicitud, 'acceso/codigos_respaldo', 'Guarda tus códigos de respaldo', ['codigos' => $codigos, 'continuar' => '/']);
    }

    public function formularioReautenticacion(Solicitud $solicitud): Respuesta
    {
        $usuario = $solicitud->atributo('usuario');
        if (!$usuario instanceof UsuarioSesion) {
            return $this->redirigir($solicitud, '/ingresar');
        }
        return $this->pantalla($solicitud, 'acceso/reautenticar', 'Confirma tu identidad', ['con2fa' => $usuario->tiene2fa, 'volver' => $this->volver($solicitud, '')]);
    }

    public function reautenticar(Solicitud $solicitud): Respuesta
    {
        $usuario = $solicitud->atributo('usuario');
        if (!$usuario instanceof UsuarioSesion) {
            return $this->redirigir($solicitud, '/ingresar');
        }
        if ($this->autenticacion->reautenticar($solicitud, $this->sesion, $usuario, (string) ($solicitud->cuerpo['secreto'] ?? ''))) {
            $volver = $this->volver($solicitud, '');
            return $this->redirigir($solicitud, $volver === '' ? '/' : $volver);
        }
        return $this->pantalla($solicitud, 'acceso/reautenticar', 'Confirma tu identidad', [
            'con2fa' => $usuario->tiene2fa,
            'volver' => $this->volver($solicitud, ''),
            'error' => $usuario->tiene2fa ? ServicioAutenticacion::MENSAJE_CODIGO : 'La contraseña no es correcta.',
        ], 422);
    }

    public function salir(Solicitud $solicitud): Respuesta
    {
        $this->autenticacion->cerrarSesion($solicitud, $this->sesion);
        // ASVS V14.3.1: al salir, el navegador descarta la caché y el almacenamiento del sitio (preferencias de la barra
        // lateral incluidas). No se usa "cookies": la cookie de sesión ya se invalidó en el servidor y se borra aparte.
        return $this->redirigir($solicitud, '/ingresar?aviso=salida')->conCabecera('Clear-Site-Data', '"cache", "storage"');
    }

    public function formularioActivacion(Solicitud $solicitud): Respuesta
    {
        $token = (string) ($solicitud->consulta['token'] ?? '');
        $usuario = $this->cuentas->invitacionVigente($token);
        if ($usuario === null) {
            return $this->pantalla($solicitud, 'acceso/enlace_vencido', 'Enlace no disponible', ['tipo' => 'invitacion'], 410);
        }
        return $this->pantalla($solicitud, 'acceso/activar', 'Activa tu cuenta', ['token' => $token, 'nombre' => $usuario['nombre'], 'correo' => $usuario['correo']]);
    }

    public function activar(Solicitud $solicitud): Respuesta
    {
        $token = (string) ($solicitud->cuerpo['token'] ?? '');
        try {
            $this->cuentas->activar($solicitud, $this->sesion, $token, (string) ($solicitud->cuerpo['contrasena'] ?? ''), (string) ($solicitud->cuerpo['confirmacion'] ?? ''));
        } catch (ErrorValidacion $error) {
            $usuario = $this->cuentas->invitacionVigente($token);
            return $this->pantalla($solicitud, 'acceso/activar', 'Activa tu cuenta', [
                'token' => $token,
                'nombre' => $usuario['nombre'] ?? '',
                'correo' => $usuario['correo'] ?? '',
                'errores' => $error->errores(),
            ], 422);
        } catch (ErrorHttp $error) {
            return $this->pantalla($solicitud, 'acceso/enlace_vencido', 'Enlace no disponible', ['tipo' => 'invitacion', 'mensaje' => $error->getMessage()], $error->estado);
        }
        $identidad = $this->usuarios->identidad((int) $this->sesion->usuarioId());
        return $this->redirigir($solicitud, $identidad !== null && $identidad->requiere2fa ? '/2fa/enrolar' : '/');
    }

    public function formularioRecuperacion(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'acceso/recuperar', 'Recupera tu contraseña', []);
    }

    public function solicitarRecuperacion(Solicitud $solicitud): Respuesta
    {
        $this->cuentas->solicitarRecuperacion($solicitud, (string) ($solicitud->cuerpo['correo'] ?? ''));
        return $this->pantalla($solicitud, 'acceso/recuperar', 'Recupera tu contraseña', ['enviado' => true]);
    }

    public function formularioRestablecimiento(Solicitud $solicitud): Respuesta
    {
        $token = (string) ($solicitud->consulta['token'] ?? '');
        if (!$this->cuentas->recuperacionVigente($token)) {
            return $this->pantalla($solicitud, 'acceso/enlace_vencido', 'Enlace no disponible', ['tipo' => 'recuperacion'], 410);
        }
        return $this->pantalla($solicitud, 'acceso/restablecer', 'Define una contraseña nueva', ['token' => $token]);
    }

    public function restablecer(Solicitud $solicitud): Respuesta
    {
        $token = (string) ($solicitud->cuerpo['token'] ?? '');
        try {
            $this->cuentas->restablecer($solicitud, $token, (string) ($solicitud->cuerpo['contrasena'] ?? ''), (string) ($solicitud->cuerpo['confirmacion'] ?? ''));
        } catch (ErrorValidacion $error) {
            return $this->pantalla($solicitud, 'acceso/restablecer', 'Define una contraseña nueva', ['token' => $token, 'errores' => $error->errores()], 422);
        } catch (ErrorHttp $error) {
            return $this->pantalla($solicitud, 'acceso/enlace_vencido', 'Enlace no disponible', ['tipo' => 'recuperacion', 'mensaje' => $error->getMessage()], $error->estado);
        }
        $this->sesion->cerrar();
        return $this->redirigir($solicitud, '/ingresar?aviso=restablecida');
    }

    /** @return UsuarioSesion|Respuesta */
    private function usuarioQueEnrola(Solicitud $solicitud): UsuarioSesion|Respuesta
    {
        $pendiente = $solicitud->atributo('usuario_pendiente');
        if ($pendiente instanceof UsuarioSesion && !$pendiente->tiene2fa) {
            return $pendiente;
        }
        $usuario = $solicitud->atributo('usuario');
        if (!$usuario instanceof UsuarioSesion) {
            return $this->redirigir($solicitud, '/ingresar');
        }
        if ($usuario->tiene2fa) {
            return $this->redirigir($solicitud, '/cuenta');
        }
        $segundos = $this->sesion->segundosDesdeReautenticacion();
        if ($segundos === null || $segundos > 300) {
            return $this->redirigir($solicitud, '/reautenticar?volver=' . rawurlencode('/2fa/enrolar'));
        }
        return $usuario;
    }

    /** @param array<string,mixed> $datos */
    private function pantalla(Solicitud $solicitud, string $vista, string $titulo, array $datos, int $estado = 200): Respuesta
    {
        $html = $this->vista->pagina($vista, $vista, $datos + ['solicitud' => $solicitud, 'titulo' => $titulo, 'diseno' => 'acceso']);
        return Respuesta::html($html, $estado)->conCabecera('Cache-Control', 'no-store');
    }
}
