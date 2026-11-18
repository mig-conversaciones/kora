-- Semilla 001: centro, unidades, destinos, roles, permisos y matriz de autorización (RDL-008, RAC-025).
-- Extraída de la semilla mínima de producción del Anexo B (06-02, B.1). Idempotente: INSERT IGNORE.
-- Las categorías de gasto y los parámetros de indicadores se siembran con sus tablas en la I3 (migración 008).
INSERT IGNORE INTO centros (codigo, nombre, regional, codigo_regional)
VALUES ('9124', 'Centro Agropecuario de Buga', 'Valle', '76');

SET @centro = (SELECT id FROM centros WHERE codigo = '9124');

INSERT IGNORE INTO unidades_productivas (centro_id, codigo, nombre, prefijo_lote, tipo) VALUES
    (@centro, 'GAN', 'Ganadería', 'GAN', 'pecuaria'),
    (@centro, 'GRA', 'Granja', 'GRA', 'pecuaria'),
    (@centro, 'API', 'Apicultura', 'API', 'pecuaria'),
    (@centro, 'AGR', 'Agrícola', 'AGR', 'agricola'),
    (@centro, 'HOR', 'Hortalizas', 'HOR', 'agricola'),
    (@centro, 'INV', 'Invernadero', 'INV', 'agricola'),
    (@centro, 'IND', 'Agroindustria', 'IND', 'agroindustrial'),
    (@centro, 'GOU', 'Gourmet - Café', 'GOU', 'agroindustrial'),
    (@centro, 'CAF', 'Cafetería Aprendices', 'CAF', 'servicios'),
    (@centro, 'LAM', 'Lamfa', 'LAM', 'servicios'),
    (@centro, 'COM', 'Comercio', 'COM', 'servicios'),
    (@centro, 'SIN', 'Sin clasificar (solo heredados)', 'SIN', 'tecnica');

INSERT IGNORE INTO destinos_internos (centro_id, nombre, unidad_id) VALUES
    (@centro, 'Agroindustria', (SELECT id FROM unidades_productivas WHERE centro_id = @centro AND codigo = 'IND')),
    (@centro, 'Bienestar al Aprendiz', NULL),
    (@centro, 'Agroambiental', NULL);

INSERT IGNORE INTO roles (codigo, nombre, alcance, requiere_2fa, privilegiado) VALUES
    ('superadministrador', 'Superadministrador', 'global', 1, 1),
    ('administrador', 'Administrador funcional', 'centro', 1, 1),
    ('coordinacion_administrativa', 'Coordinación administrativa', 'centro', 0, 0),
    ('grupo_recaudo', 'Grupo de Recaudo', 'centro', 0, 0),
    ('lider_produccion_centro', 'Líder de producción de centro', 'centro', 0, 0),
    ('lider_unidad', 'Líder de unidad productiva', 'unidad', 0, 0),
    ('coordinacion_academica', 'Coordinación académica', 'centro', 0, 0);

INSERT IGNORE INTO permisos (codigo, descripcion) VALUES
    ('tablero_central.consultar', 'Consultar el tablero central'),
    ('tablero_unidad.consultar', 'Consultar el tablero técnico de unidad'),
    ('asignaciones.consultar', 'Consultar asignaciones presupuestales'),
    ('asignaciones.gestionar', 'Gestionar asignaciones presupuestales'),
    ('asignaciones.importar', 'Importar presupuesto'),
    ('gastos.consultar', 'Consultar gastos ejecutados'),
    ('gastos.gestionar', 'Gestionar gastos ejecutados'),
    ('gastos.importar', 'Importar gastos ejecutados'),
    ('periodos.cerrar', 'Cerrar y reabrir periodos'),
    ('recaudos.consultar', 'Consultar recaudos'),
    ('recaudos.gestionar', 'Registrar y editar recaudos'),
    ('recaudos.importar', 'Importar el archivo de recaudos de la DG'),
    ('recaudos.clasificar', 'Clasificar recaudos'),
    ('recaudos.conciliar', 'Conciliar recaudos con ventas'),
    ('procesos.consultar', 'Consultar procesos de autoconsumo'),
    ('procesos.gestionar', 'Gestionar procesos de autoconsumo'),
    ('procesos.importar', 'Importar cupos de autoconsumo'),
    ('recepciones.consultar', 'Consultar recepciones'),
    ('recepciones.gestionar', 'Gestionar recepciones'),
    ('recepciones.importar', 'Importar base de producción'),
    ('salidas.consultar', 'Consultar salidas'),
    ('salidas.gestionar', 'Gestionar salidas'),
    ('salidas.importar', 'Importar base de ventas'),
    ('transformaciones.consultar', 'Consultar transformaciones'),
    ('transformaciones.gestionar', 'Gestionar transformaciones'),
    ('hato.consultar', 'Consultar el hato'),
    ('hato.gestionar', 'Gestionar el hato'),
    ('hato.gestionar_practica', 'Gestionar el lote de práctica académica'),
    ('hato.importar', 'Importar estado reproductivo'),
    ('catalogo.consultar', 'Consultar catálogos'),
    ('catalogo.gestionar', 'Gestionar catálogos'),
    ('catalogo.importar', 'Importar catálogo de productos'),
    ('catalogo.gestionar_factores', 'Gestionar factores de derivados lácteos'),
    ('parametros.consultar', 'Consultar parámetros'),
    ('parametros.gestionar', 'Gestionar parámetros e indicadores'),
    ('calidad.consultar', 'Consultar la bandeja de calidad'),
    ('calidad.gestionar', 'Corregir incidencias de calidad'),
    ('exportaciones.solicitar', 'Solicitar exportaciones'),
    ('trabajos.consultar_propios', 'Consultar trabajos propios'),
    ('usuarios.gestionar', 'Gestionar usuarios del centro'),
    ('roles.gestionar', 'Gestionar roles y permisos'),
    ('auditoria.consultar', 'Consultar la auditoría'),
    ('auditoria.exportar', 'Exportar la auditoría'),
    ('sistema.salud', 'Consultar la salud del sistema'),
    ('sistema.colas', 'Gestionar colas de trabajos'),
    ('sistema.funcionalidades', 'Gestionar funcionalidades configurables'),
    ('mantenimiento.gestionar', 'Activar y desactivar el modo mantenimiento'),
    ('mantenimiento.acceso_excepcional', 'Acceso autorizado durante el mantenimiento'),
    ('soporte.vista_usuario', 'Vista de soporte de solo lectura');

