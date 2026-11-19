# Cambios

Formato basado en *Keep a Changelog*, con versionado semántico (RNF-015).

## [0.4.0] — Iteración 3 «Maestros y finanzas» (sin publicar)

### Agregado

- **Migraciones 007 a 009** del Anexo B, con su reversión:
  - importaciones (lotes y filas);
  - catálogos: productos, homologación, categorías de gasto y parámetros;
  - finanzas: asignaciones, gastos, procesos de autoconsumo, recaudos y cierres de periodo.

  La columna generada `activa_unica` garantiza una sola asignación activa por unidad y vigencia, también en MySQL 5.7.
- **Semilla `002_catalogos_finanzas.sql`:**
  - categorías de gasto, con Materiales de formación (Obs. 1);
  - los seis parámetros de los indicadores;
  - factores de los derivados lácteos, que no pisan un ajuste ya hecho (RF-018, S-12).
- **Catálogos** (`/catalogos`, RF-016 a RF-023):
  - **Unidades**, con bloqueo de la inactivación mientras tengan registros activos.
  - **Productos**, con código SGAC único, coherencia de precios y medida bloqueada si hay recepciones.
  - **Derivados lácteos**, con factor y metas de rendimiento, e incidencias de configuración.
  - **Homologación** con normalización (sin tildes, minúsculas) y «concepto fuente».
  - **Categorías de gasto**, con la marca IND_17 limitada a Mantenimiento y Alimentación.
  - **Destinos internos**, con unidad receptora.
  - **Parámetros** con historial por vigencia y validación de rangos.
- **Presupuesto y gastos** (`/finanzas`, RF-024 a RF-028):
  - **Asignaciones** con desglose ≤ total y una activa por unidad y vigencia.
  - **Gastos**, con el componente académico ≤ valor y soporte PDF, PNG o JPG de hasta 5 MB. La firma del archivo se verifica, se guarda en almacenamiento privado y se descarga con autorización.
  - **Ejecución por línea presupuestal**, con la aclaración «No corresponde a ejecución CDP/RP».
  - **Cierre mensual**: 409 «Periodo cerrado» al crear, editar o archivar. La reapertura exige motivo y reautenticación, y queda auditada.
- **Procesos de autoconsumo** (`/finanzas/procesos`, RF-036, RF-037):
  - código AUTO_xxx sugerido y destino del catálogo o en texto libre;
  - medida tomada del producto, y bloqueo de unidad, medida, producto y vigencia si el proceso tiene entregas;
  - avance físico y monetario.
- **Usuarios, roles y auditoría** (`/sistema`, RF-009 a RF-012, RAU-001):
  - Invitar, cambiar el rol y las unidades (con reautenticación y auditoría del antes y el después), desactivar (revoca las sesiones al instante), reactivar y reenviar la invitación.
  - Separación de funciones con `PoliticaUsuarios`: nadie se modifica a sí mismo y un Administrador no gestiona Superadministradores. Todo intento queda auditado como «denegado».
  - Matriz de roles y permisos visible para todos. Solo el Superadministrador la modifica, con reautenticación; el rol superadministrador está protegido.
  - Consulta de la auditoría con filtros, paginación por cursor y estado del sellado.
- **Alcance por unidad (Obs. 5):**
  - `PoliticaAlcanceUnidad` (asignaciones, gastos y procesos) y `AutorizacionRecurso` para decidir sobre el registro.
  - Una unidad ajena responde 403 auditado y un registro ajeno, 404.
- **Base de los módulos** ([ADR-017](docs/adr/017-modulos-de-gestion.md)):
  - `ControladorModulo` y la página común con pestañas.
  - Parciales `campo` (accesible, con error por campo), `paginacion` y `selector_vigencia`.
  - `Entrada`, con decimales exactos sin `float` y formato local «12.500.000».
  - `ReglasDependencias`: los bloqueos se activan solos con las tablas de la I4.
  - `RecursoAuditado`, que guarda la unidad en el evento de auditoría.
- **Menú** reorganizado en «Vista general», «Mi gestión», «Catálogos» y «Sistema» (RF-002). Una entrada puede exigir cualquiera de varios permisos.
- **Pruebas:** 97 en total (24 nuevas):
  - `MaestrosFinanzasTest`, con 6 flujos completos y varios roles;
  - `ReglasNegocioTest`, con decimales, políticas y normalización.
- **Documentación:** [ADR-017](docs/adr/017-modulos-de-gestion.md), [runbook de maestros y finanzas](docs/runbooks/maestros-y-finanzas.md) y [FP-11](docs/procesos/FP-11-registro-de-gasto-ejecutado.md) con su estado de implementación.

### Cambiado

- **Errores controlados en HTMX** (403, 404, 409…): van a `#contenedor-errores` (`HX-Retarget`) y ya no reemplazan el formulario. `ErrorHttp` admite un título propio.
- **`Solicitud`** recibe los archivos subidos (`archivos`), normalizados, sin el tipo MIME del navegador.
- **Pruebas:** la versión de esquema esperada se lee de `config/app.php`, en lugar de un literal.

