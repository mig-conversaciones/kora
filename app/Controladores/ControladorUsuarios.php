<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Servicios\AutorizacionRecurso;
use App\Servicios\ServicioCatalogos;
use App\Servicios\ServicioConsultaAuditoria;
use App\Servicios\ServicioPermisos;
use App\Servicios\ServicioUsuarios;

/**
 * Usuarios, roles y auditoría (RF-009 a RF-012, RAU-001). La gestión de usuarios la hacen el Superadministrador y el
 * Administrador; la matriz de roles y permisos la consulta cualquier usuario y solo la modifica el
 * Superadministrador; la auditoría la consulta el Superadministrador.
 */
final class ControladorUsuarios extends ControladorModulo
{
    private const PESTANAS = [
        ['texto' => 'Usuarios', 'ruta' => '/sistema/usuarios', 'permiso' => 'usuarios.gestionar'],
        ['texto' => 'Roles y permisos', 'ruta' => '/sistema/roles'],
        ['texto' => 'Auditoría', 'ruta' => '/sistema/auditoria', 'permiso' => 'auditoria.consultar'],
    ];

    public function __construct(
        Vista $vista,
        private readonly ServicioUsuarios $usuarios,
        private readonly ServicioPermisos $permisos,
        private readonly ServicioConsultaAuditoria $auditoria,
        private readonly ServicioCatalogos $catalogos,
        private readonly Sesion $sesion,
    ) {
        parent::__construct($vista);
    }

    // ---------------------------------------------------------------- Usuarios (RF-009, RF-010, RF-012)

