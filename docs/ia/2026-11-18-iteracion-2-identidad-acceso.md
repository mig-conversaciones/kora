# Registro de entrega asistida por IA — Iteración 2 «Identidad y acceso»

- **Fecha:** 2026-11-18
- **Herramienta y modelo:** Claude Code (extensión de VS Code), modelo Claude Opus 5.5 (`claude-opus-5-5`)
- **Responsable humano:** líder técnico del proyecto, que revisa y aprueba
- **Requisitos:** RAC-001 a RAC-014 y RAC-016 a RAC-031; RS-002 a RS-008; RS-013; RAU-001 a RAU-005 y RAU-007; RDI-001 a RDI-005; RF-MAN-001 a RF-MAN-004; RF-Q-014; RF-002 (parcial); RF-008; RNF-010
- **Ficha:** FP-01 (actualizada)

## Objetivo

Entregar el hito M2, «acceso seguro con 2FA demostrado». Incluye:

- invitación y activación, Argon2id, política NIST y lista de bloqueadas;
- sesiones en la base de datos, recuperación, TOTP y códigos de respaldo;
- límites de frecuencia, CSRF, reautenticación, RBAC/ABAC con AlcanceDatos y matriz de autorización probada;
- auditoría sellada y panel de mantenimiento.

## Supuestos

- **Gestión de usuarios desde la interfaz:** queda para la I3, según el plan («UI de usuarios y auditoría»). En la I2 las cuentas se crean por la CLI con invitación.
- **Semilla parcial:** se siembra la parte de la semilla de producción que no depende de las tablas de la I3 (categorías de gasto y parámetros).
- **Lista de contraseñas comunes:** la lista NCSC de 100.000 (SecLists), filtrada a 8 o más caracteres. No se envió ningún dato propio.

## Decisiones

- **Capa de sesión propia** sobre la tabla `sesiones`, en lugar del manejador nativo de PHP, con las mismas garantías ([ADR-006](../adr/006-sesiones-en-bd.md)). Así `Set-Cookie` pasa por `Respuesta` y el flujo completo se prueba sin servidor.
- **Cookie en local:** `kora_sesion` con la ruta base, porque el prefijo `__Host-` exige HTTPS y `Path=/`. En producción, `__Host-kora_sesion`.
- **Opción de ruta `autenticada`:** para las rutas de la cuenta propia, sin un permiso RBAC artificial. El enrutador sigue exigiendo `publica`, `autenticada` o un permiso.
- **Destino seguro al redirigir:** sin sesión, las rutas protegidas redirigen al ingreso conservando el destino (303, o HX-Redirect para HTMX). `rutaInternaSegura` impide las redirecciones abiertas.
- **Tokens cifrados en la cola:** los enlaces con token de los correos viajan cifrados dentro de la carga del trabajo (`datos_cifrados`), para que la tabla `trabajos` no guarde secretos en claro (RAU-007).
- **Auditoría:** sellado según D-39. La verificación detecta alteraciones, huecos y un control de cadena que no coincide. El lote diario se cifra con el llavero.
- **Política contextual de contraseñas:** se retiran juntas todas las palabras de contexto. Lo exigió una prueba que encontró una debilidad («nombreapellido2026» pasaba).
- **Fuera de alcance de la I2:** la vista de soporte como otro usuario (RF-013), la API de integración y CAPTCHA (desactivado por funcionalidad).

## Archivos principales