## [0.3.0] — Iteración 2 «Identidad y acceso» (sin publicar)

### Agregado

- **Ingreso y cuentas:**
  - Correo y contraseña con Argon2id y rehash transparente.
  - Mensajes genéricos y tiempo homogéneo; límites por cuenta (espera progresiva y bloqueo temporal) y por IP (RAC-001 a RAC-005, RAC-029).
  - Política de contraseñas NIST 800-63B-4: 15 caracteres, u 8 con 2FA; frases, Unicode NFC, lista de unas 46.000 contraseñas comunes y reglas contextuales y de repetición.
  - Invitación con enlace de 72 h y activación; recuperación con enlace de 30 min que cierra todas las sesiones; cambio de contraseña (RAC-006 a RAC-008).
- **Verificación en dos pasos TOTP** (RAC-009 a RAC-014):
  - Implementación propia según la RFC 6238, verificada con sus vectores, con ventana de ±1 paso y anti-reutilización.
  - QR generado en el servidor y semilla cifrada con XChaCha20-Poly1305 y llavero versionado (RS-013).
  - 10 códigos de respaldo con HMAC y restablecimiento administrativo auditado.
- **Sesiones en el servidor** (ADR-006, RAC-016 a RAC-021):
  - ID de 256 bits guardado como hash; expiración por inactividad y absoluta, más corta para privilegiados.
  - Regeneración en cada elevación, lista de sesiones propias y «cerrar las demás».
  - Aviso de expiración con «Seguir trabajando».
- **Cadena de intermediarios completa:** IniciarSesion, LimitarTasa, VerificarCsrf (token sincronizador más Origin y Sec-Fetch-Site), Autenticar, Idempotencia (RNF-010) y Autorizar (RBAC con denegación por defecto, AlcanceDatos y reautenticación de 5 min).
- **Autorización:** `Autorizador` con políticas por recurso registrables, `Decision`, `ServicioAlcance` y `RepositorioConAlcance` (RDI-001 a RDI-005). Menú calculado según los permisos (RF-002).
- **Auditoría** (RAU-001 a RAU-007, D-39):
  - Sellado diferido en una cadena SHA-256 con secuencia propia y verificación completa que detecta alteraciones.
  - Exportación diaria cifrada y sanitizador de secretos.
- **Alertas de seguridad:** cuentas atacadas desde una red, fallos de OTP, restablecimientos de 2FA e ingresos privilegiados a deshoras (RAC-031).
- **Paneles del superadministrador:** modo mantenimiento con confirmación, motivo y reautenticación (RF-MAN-001 a RF-MAN-004), y colas de trabajos (RF-Q-014).
- **«Mi cuenta»:** contraseña, 2FA, códigos de respaldo, sesiones y preferencias de tema, densidad y menú guardadas en el servidor (RF-008).
- **Pantallas de acceso** con la marca KORA en modo claro y oscuro, y correos de invitación, recuperación, contraseña cambiada y segundo factor. Los enlaces con token viajan cifrados en la cola.
- **Datos y CLI:**
  - Semilla `001_identidad_y_estructura.sql`: centro, 12 unidades, 7 roles, 49 permisos y matriz.
  - CLI `sembrar.php`, `usuarios.php` (crear-superadministrador, invitar, restablecer-2fa) y `auditoria.php`.
  - Dependencia `chillerlan/php-qrcode` 5 (ADR-005).
- **Pruebas:** 73 en total (19 nuevas), con flujos completos de extremo a extremo y la matriz de autorización por rol.

### Cambiado

- `/trabajos/{ulid}` y las demás rutas protegidas llevan al ingreso, conservando el destino, en lugar de responder 401.
- `/health/ready` acepta también la sesión del superadministrador (D-28).

### Corregido

- La regla contextual de contraseñas evaluaba cada palabra por separado y aceptaba combinaciones como «nombreapellido2026».

## [0.2.0] — Iteración 1 «Núcleo de plataforma» (sin publicar)

### Agregado

- **Cola durable** en `trabajos` (ADR-003):
  - Reclamo atómico con `skip_locked` o `actualizacion_condicional` (MySQL 5.7), reservas con vencimiento y recuperación.
  - Finalización que verifica la reserva, reintentos con espera exponencial y variación, y fallos definitivos en `trabajos_fallidos`.
  - Deduplicación por clave de idempotencia (también sin centro), outbox transaccional y trabajos por bloques con punto de control.
  - Pausa de los trabajos de negocio en mantenimiento (RF-Q-001 a RF-Q-011, RF-Q-017).
- **Trabajador** (`cli/trabajador_cron.php`) con ranuras de concurrencia, latido y presupuesto de tiempo y memoria. Se demostró con dos trabajadores concurrentes.
- **Programador** (`cli/programador.php`):
  - Limpieza horaria y diaria.
  - Alertas de cola atascada, fallidos excesivos y trabajador sin latido, con 30 min de silencio.
  - Volcado de los eventos de la CLI y rotación del registro técnico.