-- Asignación de permisos según la matriz de autorización (3.10, RAC-025).
INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'usuarios.gestionar', 'roles.gestionar', 'auditoria.consultar', 'auditoria.exportar', 'sistema.salud',
    'sistema.colas', 'sistema.funcionalidades', 'mantenimiento.gestionar', 'mantenimiento.acceso_excepcional',
    'soporte.vista_usuario', 'trabajos.consultar_propios')
WHERE r.codigo = 'superadministrador';

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'usuarios.gestionar', 'tablero_central.consultar', 'tablero_unidad.consultar', 'asignaciones.consultar',
    'gastos.consultar', 'recaudos.consultar', 'procesos.consultar', 'recepciones.consultar', 'salidas.consultar',
    'transformaciones.consultar', 'hato.consultar', 'catalogo.consultar', 'catalogo.gestionar', 'catalogo.importar',
    'catalogo.gestionar_factores', 'parametros.consultar', 'parametros.gestionar', 'calidad.consultar',
    'sistema.salud', 'exportaciones.solicitar', 'trabajos.consultar_propios')
WHERE r.codigo = 'administrador';

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'tablero_central.consultar', 'tablero_unidad.consultar', 'asignaciones.consultar', 'asignaciones.gestionar',
    'asignaciones.importar', 'gastos.consultar', 'gastos.gestionar', 'gastos.importar', 'periodos.cerrar',
    'recaudos.consultar', 'procesos.consultar', 'procesos.gestionar', 'procesos.importar', 'recepciones.consultar',
    'salidas.consultar', 'transformaciones.consultar', 'catalogo.consultar', 'parametros.consultar',
    'calidad.consultar', 'exportaciones.solicitar', 'trabajos.consultar_propios')
WHERE r.codigo = 'coordinacion_administrativa';

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'tablero_central.consultar', 'tablero_unidad.consultar', 'asignaciones.consultar', 'recaudos.consultar',
    'recaudos.gestionar', 'recaudos.importar', 'recaudos.clasificar', 'recaudos.conciliar', 'recepciones.consultar',
    'salidas.consultar', 'catalogo.consultar', 'calidad.consultar', 'calidad.gestionar', 'exportaciones.solicitar',
    'trabajos.consultar_propios')
WHERE r.codigo = 'grupo_recaudo';

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'tablero_central.consultar', 'tablero_unidad.consultar', 'asignaciones.consultar', 'gastos.consultar',
    'recaudos.consultar', 'procesos.consultar', 'recepciones.consultar', 'recepciones.gestionar',
    'recepciones.importar', 'salidas.consultar', 'salidas.gestionar', 'salidas.importar',
    'transformaciones.consultar', 'transformaciones.gestionar', 'hato.consultar', 'catalogo.consultar',
    'catalogo.gestionar', 'catalogo.importar', 'parametros.consultar', 'calidad.consultar', 'calidad.gestionar',
    'exportaciones.solicitar', 'trabajos.consultar_propios')
WHERE r.codigo = 'lider_produccion_centro';

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'tablero_central.consultar', 'tablero_unidad.consultar', 'asignaciones.consultar', 'gastos.consultar',
    'gastos.gestionar', 'recaudos.consultar', 'procesos.consultar', 'recepciones.consultar', 'recepciones.gestionar',
    'recepciones.importar', 'salidas.consultar', 'salidas.gestionar', 'salidas.importar', 'transformaciones.consultar',
    'transformaciones.gestionar', 'hato.consultar', 'hato.gestionar', 'hato.importar', 'catalogo.consultar',
    'parametros.consultar', 'calidad.consultar', 'calidad.gestionar', 'exportaciones.solicitar',
    'trabajos.consultar_propios')
WHERE r.codigo = 'lider_unidad';

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'tablero_central.consultar', 'tablero_unidad.consultar', 'asignaciones.consultar', 'gastos.consultar',
    'procesos.consultar', 'recepciones.consultar', 'salidas.consultar', 'transformaciones.consultar',
    'transformaciones.gestionar', 'hato.consultar', 'hato.gestionar_practica', 'hato.importar', 'catalogo.consultar',
    'catalogo.gestionar_factores', 'parametros.consultar', 'calidad.consultar', 'exportaciones.solicitar',
    'trabajos.consultar_propios')
WHERE r.codigo = 'coordinacion_academica';


INSERT IGNORE INTO configuracion_sistema (clave, valor) VALUES
    ('mantenimiento', '{"activo": false, "titulo": null, "mensaje": null, "estimado_hasta": null}');

INSERT IGNORE INTO versiones_datos (centro_id, version) VALUES (@centro, 1);