| Grupo | Archivos |
|---|---|
| Núcleo | `Sesion`, `Cifrador`, `Respuesta` (cookies y HX-Redirect), `Vista` (CSRF, menú por permisos, plantilla de acceso), `Enrutador` (`autenticada`), `Migrador::sembrar`, `Aplicacion` (cadena completa) |
| Intermediarios | `IniciarSesion`, `LimitarTasa`, `VerificarCsrf`, `Autenticar`, `Idempotencia`, `Autorizar` (definitivo), `Mantenimiento` (acceso excepcional) |
| Políticas | `UsuarioSesion`, `Autorizador`, `Decision`, `Politica`, `AlcanceDatos`; `RepositorioUsuarios`, `RepositorioConAlcance` |
| Servicios | `ServicioAutenticacion`, `ServicioSegundoFactor`, `ServicioCuentas`, `ServicioContrasenas`, `ServicioTotp`, `ServicioCodigosRespaldo`, `ServicioTokensCuenta`, `ServicioLimiteTasa`, `ServicioQr`, `ServicioNotificacionesCuenta`, `ServicioAlcance`, `ServicioAuditoria` (sellado y verificación), `Programador` (seguridad y auditoría) |
| Trabajos | `VerificarCadena`, `ExportarLoteAuditoria`, `EnviarCorreo` (datos cifrados) |
| Web | `ControladorAcceso`, `ControladorCuenta`, `ControladorMantenimiento`, `ControladorColas`; vistas `acceso/*`, `cuenta/*`, `sistema/*`, `diseno/acceso.php`, `correos/*`; `config/rutas.php`, `config/menu.php`, `config/seguridad.php`, `config/app.php` |
| Datos y CLI | `bd/semillas/001_identidad_y_estructura.sql`, `recursos/listas/contrasenas-comunes.txt`, `cli/sembrar.php`, `cli/usuarios.php`, `cli/auditoria.php` |
| Pruebas | `tests/Integracion/IdentidadTest.php`, `NavegadorPrueba.php`, `tests/Unitarias/SeguridadTest.php` |
| Documentación | ADR-006, FP-01, `docs/runbooks/acceso-y-cuentas.md`, `README.md`, `CHANGELOG.md` |

## Validaciones ejecutadas

Todas son resultados reales en MAMP: PHP 8.3.36 y MySQL 5.7.24.

- **`composer verificar`:** PSR-12 sin cambios, PHPStan nivel 6 sin errores y PHPUnit con 73 pruebas y 434 aserciones.
- **Flujos de extremo a extremo en `IdentidadTest`:**
  - activación, enrolamiento con la clave de la página, panel y mantenimiento con acceso excepcional auditado;
  - código TOTP reutilizado rechazado y el siguiente aceptado; código de respaldo de un solo uso;
  - mensaje genérico y bloqueo tras 5 fallos; CSRF sin token y con Origin ajeno;
  - líder de unidad con 403 auditado y alcance limitado a su unidad;
  - recuperación que cierra las otras sesiones; contraseñas débiles rechazadas;
  - expiración a los 31 min; idempotencia con respuesta repetida;
  - cadena de auditoría íntegra y luego alterada (detectada en la secuencia exacta);
  - matriz de autorización de 5 roles × 2 rutas.
- **Unitarias:** vectores de la RFC 6238, ventana y anti-reutilización, cifrado con contexto y rotación, política NIST, denegación por defecto, alcance vacío como `1 = 0`, sanitizador y redirecciones seguras.
- **Flujo real con curl contra Apache:**
  - la invitación por la CLI generó un `.eml` con el enlace;
  - la cookie quedó `HttpOnly; SameSite=Lax; Path=/dashboard_laila/`;
  - activación 303 a `/2fa/enrolar`, QR presente, 10 códigos de respaldo;
  - paneles y cuenta en 200; preferencias 204, con el tema oscuro aplicado en el primer pintado;
  - salida 303 al ingreso; ruta protegida tras salir 303 con `volver`; CSRF falso 403.
- **Captura** de la pantalla de ingreso en modo claro y oscuro.
- **Activos:** JS de 39,9 KB y CSS de 7,4 KB comprimidos con gzip.

## Riesgos

- **Cookie local.** No es `__Host-` ni `Secure`. Es aceptable solo en local; en producción la configuración lo cambia automáticamente al usar `https://`.
- **Sesiones anónimas.** El GET de los formularios públicos crea una sesión anónima. Vence a la hora y la limpia la limpieza periódica, pero un tráfico anónimo alto genera filas: se medirá en la PCA (I7).
- **Sin pruebas en navegador.** El aviso de expiración, la confirmación del modal y el foco no tienen pruebas en navegador (Playwright, pendiente).
- **Supuestos de seguridad.** S-04 (roles), S-05 (2FA por rol) y S-23 siguen pendientes de validar con la contraparte.

## Pendientes

- **I3:** gestión de usuarios, roles y unidades desde la interfaz (RF-009 a RF-012), consulta de la auditoría (RAU-001), selector de unidad (RF-004) y menú agrupado por «Mi gestión» y «Consulta» (RF-002 completo).
- **Iteraciones posteriores:** vista de soporte (RF-013), archivo de la auditoría (RAU-006) y verificación de integridad (I7).

## Aprobación humana

Pendiente: revisión del líder técnico y demostración del hito M2 a la contraparte.
