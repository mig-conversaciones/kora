<?php

declare(strict_types=1);

use App\Controladores\ControladorAcceso;
use App\Controladores\ControladorCalidad;
use App\Controladores\ControladorCatalogos;
use App\Controladores\ControladorColas;
use App\Controladores\ControladorCsp;
use App\Controladores\ControladorCuenta;
use App\Controladores\ControladorExportaciones;
use App\Controladores\ControladorFinanzas;
use App\Controladores\ControladorHato;
use App\Controladores\ControladorImportaciones;
use App\Controladores\ControladorInicio;
use App\Controladores\ControladorMantenimiento;
use App\Controladores\ControladorProduccion;
use App\Controladores\ControladorRecaudos;
use App\Controladores\ControladorSalud;
use App\Controladores\ControladorSistemaDiseno;
use App\Controladores\ControladorTableros;
use App\Controladores\ControladorTrabajos;
use App\Controladores\ControladorUsuarios;
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

    // Catálogos y parámetros (I3, RF-016 a RF-023). Consultan todos; editan catalogo.gestionar y parametros.gestionar.
    $r->agregar('GET', '/catalogos/unidades', [ControladorCatalogos::class, 'unidades'], 'catalogo.consultar');
    $r->agregar('GET', '/catalogos/unidades/{id}/editar', [ControladorCatalogos::class, 'editarUnidad'], 'catalogo.gestionar');
    $r->agregar('POST', '/catalogos/unidades/{id}', [ControladorCatalogos::class, 'guardarUnidad'], 'catalogo.gestionar');
    $r->agregar('GET', '/catalogos/productos', [ControladorCatalogos::class, 'productos'], 'catalogo.consultar');
    $r->agregar('GET', '/catalogos/productos/nuevo', [ControladorCatalogos::class, 'nuevoProducto'], 'catalogo.gestionar');
    $r->agregar('POST', '/catalogos/productos', [ControladorCatalogos::class, 'crearProducto'], 'catalogo.gestionar');
    // Editar un producto admite catalogo.gestionar o catalogo.gestionar_factores: el controlador exige alguno.
    $r->agregar('GET', '/catalogos/productos/{id}/editar', [ControladorCatalogos::class, 'editarProducto'], 'catalogo.consultar');
    $r->agregar('POST', '/catalogos/productos/{id}', [ControladorCatalogos::class, 'guardarProducto'], 'catalogo.consultar');
    $r->agregar('GET', '/catalogos/homologaciones', [ControladorCatalogos::class, 'homologaciones'], 'catalogo.consultar');
    $r->agregar('POST', '/catalogos/homologaciones', [ControladorCatalogos::class, 'guardarHomologacion'], 'catalogo.gestionar');
    $r->agregar('GET', '/catalogos/categorias', [ControladorCatalogos::class, 'categorias'], 'catalogo.consultar');
    $r->agregar('POST', '/catalogos/categorias', [ControladorCatalogos::class, 'crearCategoria'], 'catalogo.gestionar');
    $r->agregar('POST', '/catalogos/categorias/{id}/{estado}', [ControladorCatalogos::class, 'estadoCategoria'], 'catalogo.gestionar');
    $r->agregar('GET', '/catalogos/destinos', [ControladorCatalogos::class, 'destinos'], 'catalogo.consultar');
    $r->agregar('POST', '/catalogos/destinos', [ControladorCatalogos::class, 'guardarDestino'], 'catalogo.gestionar');
    $r->agregar('POST', '/catalogos/destinos/{id}', [ControladorCatalogos::class, 'guardarDestino'], 'catalogo.gestionar');
    $r->agregar('GET', '/catalogos/parametros', [ControladorCatalogos::class, 'parametros'], 'parametros.consultar');
    $r->agregar('POST', '/catalogos/parametros', [ControladorCatalogos::class, 'registrarParametro'], 'parametros.gestionar');

    // Presupuesto y gastos (I3, RF-024 a RF-028) y procesos de autoconsumo (RF-036 a RF-038).
    $r->agregar('GET', '/finanzas/asignaciones', [ControladorFinanzas::class, 'asignaciones'], 'asignaciones.consultar');
    $r->agregar('GET', '/finanzas/asignaciones/nueva', [ControladorFinanzas::class, 'formularioAsignacion'], 'asignaciones.gestionar');
    $r->agregar('GET', '/finanzas/asignaciones/{id}/editar', [ControladorFinanzas::class, 'formularioAsignacion'], 'asignaciones.gestionar');
    $r->agregar('POST', '/finanzas/asignaciones', [ControladorFinanzas::class, 'guardarAsignacion'], 'asignaciones.gestionar');
    $r->agregar('POST', '/finanzas/asignaciones/{id}', [ControladorFinanzas::class, 'guardarAsignacion'], 'asignaciones.gestionar');
    $r->agregar('POST', '/finanzas/asignaciones/{id}/archivar', [ControladorFinanzas::class, 'archivarAsignacion'], 'asignaciones.gestionar');
    $r->agregar('GET', '/finanzas/gastos', [ControladorFinanzas::class, 'gastos'], 'gastos.consultar');
    $r->agregar('GET', '/finanzas/gastos/nuevo', [ControladorFinanzas::class, 'formularioGasto'], 'gastos.gestionar');
    $r->agregar('GET', '/finanzas/gastos/{id}/editar', [ControladorFinanzas::class, 'formularioGasto'], 'gastos.gestionar');
    $r->agregar('POST', '/finanzas/gastos', [ControladorFinanzas::class, 'guardarGasto'], 'gastos.gestionar');
    $r->agregar('POST', '/finanzas/gastos/{id}', [ControladorFinanzas::class, 'guardarGasto'], 'gastos.gestionar');
    $r->agregar('POST', '/finanzas/gastos/{id}/archivar', [ControladorFinanzas::class, 'archivarGasto'], 'gastos.gestionar');
    $r->agregar('GET', '/finanzas/gastos/{id}/soportes/{adjunto}', [ControladorFinanzas::class, 'soporte'], 'gastos.consultar');
    $r->agregar('GET', '/finanzas/ejecucion', [ControladorFinanzas::class, 'ejecucion'], 'asignaciones.consultar');
    $r->agregar('GET', '/finanzas/procesos', [ControladorFinanzas::class, 'procesos'], 'procesos.consultar');
    $r->agregar('GET', '/finanzas/procesos/nuevo', [ControladorFinanzas::class, 'formularioProceso'], 'procesos.gestionar');
    $r->agregar('GET', '/finanzas/procesos/{id}/editar', [ControladorFinanzas::class, 'formularioProceso'], 'procesos.gestionar');
    $r->agregar('POST', '/finanzas/procesos', [ControladorFinanzas::class, 'guardarProceso'], 'procesos.gestionar');
    $r->agregar('POST', '/finanzas/procesos/{id}', [ControladorFinanzas::class, 'guardarProceso'], 'procesos.gestionar');
    $r->agregar('POST', '/finanzas/procesos/{id}/archivar', [ControladorFinanzas::class, 'archivarProceso'], 'procesos.gestionar');
    $r->agregar('GET', '/finanzas/periodos', [ControladorFinanzas::class, 'periodos'], 'periodos.cerrar');
    $r->agregar('POST', '/finanzas/periodos/{anio}/{mes}/cerrar', [ControladorFinanzas::class, 'cerrarPeriodo'], 'periodos.cerrar');
    $r->agregar('POST', '/finanzas/periodos/{anio}/{mes}/reabrir', [ControladorFinanzas::class, 'reabrirPeriodo'], 'periodos.cerrar', ['reautenticacion' => true]);

    // Producción y lotes (I4, RF-039 a RF-051). Las rutas fijas van antes de las que llevan {id}.
    $r->agregar('GET', '/produccion/recepciones', [ControladorProduccion::class, 'recepciones'], 'recepciones.consultar');
    $r->agregar('GET', '/produccion/recepciones/nueva', [ControladorProduccion::class, 'formularioRecepcion'], 'recepciones.gestionar');
    $r->agregar('GET', '/produccion/recepciones/numero-sugerido', [ControladorProduccion::class, 'numeroSugerido'], 'recepciones.gestionar');
    $r->agregar('POST', '/produccion/recepciones', [ControladorProduccion::class, 'guardarRecepcion'], 'recepciones.gestionar');
    $r->agregar('GET', '/produccion/recepciones/{id}', [ControladorProduccion::class, 'recepcion'], 'recepciones.consultar');
    $r->agregar('GET', '/produccion/recepciones/{id}/editar', [ControladorProduccion::class, 'formularioRecepcion'], 'recepciones.gestionar');
    $r->agregar('POST', '/produccion/recepciones/{id}', [ControladorProduccion::class, 'guardarRecepcion'], 'recepciones.gestionar');
    $r->agregar('POST', '/produccion/recepciones/{id}/archivar', [ControladorProduccion::class, 'archivarRecepcion'], 'recepciones.gestionar');
    $r->agregar('GET', '/produccion/salidas', [ControladorProduccion::class, 'salidas'], 'salidas.consultar');
    $r->agregar('GET', '/produccion/salidas/nueva', [ControladorProduccion::class, 'formularioSalida'], 'salidas.gestionar');
    $r->agregar('POST', '/produccion/salidas', [ControladorProduccion::class, 'guardarSalida'], 'salidas.gestionar');
    $r->agregar('GET', '/produccion/salidas/{id}/editar', [ControladorProduccion::class, 'formularioSalida'], 'salidas.gestionar');
    $r->agregar('POST', '/produccion/salidas/{id}', [ControladorProduccion::class, 'guardarSalida'], 'salidas.gestionar');
    $r->agregar('POST', '/produccion/salidas/{id}/archivar', [ControladorProduccion::class, 'archivarSalida'], 'salidas.gestionar');
    $r->agregar('GET', '/produccion/transformaciones', [ControladorProduccion::class, 'transformaciones'], 'transformaciones.consultar');
    $r->agregar('GET', '/produccion/transformaciones/nueva', [ControladorProduccion::class, 'formularioTransformacion'], 'transformaciones.gestionar');
    $r->agregar('POST', '/produccion/transformaciones', [ControladorProduccion::class, 'guardarTransformacion'], 'transformaciones.gestionar');
    $r->agregar('POST', '/produccion/transformaciones/{id}/archivar', [ControladorProduccion::class, 'archivarTransformacion'], 'transformaciones.gestionar');

    // Hato (I4, RF-052 a RF-057).
    $r->agregar('GET', '/hato', [ControladorHato::class, 'inventario'], 'hato.consultar');
    $r->agregar('POST', '/hato/cortes', [ControladorHato::class, 'crearCorte'], 'hato.gestionar');
    $r->agregar('GET', '/hato/animales/nuevo', [ControladorHato::class, 'formularioAnimal'], 'hato.gestionar');
    $r->agregar('POST', '/hato/animales', [ControladorHato::class, 'guardarAnimal'], 'hato.gestionar');
    $r->agregar('GET', '/hato/animales/{id}', [ControladorHato::class, 'formularioAnimal'], 'hato.consultar');
    $r->agregar('POST', '/hato/animales/{id}', [ControladorHato::class, 'guardarAnimal'], 'hato.gestionar');
    $r->agregar('POST', '/hato/animales/{id}/practica', [ControladorHato::class, 'cambiarPractica'], 'hato.gestionar_practica');
    $r->agregar('POST', '/hato/animales/{id}/archivar', [ControladorHato::class, 'archivarAnimal'], 'hato.gestionar');

    // Recaudos (I4, RF-029, RF-035): consulta según el alcance; escritura solo del Grupo de Recaudo.
    $r->agregar('GET', '/recaudos', [ControladorRecaudos::class, 'listado'], 'recaudos.consultar');
    // RF-033: el alta manual solo existe con la funcionalidad recaudos_alta_manual activa (si no, 404).
    $r->agregar('GET', '/recaudos/nuevo', [ControladorRecaudos::class, 'formulario'], 'recaudos.gestionar', ['funcionalidad' => 'recaudos_alta_manual']);
    $r->agregar('POST', '/recaudos', [ControladorRecaudos::class, 'guardar'], 'recaudos.gestionar', ['funcionalidad' => 'recaudos_alta_manual']);
    // I5: clasificación (RF-031) y conciliación (RF-032).
    $r->agregar('GET', '/recaudos/clasificar', [ControladorRecaudos::class, 'clasificacion'], 'recaudos.clasificar');
    $r->agregar('POST', '/recaudos/clasificar', [ControladorRecaudos::class, 'clasificar'], 'recaudos.clasificar');
    $r->agregar('GET', '/recaudos/diferencias', [ControladorRecaudos::class, 'diferencias'], 'recaudos.conciliar');
    $r->agregar('GET', '/recaudos/{id}/conciliar', [ControladorRecaudos::class, 'conciliacion'], 'recaudos.conciliar');
    $r->agregar('POST', '/recaudos/{id}/conciliar', [ControladorRecaudos::class, 'conciliar'], 'recaudos.conciliar');

    // Tableros (I6, RF-062 a RF-079). El alcance y la unidad se validan en el controlador (RF-072).
    $r->agregar('GET', '/tableros/central', [ControladorTableros::class, 'central'], 'tablero_central.consultar');
    $r->agregar('GET', '/tableros/central/procesos', [ControladorTableros::class, 'procesos'], 'tablero_central.consultar');
    $r->agregar('GET', '/tableros/unidad/{codigo}', [ControladorTableros::class, 'unidad'], 'tablero_unidad.consultar');
    $r->agregar('GET', '/tableros/unidad/{codigo}/hato', [ControladorTableros::class, 'hato'], 'tablero_unidad.consultar');

    // Exportaciones (I6, RF-080 a RF-082): solo el autor descarga, durante 24 h.
    $r->agregar('POST', '/exportaciones', [ControladorExportaciones::class, 'solicitar'], 'exportaciones.solicitar', ['limite' => 'administracion']);
    $r->agregar('GET', '/exportaciones/{ulid}', [ControladorExportaciones::class, 'estado'], 'exportaciones.solicitar');
    $r->agregar('GET', '/exportaciones/{ulid}/descargar', [ControladorExportaciones::class, 'descargar'], 'exportaciones.solicitar');

    // Bandeja de calidad (I4, RF-058, RF-059).
    $r->agregar('GET', '/calidad', [ControladorCalidad::class, 'bandeja'], 'calidad.consultar');
    $r->agregar('POST', '/calidad/recalcular', [ControladorCalidad::class, 'recalcular'], 'calidad.gestionar', ['limite' => 'administracion']);
    $r->agregar('POST', '/calidad/{id}/vincular-recepcion', [ControladorCalidad::class, 'vincularRecepcion'], 'calidad.gestionar');
    $r->agregar('POST', '/calidad/{id}/asignar-proceso', [ControladorCalidad::class, 'asignarProceso'], 'calidad.gestionar');
    $r->agregar('POST', '/calidad/{id}/aceptar', [ControladorCalidad::class, 'aceptar'], 'calidad.gestionar');

    // Importaciones asistidas (I5, RF-083 a RF-094). El permiso depende de la fuente: lo decide el servicio.
    $r->agregar('GET', '/importaciones', [ControladorImportaciones::class, 'inicio'], '', ['autenticada' => true]);
    $r->agregar('POST', '/importaciones/libro', [ControladorImportaciones::class, 'cargar'], '', ['autenticada' => true, 'limite' => 'administracion']);
    $r->agregar('POST', '/importaciones', [ControladorImportaciones::class, 'crear'], '', ['autenticada' => true]);
    $r->agregar('GET', '/importaciones/acta', [ControladorImportaciones::class, 'acta'], '', ['autenticada' => true]);
    $r->agregar('GET', '/importaciones/plantillas/{fuente}', [ControladorImportaciones::class, 'plantilla'], '', ['autenticada' => true]);
    $r->agregar('GET', '/importaciones/{ulid}', [ControladorImportaciones::class, 'detalle'], '', ['autenticada' => true]);
    $r->agregar('GET', '/importaciones/{ulid}/estado', [ControladorImportaciones::class, 'estado'], '', ['autenticada' => true]);
    $r->agregar('GET', '/importaciones/{ulid}/errores', [ControladorImportaciones::class, 'errores'], '', ['autenticada' => true]);
    $r->agregar('GET', '/importaciones/{ulid}/original', [ControladorImportaciones::class, 'original'], '', ['autenticada' => true]);
    $r->agregar('POST', '/importaciones/{ulid}/aplicar', [ControladorImportaciones::class, 'aplicar'], '', ['autenticada' => true]);
    $r->agregar('POST', '/importaciones/{ulid}/revertir', [ControladorImportaciones::class, 'revertir'], '', ['autenticada' => true, 'reautenticacion' => true]);

    // Usuarios, roles y auditoría (I3, RF-009 a RF-012, RAU-001).
    $r->agregar('GET', '/sistema/usuarios', [ControladorUsuarios::class, 'listar'], 'usuarios.gestionar');
    $r->agregar('GET', '/sistema/usuarios/invitar', [ControladorUsuarios::class, 'formularioInvitacion'], 'usuarios.gestionar');
    $r->agregar('POST', '/sistema/usuarios', [ControladorUsuarios::class, 'invitar'], 'usuarios.gestionar', ['limite' => 'administracion']);
    $r->agregar('GET', '/sistema/usuarios/{id}', [ControladorUsuarios::class, 'mostrar'], 'usuarios.gestionar');
    $r->agregar('POST', '/sistema/usuarios/{id}/acceso', [ControladorUsuarios::class, 'actualizarAcceso'], 'usuarios.gestionar', ['reautenticacion' => true]);
    $r->agregar('POST', '/sistema/usuarios/{id}/reenviar', [ControladorUsuarios::class, 'reenviarInvitacion'], 'usuarios.gestionar', ['limite' => 'administracion']);
    $r->agregar('POST', '/sistema/usuarios/{id}/{accion}', [ControladorUsuarios::class, 'cambiarEstado'], 'usuarios.gestionar');
    $r->agregar('GET', '/sistema/roles', [ControladorUsuarios::class, 'roles'], '', ['autenticada' => true]);
    $r->agregar('POST', '/sistema/roles/{rol}/{permiso}/{accion}', [ControladorUsuarios::class, 'cambiarPermiso'], 'roles.gestionar', ['reautenticacion' => true]);
    $r->agregar('GET', '/sistema/auditoria', [ControladorUsuarios::class, 'auditoria'], 'auditoria.consultar');

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