- **Seguimiento de trabajos:** respuesta `202 Accepted` con `Location`, y fragmento con consulta periódica creciente (2, 3, 5, 8 y 13 s) que se detiene al terminar. Rutas `/trabajos/{ulid}` (protegida) y demostración en `/sistema-diseno`.
- **Correo** con PHPMailer:
  - Transportes SMTP y archivo (local) y plantillas `alerta_operativa` y `prueba`.
  - Destinatario por id, idempotencia por Message-ID y cortacircuitos compartido en la base (RNF-008).
- **Modo mantenimiento completo** sin panel:
  - Estado en `configuracion_sistema` con versión optimista, orden seguro de escritura y comprobaciones de salida.
  - Reanudación gradual, drenaje y CLI de recuperación sin base de datos (RF-MAN-005 a RF-MAN-007, RF-MAN-012 a RF-MAN-016).
- **Salud:** `/health/ready` comprueba también la cola y los latidos del trabajador y del programador (ROB-010, ROB-011).
- **Caché versionada** en archivos, con protección contra la estampida y limpieza por tamaño (ADR-010).
- **Auditoría mínima:** eventos en `eventos_auditoria` con IP tratada y HMAC. El sellado llega en la I2.
- **Registro técnico** de cada solicitud: usuario, ruta, acción, estado, duración, memoria y consultas (RAU-008, RAU-009).
- **Intermediario `Autorizar` provisional:** deniega las rutas con permiso sin sesión (401) y aplica las funcionalidades configurables (ROB-007).
- **CLI:** `cola.php` (estado, fallidos, reintentar, cancelar, descartar; RF-Q-014), `correo.php prueba` y `cron_local.php` (simulador del cron para MAMP).
- **Pruebas:** 54 en total (27 nuevas), entre ellas los dos trabajadores concurrentes, ambas estrategias de reclamo y el ciclo de mantenimiento.

### Corregido

- Las respuestas con `Location` y estado 202 salían como 302 (PHP reescribe el estado); ahora el código se fija después de las cabeceras.
- No se usa el estado 286 de htmx: Apache lo convierte en 500.
- Un trabajo que pedía continuar se volvía a reclamar en la misma ejecución, en un ciclo continuo. Ahora la ejecución se detiene y libera sin gastar intentos los trabajos reclamados que no alcanzó a procesar.
- Los errores lanzados por un intermediario ahora salen con CSP y `X-Request-ID`.

## [0.1.0] — Iteración 0 «Fundaciones» (sin publicar)

### Agregado

- **Esqueleto MVC en PHP nativo:**
  - Controlador frontal con señal de mantenimiento previa a la base de datos.
  - Enrutador con denegación por defecto y contenedor con autowiring.
  - `Solicitud` y `Respuesta`, con ruta base para el subdirectorio local.
  - `Vista` con huellas de activos y `ManejadorErrores`: los errores salen con CSP y código de soporte.
  - Registro técnico en JSON.
- **Intermediarios:** `IdSolicitud` (X-Request-ID), `CabecerasSeguridad` (CSP con nonce por respuesta) y `Mantenimiento`.
- **Migraciones 001 a 006** del Anexo B, con su reversión y el ejecutor `cli/migrar.php` (checksum y rechazo de archivos alterados).
- **`cli/verificar_entorno.php`:** PHP, extensiones, Argon2id, motor de base de datos, SKIP LOCKED, charset, zona horaria y almacenamiento.
- **Sistema de diseño:**
  - Tokens en tres capas, modo oscuro y contraste AA verificado de forma automática.
  - Inter autoalojada y favicon con sus variantes y el manifest.
  - Vitrina en `/sistema-diseno`.
- **`retroalimentacion-ui.js` y `componentes-alpine.js`:** toasts, confirmación, esqueletos, estado vacío, errores controlados, menú lateral y selector de tema.
- **Salud:** `/health/live` y `/health/ready` (con token). Receptor de informes de la CSP.
- **Calidad:** PHPUnit 11 (27 pruebas), PHPStan nivel 6, PHP-CS-Fixer (PSR-12), ESLint y flujo de GitHub Actions con MariaDB 10.6 y MySQL 5.7.
- **Documentación:** ADR 001 a 014, fichas FP-01 a FP-11, documentación de UI, verificación de Hostinger y registro de IA.

### Cambiado

- **Identidad visual KORA (ADR-016),** tomada de `referencias/KORA_kit_identidad`:
  - Logo y símbolo en el menú lateral, favicon e íconos de aplicación generados desde el kit.
  - Paleta del sistema: azul pizarra, turquesa de acento y de acción, neutros y estados propios.
  - Las series de datos de los tableros pasan a tokens aparte, y los controles usan un borde de 3:1.
  - `npm run marca` deriva los recursos web y `npm run contraste` verifica 33 pares en modo claro y oscuro.
  - Reemplaza el favicon de hoja y la paleta verde del Anexo L.
- La aplicación se llama **KORA** (ADR-014). La cookie de sesión prevista pasa de `__Host-pcab_sesion` a `__Host-kora_sesion`.
- El desarrollo local usa el MySQL 5.7.24 de MAMP. El SQL se limita al mínimo común con MariaDB 10.6 (ADR-002).
- El prototipo y todo el material anterior se movieron a `referencias/`.
