# Registro de entrega asistida por IA — Iteración 3 «Maestros y finanzas»

- **Fecha:** 2026-11-18
- **Herramienta y modelo:** Claude Code (extensión de VS Code), modelo Claude Opus 5.5 (`claude-opus-5-5`)
- **Responsable humano:** líder técnico del proyecto, que revisa y aprueba
- **Requisitos:** RF-002 (menú completo), RF-009 a RF-012, RF-016 a RF-028, RF-036 y RF-037, RAU-001; criterios de las Obs. 1 y 5; S-12, S-13 y S-14
- **Fichas:** FP-11 (actualizada)

## Objetivo

Entregar los maestros y las finanzas de la I3:

- **Catálogos:** unidades, productos y derivados con factores, homologación, categorías de gasto (con Materiales de formación), destinos y parámetros con historial.
- **Finanzas:** asignaciones, gastos con soporte, ejecución por línea, procesos de autoconsumo y cierres de periodo.
- **Sistema:** interfaz de usuarios, roles y auditoría.

Se demostraron la Obs. 1 (categoría Materiales de formación, fuera de IND_17) y la Obs. 5 (cada líder ve y edita solo su unidad).

## Supuestos

- **Entregas y recepciones.** Las salidas, recepciones y transformaciones llegan con la migración 010 en la I4. Por eso hoy la ejecución de los procesos (RF-037) es 0, y no hay bloqueos por entregas ni por recepciones. Las reglas ya están escritas y se activan solas con las tablas (ADR-017).
- **RF-038.** El aviso de entregas sin proceso depende de las salidas: se completa en la I4, con la bandeja de calidad.
- **Recaudos.** La tabla se crea ahora porque la exige la regla de inactivación de unidades, pero su interfaz es de la I5.
- **Un solo centro.** La v1 opera un centro (S-19): el superadministrador, que no tiene centro, invita los roles de negocio a ese centro.
- **Selector de unidad (RF-004).** En lugar de un selector global, cada listado filtra por las unidades del alcance. El chip de la barra superior sigue informativo; queda para la I6, con los tableros.
- **Vista de soporte (RF-013).** Sigue fuera de alcance: es «Podría» y su funcionalidad está desactivada.

## Decisiones

- **[ADR-017](../adr/017-modulos-de-gestion.md):**
  - montos como decimales exactos en texto, sin `float`, y aceptando «12.500.000»;
  - reglas de bloqueo con tablas condicionales;
  - el periodo cerrado como conflicto 409 y no como 403;
  - ciclo HTMX común, con los errores dirigidos a `#contenedor-errores`.
- **Separación de funciones (RF-012) en `PoliticaUsuarios`.**
  - Nadie se modifica a sí mismo, ni siquiera el superadministrador. Un administrador nunca toca cuentas de superadministrador y solo gestiona su centro (fuera de él, 404).
  - El rol superadministrador no se edita desde la matriz.
  - Una cuenta global no recibe roles de negocio, ni al revés.
- **Corte inmediato.** Desactivar una cuenta revoca sus sesiones y sus enlaces pendientes. Cambiar el rol, las unidades o los permisos de un rol incrementa `version_permisos`, y la sesión se regenera en la siguiente solicitud.
- **Cierre de periodo.**
  - Se verifican la fecha anterior y la nueva del gasto, para que no se pueda sacar un gasto de un mes cerrado.
  - Los meses futuros no se cierran; lo valida el servidor.
  - La reapertura exige un motivo de al menos 10 caracteres y reautenticación.
- **Soportes.** La firma (bytes mágicos) debe coincidir con la extensión. El nombre en disco es aleatorio, en `almacenamiento/adjuntos/AAAA/MM`. La descarga verifica primero el acceso al gasto dueño, y si no lo hay responde 404.
- **Semilla de derivados.** Solo actualiza los productos sin factor, así que ejecutar `sembrar.php` otra vez no pisa un ajuste del Taller de Lácteos.

## Archivos principales

| Grupo | Archivos |
|---|---|
| Base de datos | `bd/migraciones/007` a `009` (`.sql` y `.down.sql`), `bd/semillas/002_catalogos_finanzas.sql` |
| Núcleo | `Entrada` (nueva), `Solicitud` (archivos), `Vista` (moneda, decimal, fecha y menú con varios permisos), `ErrorHttp` (título), `ManejadorErrores` (HX-Retarget) |
| Políticas | `PoliticaAlcanceUnidad`, `PoliticaUsuarios`, `RecursoUnidad`, `RecursoUsuario` |
| Repositorios | `RepositorioConAlcance` (insertar y archivar), `RepositorioAsignaciones`, `RepositorioGastos`, `RepositorioProcesos` |
| Servicios | `AutorizacionRecurso`, `ReglasDependencias`, `RecursoAuditado`, `ServicioVersionDatos`, `ServicioCatalogos`, `ServicioProductos`, `ServicioParametros`, `ServicioAsignaciones`, `ServicioGastos`, `ServicioAdjuntos`, `ServicioProcesos`, `ServicioPeriodos`, `ServicioUsuarios`, `ServicioPermisos`, `ServicioConsultaAuditoria`; `ServicioAuditoria` (unidad del evento) |
| Web | `ControladorModulo`, `ControladorCatalogos`, `ControladorFinanzas`, `ControladorUsuarios`; vistas `modulos/pagina.php`, `catalogos/*`, `finanzas/*`, `sistema/usuarios`, `usuario`, `usuario_invitar`, `roles` y `auditoria`; parciales `campo`, `paginacion` y `selector_vigencia`; `config/rutas.php`, `config/menu.php`, `config/app.php`; `recursos/css/app.css` |
| Pruebas | `tests/Integracion/MaestrosFinanzasTest.php`, `AyudasCuentas.php`, `NavegadorPrueba` (archivos y GET de HTMX), `MigracionesTest` (9 migraciones), `tests/Unitarias/ReglasNegocioTest.php` |
| Documentación | ADR-017, `docs/runbooks/maestros-y-finanzas.md`, `acceso-y-cuentas.md`, FP-11, `README.md`, `CHANGELOG.md` |

