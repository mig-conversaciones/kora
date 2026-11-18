<?php

declare(strict_types=1);

use App\Controladores\ControladorAcceso;
use App\Controladores\ControladorColas;
use App\Controladores\ControladorCsp;
use App\Controladores\ControladorCuenta;
use App\Controladores\ControladorInicio;
use App\Controladores\ControladorMantenimiento;
use App\Controladores\ControladorSalud;
use App\Controladores\ControladorSistemaDiseno;
use App\Controladores\ControladorTrabajos;
use App\Nucleo\Enrutador;

/**
 * Registro de rutas. Cada ruta declara el permiso que verifica su política (RAC-024). El enrutador rechaza al
 * arrancar cualquier ruta sin permiso que no se declare «publica» o «autenticada» (denegación por defecto, RAC-023).
 *
 * Opciones: publica, autenticada (cualquier usuario con sesión completa, para su propia cuenta), reautenticacion
 * (2FA o contraseña en los últimos 5 min, RAC-026), exceptuadaMantenimiento, limite (RAC-029), funcionalidad,
 * sinCsrf (solo API firmada).
 */
return static function (Enrutador $r): void {
    $r->agregar('GET', '/', [ControladorInicio::class, 'mostrar'], '', ['publica' => true]);

    // Acceso (FP-01): formularios clásicos con _csrf.
    $r->agregar('GET', '/ingresar', [ControladorAcceso::class, 'formularioIngreso'], '', ['publica' => true]);
    $r->agregar('POST', '/ingresar', [ControladorAcceso::class, 'ingresar'], '', ['publica' => true]);
    $r->agregar('GET', '/2fa', [ControladorAcceso::class, 'formularioSegundoFactor'], '', ['publica' => true]);
    $r->agregar('POST', '/2fa', [ControladorAcceso::class, 'verificarSegundoFactor'], '', ['publica' => true]);
    $r->agregar('GET', '/2fa/enrolar', [ControladorAcceso::class, 'formularioEnrolamiento'], '', ['publica' => true]);
    $r->agregar('POST', '/2fa/enrolar', [ControladorAcceso::class, 'confirmarEnrolamiento'], '', ['publica' => true]);
    $r->agregar('GET', '/reautenticar', [ControladorAcceso::class, 'formularioReautenticacion'], '', ['autenticada' => true]);
    $r->agregar('POST', '/reautenticar', [ControladorAcceso::class, 'reautenticar'], '', ['autenticada' => true]);
    $r->agregar('POST', '/salir', [ControladorAcceso::class, 'salir'], '', ['publica' => true]);
    $r->agregar('GET', '/cuenta/activar', [ControladorAcceso::class, 'formularioActivacion'], '', ['publica' => true]);
    $r->agregar('POST', '/cuenta/activar', [ControladorAcceso::class, 'activar'], '', ['publica' => true]);
    $r->agregar('GET', '/cuenta/recuperar', [ControladorAcceso::class, 'formularioRecuperacion'], '', ['publica' => true]);
    $r->agregar('POST', '/cuenta/recuperar', [ControladorAcceso::class, 'solicitarRecuperacion'], '', ['publica' => true]);
    $r->agregar('GET', '/cuenta/restablecer', [ControladorAcceso::class, 'formularioRestablecimiento'], '', ['publica' => true]);
    $r->agregar('POST', '/cuenta/restablecer', [ControladorAcceso::class, 'restablecer'], '', ['publica' => true]);

    // Mi cuenta (RAC-008, RAC-011, RAC-014, RAC-020, RF-008).
    $r->agregar('GET', '/cuenta', [ControladorCuenta::class, 'mostrar'], '', ['autenticada' => true]);
    $r->agregar('POST', '/cuenta/contrasena', [ControladorCuenta::class, 'cambiarContrasena'], '', ['autenticada' => true]);
    $r->agregar('POST', '/cuenta/2fa/codigos', [ControladorCuenta::class, 'regenerarCodigos'], '', ['autenticada' => true, 'reautenticacion' => true]);
    $r->agregar('POST', '/cuenta/2fa/desactivar', [ControladorCuenta::class, 'desactivarSegundoFactor'], '', ['autenticada' => true, 'reautenticacion' => true]);
    $r->agregar('POST', '/cuenta/sesiones/cerrar-otras', [ControladorCuenta::class, 'cerrarOtrasSesiones'], '', ['autenticada' => true]);
    $r->agregar('POST', '/preferencias', [ControladorCuenta::class, 'guardarPreferencias'], '', ['autenticada' => true]);
    $r->agregar('POST', '/sesion/extender', [ControladorCuenta::class, 'extenderSesion'], '', ['autenticada' => true]);

    // Sistema (Superadministrador).
    $r->agregar('GET', '/sistema/mantenimiento', [ControladorMantenimiento::class, 'mostrar'], 'mantenimiento.gestionar', ['exceptuadaMantenimiento' => true]);
    $r->agregar('POST', '/sistema/mantenimiento/activar', [ControladorMantenimiento::class, 'activar'], 'mantenimiento.gestionar', ['exceptuadaMantenimiento' => true, 'reautenticacion' => true]);
    $r->agregar('POST', '/sistema/mantenimiento/desactivar', [ControladorMantenimiento::class, 'desactivar'], 'mantenimiento.gestionar', ['exceptuadaMantenimiento' => true, 'reautenticacion' => true]);
    $r->agregar('GET', '/sistema/colas', [ControladorColas::class, 'mostrar'], 'sistema.colas');
    $r->agregar('POST', '/sistema/colas/{ulid}/{accion}', [ControladorColas::class, 'operar'], 'sistema.colas', ['limite' => 'administracion']);

    // Vitrina del sistema de diseño (hito M1). La demostración de la cola solo responde fuera de producción.
    $r->agregar('GET', '/sistema-diseno', [ControladorSistemaDiseno::class, 'mostrar'], '', ['publica' => true]);
    $r->agregar('GET', '/sistema-diseno/demostracion/{caso}', [ControladorSistemaDiseno::class, 'demostracion'], '', ['publica' => true]);
    $r->agregar('POST', '/sistema-diseno/demostracion/trabajo', [ControladorSistemaDiseno::class, 'encolarTrabajo'], '', ['publica' => true]);
    $r->agregar('GET', '/sistema-diseno/demostracion/trabajos/{ulid}', [ControladorSistemaDiseno::class, 'estadoTrabajo'], '', ['publica' => true]);

    // Seguimiento de trabajos en segundo plano (RF-Q-012): solo el actor o un superadministrador.
    $r->agregar('GET', '/trabajos/{ulid}', [ControladorTrabajos::class, 'estado'], 'trabajos.consultar_propios');

    $r->agregar('GET', '/health/live', [ControladorSalud::class, 'vivo'], '', ['publica' => true, 'exceptuadaMantenimiento' => true]);
    $r->agregar('GET', '/health/ready', [ControladorSalud::class, 'listo'], '', ['publica' => true, 'exceptuadaMantenimiento' => true]);

    // Informes de violación de la CSP (RS-007): los envía el navegador sin cookies ni token, por eso sin CSRF.
    $r->agregar('POST', '/csp-reportes', [ControladorCsp::class, 'recibir'], '', ['publica' => true, 'exceptuadaMantenimiento' => true, 'sinCsrf' => true]);
};
