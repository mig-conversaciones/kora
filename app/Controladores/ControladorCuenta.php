<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Politicas\UsuarioSesion;
use App\Repositorios\RepositorioUsuarios;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCodigosRespaldo;
use App\Servicios\ServicioCuentas;
use App\Servicios\ServicioSegundoFactor;

/**
 * «Mi cuenta» (RAC-008, RAC-011, RAC-014, RAC-019, RAC-020, RF-008): contraseña, verificación en dos pasos,
 * códigos de respaldo, sesiones activas y preferencias. Cada operación sensible exige reautenticación reciente
 * (opción de ruta «reautenticacion», RAC-026).
 */
final class ControladorCuenta extends ControladorBase
{
    private const PREFERENCIAS = [
        'tema' => ['sistema', 'claro', 'oscuro'],
        'densidad' => ['comoda', 'compacta'],
        'menu' => ['expandido', 'colapsado'],
    ];

    public function __construct(
        Vista $vista,
        private readonly Sesion $sesion,
        private readonly BaseDatos $bd,
        private readonly ServicioCuentas $cuentas,
        private readonly ServicioSegundoFactor $segundoFactor,
        private readonly ServicioCodigosRespaldo $codigos,
        private readonly RepositorioUsuarios $usuarios,
        private readonly ServicioAuditoria $auditoria,
    ) {
        parent::__construct($vista);
    }

    public function mostrar(Solicitud $solicitud): Respuesta
    {
        return $this->renderizar($solicitud, 'cuenta/pagina', 'cuenta/contenido', $this->datosCuenta($solicitud) + [
            'titulo' => 'Mi cuenta',
            'migas' => [['texto' => 'Mi cuenta']],
        ]);
    }

    public function cambiarContrasena(Solicitud $solicitud): Respuesta
    {
        $usuario = $this->usuario($solicitud);
        try {
            $this->cuentas->cambiarContrasena(
                $solicitud,
                $this->sesion,
                $usuario,
                (string) ($solicitud->cuerpo['contrasena_actual'] ?? ''),
                (string) ($solicitud->cuerpo['contrasena'] ?? ''),
                (string) ($solicitud->cuerpo['confirmacion'] ?? ''),
                ($solicitud->cuerpo['cerrar_otras'] ?? '') === '1',
            );
        } catch (ErrorValidacion $error) {
            return $this->respuestaValidacion($solicitud, $error, 'cuenta/formulario_contrasena', [], []);
        }
        $respuesta = $this->renderizar($solicitud, 'cuenta/formulario_contrasena', 'cuenta/formulario_contrasena', ['valores' => [], 'errores' => [], 'exito' => true]);
        return $this->conToast($respuesta, 'success', 'Contraseña actualizada. Te enviamos un aviso por correo.');
    }

    public function regenerarCodigos(Solicitud $solicitud): Respuesta
    {
        $codigos = $this->segundoFactor->regenerarCodigos($solicitud, $this->usuario($solicitud));
        return $this->renderizar($solicitud, 'cuenta/codigos_nuevos', 'cuenta/codigos_nuevos', ['codigos' => $codigos]);
    }

    public function desactivarSegundoFactor(Solicitud $solicitud): Respuesta
    {
        $this->segundoFactor->desactivar($solicitud, $this->sesion, $this->usuario($solicitud));
        return $this->conToast($this->redirigir($solicitud, '/cuenta'), 'success', 'Desactivaste la verificación en dos pasos.');
    }

    public function cerrarOtrasSesiones(Solicitud $solicitud): Respuesta
    {
        $usuario = $this->usuario($solicitud);
        $cerradas = $this->sesion->revocarOtras($usuario->id);
        $this->auditoria->registrar($solicitud, 'sesion.revocar_otras', 'exito', null, ['cerradas' => $cerradas]);
        $respuesta = $this->renderizar($solicitud, 'cuenta/sesiones', 'cuenta/sesiones', ['sesiones' => $this->sesiones($usuario->id)]);
        return $this->conToast($respuesta, 'success', $cerradas === 1 ? 'Se cerró 1 sesión.' : "Se cerraron {$cerradas} sesiones.");
    }

    /** Preferencias de interfaz (RF-008): solo valores de la lista permitida (RS-002, RS-006). */
    public function guardarPreferencias(Solicitud $solicitud): Respuesta
    {
        $usuario = $this->usuario($solicitud);
        $preferencias = $usuario->preferencias;
        foreach (self::PREFERENCIAS as $clave => $permitidos) {
            if (isset($solicitud->cuerpo[$clave])) {
                $valor = (string) $solicitud->cuerpo[$clave];
                if (!in_array($valor, $permitidos, true)) {
                    throw new ErrorHttp(422, 'Preferencia no válida.');
                }
                $preferencias[$clave] = $valor;
            }
        }
        $this->usuarios->guardarPreferencias($usuario->id, $preferencias);
        return new Respuesta('', 204);
    }

    /** Extiende la sesión desde el aviso de expiración (RAC-019, WCAG 2.2.1): la solicitud misma renueva la actividad. */
    public function extenderSesion(Solicitud $solicitud): Respuesta
    {
        return $this->conToast(new Respuesta('', 204), 'info', 'Tu sesión sigue activa.');
    }

    /** @return array<string,mixed> */
    private function datosCuenta(Solicitud $solicitud): array
    {
        $usuario = $this->usuario($solicitud);
        return [
            'cuenta' => $usuario,
            'codigosRestantes' => $usuario->tiene2fa ? $this->codigos->restantes($usuario->id) : 0,
            'sesiones' => $this->sesiones($usuario->id),
            'valores' => [],
            'errores' => [],
        ];
    }

    /** @return list<array<string,mixed>> sesiones activas con agente, IP tratada y última actividad (RAC-020) */
    private function sesiones(int $usuarioId): array
    {
        $filas = $this->bd->consultar(
            'SELECT id, agente, ip_tratada, nivel_autenticacion, creada_en, ultima_actividad_en FROM sesiones
              WHERE usuario_id = :usuario AND revocada_en IS NULL AND expira_absoluta_en > UTC_TIMESTAMP(3)
              ORDER BY ultima_actividad_en DESC LIMIT 20',
            ['usuario' => $usuarioId]
        );
        $actual = $this->sesion->huella();
        return array_map(static fn (array $f): array => $f + ['actual' => $f['id'] === $actual], $filas);
    }

    private function usuario(Solicitud $solicitud): UsuarioSesion
    {
        $usuario = $solicitud->atributo('usuario');
        if (!$usuario instanceof UsuarioSesion) {
            throw new ErrorHttp(401);
        }
        return $usuario;
    }
}