## Validaciones ejecutadas

Todas son resultados reales en MAMP: PHP 8.3.36 y MySQL 5.7.24.

- **Migraciones:** 007 a 009 aplicadas en `kora` y `kora_pruebas`. `MigracionesTest` aplica, revierte y reaplica las 9, y comprueba que las tablas presentes sean exactamente las esperadas.
- **`composer verificar`:** PSR-12 sin cambios, PHPStan nivel 6 sin errores y PHPUnit con 97 pruebas y 612 aserciones. Las 6 pruebas nuevas de integración son repetibles: dos ejecuciones seguidas en verde.
- **Flujos en `MaestrosFinanzasTest`:**
  - **Obs. 5:** el líder de GAN no ve la asignación ni los gastos de AGR. Un gasto en AGR responde 403 auditado y uno ajeno, 404. El formato «12.500.000» se guarda como `12500000.00`.
  - **Reglas de asignación:** desglose mayor que el total y asignación duplicada, ambos con mensaje por campo.
  - **Periodo cerrado:**
    - Componente mayor que el valor, rechazado con 422 conservando lo escrito.
    - Con el mes cerrado: 409 «Periodo cerrado» con `HX-Retarget` al editar, archivar y crear. Un mes futuro no se cierra (422).
    - Reapertura: sin reautenticar lleva a `/reautenticar`; con un motivo corto, 422; con uno válido, auditada con su motivo. Después, la edición pasa y una versión vieja choca con 409.
  - **Soportes:** un ejecutable renombrado a `.pdf` se rechaza sin dejar el gasto a medias. El PNG se guarda con nombre aleatorio; el dueño lo descarga y otro líder recibe 404.
  - **Catálogos:** precio 0 y porcentaje 101 rechazados; el historial de parámetros se conserva. SGAC duplicado y factor 0 rechazados, con la incidencia «Yogurt no está en el catálogo». «  Léche   de VACA » → «leche de vaca». IND_17 se apaga solo en la línea «otra». Inactivar GAN con un gasto se bloquea; inactivar AGR pasa.
  - **Usuarios:**
    - Un administrador no crea superadministradores (403 auditado). Un líder sin unidades da 422.
    - Modificar la propia cuenta responde 403.
    - El cambio de unidades queda con antes y después, y el líder ve AGR en su siguiente solicitud.
    - Al desactivarlo, su sesión vuelve al ingreso.
    - Un administrador recibe 403 en la auditoría; el superadministrador la ve con el motivo.
  - **Matriz:** el líder la consulta pero no la cambia. El superadministrador concede un permiso: `version_permisos` sube y el permiso rige de inmediato. El rol superadministrador está protegido.
- **Unitarias:** decimales locales y de campo, factores sin interpretar miles, suma sin error de coma flotante, fechas reales, rangos de mes, las dos políticas y la normalización.
- **Prueba de humo contra Apache** con una cuenta temporal de Coordinación administrativa en la base `kora`:
  - Al terminar, la cuenta quedó desactivada y el gasto de prueba archivado.
  - Activación 303; formularios con CSRF.
  - Subida multipart real: un PDF falso da 422 y un PNG, 200 con toast y `HX-Push-Url`.
  - El adjunto queda con nombre aleatorio en `almacenamiento/adjuntos/2026/11/`, que responde 403 desde la web.
  - Descarga 200 con `Content-Disposition: attachment`, `nosniff` y CSP; sin sesión, 303.
  - 12 pantallas nuevas responden 200, y `/sistema/usuarios` responde 403 para ese rol.
  - Ninguna página tiene `style=`, `on*=` ni `hx-on`, y el menú sale con los cuatro grupos.
  - La cadena de auditoría queda íntegra.
- **Activos:** CSS de 7,9 KB y JS de 39,9 KB comprimidos con gzip, dentro del presupuesto.

## Riesgos

- **Sin pruebas en navegador** de la confirmación de archivado y reapertura, la carga de archivos con HTMX (`hx-encoding`) ni el foco tras un 422 (Playwright, pendiente). La subida sí se probó por Apache con curl.
- **Desactivación con datos.** La regla de inactivación de unidades cuenta los recaudos activos: con datos reales de la DG (I5) puede bloquear más de lo esperado, por la unidad SIN y los recaudos sin clasificar.
- **Validaciones pendientes con la contraparte:** S-12 (factores de derivados) y S-14 (IND_17 sin Materiales de formación).
- **Adjuntos huérfanos.** Si la transacción falla después de mover el archivo, queda un adjunto huérfano. La limpieza periódica todavía no los retira; se agrega con las copias de la I7.

## Pendientes

- **I4:** recepciones, salidas, transformaciones (con las reglas de bloqueo ya activas), entregas de autoconsumo vinculadas a procesos (RF-037 y RF-038) y bandeja de calidad.
- **I5:** importación de presupuesto, gastos, cupos y catálogo, con `Entrada::decimal`.
- **I6:** selector de unidad en los tableros (RF-004).
- **Mantenimiento:** limpieza de adjuntos huérfanos y exportación de la auditoría desde la interfaz (`auditoria.exportar`).

## Aprobación humana

Pendiente: revisión del líder técnico y demostración de las Obs. 1 y 5 a la contraparte.
