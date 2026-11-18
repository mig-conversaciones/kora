# FP-01 Autenticación con segundo factor

> Ficha de proceso (RDOC-001). Fuente: SRS v1.0, sección 3.18. Se implementa en la iteración I2; ningún PR de esta funcionalidad se aprueba sin actualizar la ficha con los archivos y pruebas reales.

| Campo | Detalle |
|---|---|
| Propósito | Verificar la identidad del usuario y abrir una sesión segura con el nivel de autenticación que exige su rol. |
| Alcance | `/ingresar`, `/2fa`, `/salir`, enrolamiento de 2FA y regeneración de la sesión. No incluye la recuperación (FP de la cuenta, RAC-006). |
| Actores | Todos los usuarios. Superadministrador y Administrador con 2FA obligatoria. |
| Entradas | Correo y contraseña; código TOTP o código de respaldo; token CSRF. |
| Salidas | Sesión `__Host-kora_sesion` con nivel `contrasena` o `2fa`; redirección a la vista inicial del rol (RF-001). |
| Precondiciones | Cuenta activa e invitación completada. Si el rol exige 2FA y no está configurada, se fuerza el enrolamiento. |
| Postcondiciones | ID de sesión regenerado, registro en `sesiones`, contador de fallos reiniciado. |
| Flujo principal | 1) GET del formulario con CSRF. 2) POST con las credenciales; verificación con Argon2id en tiempo homogéneo. 3) Si exige 2FA, se crea una sesión parcial y se pide el código. 4) El TOTP se verifica con ventana de ±1 y anti-repetición. 5) Se regenera el ID y se fija el nivel `2fa`. 6) Redirección a la vista inicial. |
| Flujos alternos | Código de respaldo en lugar del TOTP; enrolamiento forzado; contraseña que requiere un nuevo hash (`password_needs_rehash`). |
| Reglas de negocio | Mensajes genéricos (RAC-005); expiración por inactividad de 30 o 15 min y absoluta de 12 u 8 h (RAC-019). |
| Errores esperados | 401 genérico ante credenciales inválidas; 429 con `Retry-After` por límite (RAC-029, RAC-013); 403 por cuenta desactivada (mensaje genérico); 503 en mantenimiento salvo la ruta exceptuada. |
| Controles de seguridad | Argon2id, CSRF, cookie `__Host-`, regeneración del ID, límites de frecuencia, verificación de Origin y auditoría. |
| Eventos de auditoría | `sesion.iniciar` (éxito o fallo), `sesion.2fa` (éxito o fallo), `sesion.regenerar`, `sesion.bloqueo_temporal`, `sesion.cerrar`. |
| Pruebas | PI-RAC-001, PI-RAC-005, PE-RAC-001, PI-RAC-012, PI-RAC-013, PS-RAC-018. |
| Archivos afectados | `AutenticacionControlador.php`, `ServicioAutenticacion.php`, `ServicioTotp.php`, `ManejadorSesionBd.php`, `LimitadorTasa.php`, `vistas/autenticacion/*.php`. |

## Estado de implementación (I2, 2026-11-18)

| Parte | Archivos | Pruebas |
|---|---|---|
| Ingreso con mensajes genéricos, tiempo homogéneo y límites por cuenta e IP | `ServicioAutenticacion::ingresar`, `ServicioLimiteTasa`, `ControladorAcceso`, `acceso/ingresar.php` | `IdentidadTest::testMensajeGenericoYLimiteDeIntentosPorCuenta` |
| Segundo factor TOTP con anti-reutilización y códigos de respaldo | `ServicioTotp`, `ServicioCodigosRespaldo`, `ServicioAutenticacion::verificarCodigo` | `SeguridadTest` (vectores RFC 6238), `IdentidadTest::testIngresoCon2faRechazaCodigoReutilizadoYAceptaElSiguiente`, `::testCodigoDeRespaldoSirveUnaSolaVez` |
| Enrolamiento con QR del servidor y semilla cifrada | `ServicioSegundoFactor`, `ServicioQr`, `Cifrador`, `acceso/enrolar.php` | `IdentidadTest::testSuperadministradorActivaEnrolaY2faYAccedeAlSistema` |
| Sesión del servidor, regeneración, expiración y revocación | `App\Nucleo\Sesion`, `IniciarSesion`, `Autenticar` | `IdentidadTest::testSesionVencidaPorInactividad`, `::testRecuperacionCambiaLaContrasenaYCierraTodasLasSesiones` |
| CSRF con Origin y Sec-Fetch-Site | `VerificarCsrf` | `IdentidadTest::testCsrfObligatorioEnLasEscrituras` |
| Reautenticación de 5 min para operaciones sensibles | `Autorizar` (opción `reautenticacion`), `ControladorAcceso::reautenticar` | `IdentidadTest` (panel de mantenimiento) |

**Cookie local.** La cookie se llama `kora_sesion` en MAMP y `__Host-kora_sesion` en producción ([ADR-006](../adr/006-sesiones-en-bd.md)).