    public function listar(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'sistema/usuarios', $this->datosListado($solicitud), $this->meta('Usuarios', 'Invita, edita, desactiva y reactiva cuentas. Nadie modifica sus propios roles, unidades o estado.'));
    }

    public function formularioInvitacion(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'sistema/usuario_invitar', $this->datosFormulario($solicitud), $this->meta('Invitar usuario'));
    }

    public function invitar(Solicitud $solicitud): Respuesta
    {
        try {
            $this->usuarios->invitar($solicitud, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'sistema/usuario_invitar', $this->datosFormulario($solicitud), $this->meta('Invitar usuario'));
        }
        return $this->trasGuardar($this->pantalla($solicitud, 'sistema/usuarios', $this->datosListado($solicitud), $this->meta('Usuarios')), '/sistema/usuarios', 'Invitación enviada: el enlace vale 72 horas.');
    }

    public function mostrar(Solicitud $solicitud, string $id): Respuesta
    {
        $cuenta = $this->usuarios->obtener($solicitud, (int) $id);
        return $this->pantalla($solicitud, 'sistema/usuario', $this->datosFormulario($solicitud) + ['sesionesActivas' => $this->usuarios->sesionesActivas((int) $id), 'cuenta' => $cuenta, 'valores' => ['nombre' => $cuenta['nombre'], 'rol' => $cuenta['roles'][0] ?? '', 'unidades' => $cuenta['unidades']]], $this->meta($cuenta['nombre']));
    }

    public function actualizarAcceso(Solicitud $solicitud, string $id): Respuesta
    {
        try {
            $this->usuarios->actualizarAcceso($solicitud, (int) $id, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'sistema/usuario', $this->datosFormulario($solicitud) + ['cuenta' => $this->usuarios->obtener($solicitud, (int) $id)], $this->meta('Usuario'));
        }
        return $this->detalleActualizado($solicitud, (int) $id, 'Acceso actualizado: se aplica en la siguiente solicitud de la persona.');
    }

    public function cambiarEstado(Solicitud $solicitud, string $id, string $accion): Respuesta
    {
        if (!in_array($accion, ['desactivar', 'reactivar'], true)) {
            throw new ErrorHttp(404);
        }
        try {
            $this->usuarios->cambiarEstado($solicitud, (int) $id, $accion === 'reactivar', (string) ($solicitud->cuerpo['motivo'] ?? ''));
        } catch (ErrorValidacion $error) {
            $cuenta = $this->usuarios->obtener($solicitud, (int) $id);
            return $this->validacion($solicitud, $error, 'sistema/usuario', $this->datosFormulario($solicitud) + ['cuenta' => $cuenta, 'valores' => ['nombre' => $cuenta['nombre'], 'rol' => $cuenta['roles'][0] ?? '', 'unidades' => $cuenta['unidades']]], $this->meta('Usuario'));
        }
        return $this->detalleActualizado($solicitud, (int) $id, $accion === 'desactivar' ? 'Cuenta desactivada: sus sesiones se cerraron.' : 'Cuenta reactivada.');
    }

    /** Cierra las sesiones de una cuenta sin desactivarla (ASVS V7.4.5). */
    public function cerrarSesiones(Solicitud $solicitud, string $id): Respuesta
    {
        try {
            $cerradas = $this->usuarios->cerrarSesiones($solicitud, (int) $id, (string) ($solicitud->cuerpo['motivo'] ?? ''));
        } catch (ErrorValidacion $error) {
            $cuenta = $this->usuarios->obtener($solicitud, (int) $id);
            return $this->validacion($solicitud, $error, 'sistema/usuario', $this->datosFormulario($solicitud) + ['sesionesActivas' => $this->usuarios->sesionesActivas((int) $id), 'cuenta' => $cuenta, 'valores' => ['nombre' => $cuenta['nombre'], 'rol' => $cuenta['roles'][0] ?? '', 'unidades' => $cuenta['unidades']]], $this->meta('Usuario'));
        }
        return $this->detalleActualizado($solicitud, (int) $id, $cerradas === 1 ? 'Se cerró 1 sesión.' : "Se cerraron {$cerradas} sesiones.");
    }

    /** Cierra las sesiones de todos los usuarios menos la propia: solo el superadministrador (ASVS V7.4.5). */
    public function cerrarTodasLasSesiones(Solicitud $solicitud): Respuesta
    {
        try {
            $cerradas = $this->usuarios->cerrarTodasLasSesiones($solicitud, $this->sesion->huella(), (string) ($solicitud->cuerpo['motivo'] ?? ''));
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'sistema/usuarios', $this->datosListado($solicitud), $this->meta('Usuarios'));
        }
        return $this->trasGuardar($this->listar($solicitud), '/sistema/usuarios', "Se cerraron {$cerradas} sesiones; la tuya sigue abierta.");
    }

    public function reenviarInvitacion(Solicitud $solicitud, string $id): Respuesta
    {
        $this->usuarios->reenviarInvitacion($solicitud, (int) $id);
        return $this->detalleActualizado($solicitud, (int) $id, 'Invitación reenviada; el enlace anterior dejó de valer.');
    }

    // ---------------------------------------------------------------- Roles y permisos (RF-011)

    public function roles(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'sistema/roles', $this->datosRoles($solicitud), $this->meta('Roles y permisos', 'Matriz de autorización en formato recurso.acción. Solo el superadministrador la modifica, con reautenticación.'));
    }

    public function cambiarPermiso(Solicitud $solicitud, string $rol, string $permiso, string $accion): Respuesta
    {
        if (!in_array($accion, ['conceder', 'retirar'], true)) {
            throw new ErrorHttp(404);
        }
        $this->permisos->cambiar($solicitud, (int) $rol, (int) $permiso, $accion === 'conceder');
        return $this->conToast($this->pantalla($solicitud, 'sistema/roles', $this->datosRoles($solicitud), $this->meta('Roles y permisos')), 'success', $accion === 'conceder' ? 'Permiso concedido.' : 'Permiso retirado.');
    }

    // ---------------------------------------------------------------- Auditoría (RAU-001)

    public function auditoria(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'sistema/auditoria', $this->auditoria->buscar($solicitud->consulta) + ['sellado' => $this->auditoria->estadoSellado()], $this->meta('Auditoría', 'Eventos de seguridad y de negocio, sellados en cadena. Solo lectura.'));
    }

    // ---------------------------------------------------------------- Datos

    private function detalleActualizado(Solicitud $solicitud, int $id, string $mensaje): Respuesta
    {
        $cuenta = $this->usuarios->obtener($solicitud, $id);
        $respuesta = $this->pantalla($solicitud, 'sistema/usuario', $this->datosFormulario($solicitud) + ['sesionesActivas' => $this->usuarios->sesionesActivas($id), 'cuenta' => $cuenta, 'valores' => ['nombre' => $cuenta['nombre'], 'rol' => $cuenta['roles'][0] ?? '', 'unidades' => $cuenta['unidades']]], $this->meta($cuenta['nombre']));
        return $this->conToast($respuesta, 'success', $mensaje);
    }

    /** @return array<string,mixed> */
    private function datosListado(Solicitud $solicitud): array
    {
        $filtros = [
            'buscar' => mb_substr(trim((string) ($solicitud->consulta['buscar'] ?? '')), 0, 100),
            'estado' => (string) ($solicitud->consulta['estado'] ?? ''),
            'rol' => preg_replace('/[^a-z_]/', '', (string) ($solicitud->consulta['rol'] ?? '')) ?? '',
        ];
        $pagina = $this->enteroConsulta($solicitud, 'pagina', 1) ?? 1;
        return $this->usuarios->listar($solicitud, $filtros, $pagina) + ['filtros' => $filtros, 'pagina' => $pagina, 'roles' => $this->usuarios->rolesAsignables($solicitud), 'actorId' => AutorizacionRecurso::usuario($solicitud)->id, 'esSuperadministrador' => AutorizacionRecurso::usuario($solicitud)->esSuperadministrador()];
    }

    /** @return array<string,mixed> */
    private function datosFormulario(Solicitud $solicitud): array
    {
        $actor = AutorizacionRecurso::usuario($solicitud);
        // El superadministrador no tiene centro: invita al único centro de la v1 (S-19).
        $centro = $actor->centroId ?? $this->catalogos->centroPrincipal();
        $unidades = array_values(array_filter($this->catalogos->unidades($centro, true), static fn (array $u): bool => $u['codigo'] !== 'SIN'));
        return ['roles' => $this->usuarios->rolesAsignables($solicitud), 'unidades' => $unidades];
    }

    /** @return array<string,mixed> */
    private function datosRoles(Solicitud $solicitud): array
    {
        $usuario = AutorizacionRecurso::usuario($solicitud);
        return $this->permisos->matriz() + ['puedeEditar' => $usuario->esSuperadministrador() && $usuario->tienePermiso('roles.gestionar')];
    }

    /** @return array<string,mixed> */
    private function meta(string $titulo, string $descripcion = ''): array
    {
        return [
            'titulo' => $titulo,
            'sobretitulo' => 'Sistema',
            'descripcion' => $descripcion,
            'pestanas' => self::PESTANAS,
            'migas' => [['texto' => 'Sistema'], ['texto' => $titulo]],
        ];
    }
}
