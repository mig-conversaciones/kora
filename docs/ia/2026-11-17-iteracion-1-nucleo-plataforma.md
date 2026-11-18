# Registro de entrega asistida por IA — Iteración 1 «Núcleo de plataforma»

- **Fecha:** 2026-11-17
- **Herramienta y modelo:** Claude Code (extensión de VS Code), modelo Claude Opus 5.5 (`claude-opus-5-5`)
- **Responsable humano:** líder técnico del proyecto, que revisa y aprueba
- **Requisitos:** RF-Q-001 a RF-Q-013, RF-Q-015 a RF-Q-019 (RF-Q-014 por CLI), RF-MAN-005 a RF-MAN-007, RF-MAN-012 a RF-MAN-016, RAU-008, RAU-009, ROB-007, ROB-010, ROB-011, RNF-008
- **Fichas:** FP-10 (actualizada) y FP-09 (base de la cola)

## Objetivo

Entregar la infraestructura transversal de la I1 (05-planificacion):

- cola durable, trabajador y programador por cron, seguimiento de trabajos;
- correo con cortacircuitos, salud completa, registro JSON correlacionado y caché versionada;
- señal e intermediario de mantenimiento y funcionalidades configurables.

Hito: «cola demostrada con dos trabajadores».

## Supuestos

- **Sin sesiones hasta la I2.** Por eso:
  - el panel web de colas (RF-Q-014) se entrega como CLI;
  - el panel de mantenimiento llega en la I2;
  - `/trabajos/{ulid}` queda protegido por un intermediario `Autorizar` provisional que deniega con 401.
- **Auditoría por inserción.** Se inserta en `eventos_auditoria` sin sellar; el sellado con cadena de hash es de la I2 (D-39).
- **MySQL 5.7 en local** con la estrategia de reclamo `actualizacion_condicional` (ADR-002).

## Decisiones

- **Código base del SRS (04-04a, 04-04b, 06-03, 04-06), con estas adaptaciones:**
  - el ciclo del trabajador pasa a la clase `App\Cola\Trabajador` para poder probarlo;
  - el despachador encola sin `Autorizador` (llega en la I2);
  - el estado de un trabajo se presenta con el trait `RespuestaEstadoTrabajo`.
- **Deduplicación sin centro.** Se busca la clave antes de insertar, porque el índice único no cubre los NULL.
- **Sin código 286.** Apache lo convierte en 500. El fragmento terminal no tiene disparador, así que la consulta periódica se detiene igual.
- **Estado HTTP después de las cabeceras.** `Respuesta::enviar` fija el estado al final para conservar el 202 con `Location`; antes PHP lo convertía en 302.
- **Continuación de trabajos por bloques.** El trabajador se detiene tras una continuación y libera sin gastar intentos los trabajos reclamados que no procesó. Corrige un ciclo continuo que el código de referencia también tenía con el corte por tiempo.
- **Errores de intermediarios** convertidos en cada eslabón: salen con CSP y `X-Request-ID`.
- **Transporte de correo «archivo»** para el desarrollo local (MAMP no tiene servidor SMTP) y PHPMailer 6.9 (ADR-005).
- **Ruta del latido configurable** en `config/mantenimiento.php`, para aislar las pruebas.
- **Gancho `KORA_BD_PRUEBAS`** en la CLI, restringido a bases `*_pruebas`, para probar procesos concurrentes.

## Archivos creados o modificados

| Grupo | Archivos |
|---|---|
| Cola | `app/Cola/*` (ConductorCola, ConductorColaMysql, DespachadorCola, Trabajador, ContextoTrabajo, CortaCircuitos, errores, ManejadorTrabajo), `config/cola.php` |
| Trabajos | `app/Trabajos/EnviarCorreo.php`, `LimpiezaPeriodica.php`, `DemostracionProgreso.php` |
| Servicios | `ServicioMantenimiento` (completo), `ServicioAuditoria`, `ServicioCorreo`, `CorreoRechazado`, `ServicioMonitorCola`, `Programador`, `ServicioAdministracionCola` |
| Núcleo | `Cache.php`, `Respuesta::enviar`, `Aplicacion` (Autorizar y conversión por eslabón), `ManejadorErrores` (401) |
| Web | `Intermediarios/Autorizar.php`, `IdSolicitud.php` (RAU-008), `ControladorSalud`, `ControladorTrabajos`, `RespuestaEstadoTrabajo`, `ControladorSistemaDiseno` (demostración), vistas `trabajos/*` y `correos/*`, `config/rutas.php`, `config/app.php`, `config/correo.php`, estilos de seguimiento |
| CLI | `trabajador_cron.php`, `programador.php`, `mantenimiento.php`, `cola.php`, `correo.php`, `cron_local.php`, `arranque.php` |
| Pruebas | `tests/Integracion/ColaTest.php`, `PlataformaTest.php`, `AplicacionTest.php` (ampliada) |
| Documentación | `docs/runbooks/cola-cron-mantenimiento.md`, FP-10, ADR-002, `CHANGELOG.md`, `README.md`, `.env.example` |

## Validaciones ejecutadas

Todas son resultados reales en MAMP: PHP 8.3.36 y MySQL 5.7.24.

- **`composer verificar`:** PSR-12 sin cambios, PHPStan nivel 6 sin errores y PHPUnit con 54 pruebas y 290 aserciones.
- **Hito «cola con dos trabajadores»:** 2 procesos `trabajador_cron.php` concurrentes y 24 trabajos. La suma de procesados fue exactamente 24, los dos procesos participaron y todos los trabajos terminaron con un solo intento.
- **Flujo completo por Apache:**
  - `POST` de la demostración devuelve 202 con `Location` y `Retry-After: 2`; el doble clic con la misma clave devuelve el mismo trabajo.
  - El trabajador lo completa y la consulta HTMX final devuelve 200 con el toast y sin disparador.
  - `/trabajos/{ulid}` responde 401.
  - Con JSON también se obtiene 202.
- **CLI:**
  - El programador encoló la limpieza horaria y la diaria.
  - El correo de prueba se procesó y quedó como `.eml`.
  - `cola.php estado` muestra conteos y latidos.
  - El ciclo de mantenimiento dio 503 en la web, 200 en `/health/live`, drenaje «listo» y desactivación con las 3 comprobaciones superadas. Después `/health/ready` respondió «listo» con 7 de 7 comprobaciones.
- **`cron_local.php`:** en Windows lanzó el trabajador y el programador en segundo plano, que dejaron sus latidos.
- **Errores encontrados y corregidos durante las pruebas:** el 202 que salía como 302, el 286 que Apache convierte en 500, el ciclo continuo de continuaciones y los 401 sin CSP.

## Riesgos

- **Trabajos sin centro.** Su deduplicación tiene una ventana de carrera mínima, aceptada para trabajos técnicos.
- **Cron real sin probar.** No se probó el cron de hPanel (duración máxima de los procesos y frecuencia de 1 minuto): pendiente con la cuenta de Hostinger (riesgo R6).
- **Sin pruebas en navegador.** La consulta periódica, la pausa con la pestaña oculta y el toast final no tienen todavía pruebas E2E con Playwright. Se verificaron por contrato (cabeceras y fragmentos).
- **Demostración pública.** La demostración de la cola (`POST /sistema-diseno/demostracion/trabajo`) no tiene CSRF hasta la I2. Solo existe fuera de producción y limita a 20 los trabajos pendientes.

## Pendientes

- **I2:** panel web de colas y de mantenimiento con 2FA, `despachar()` con el Autorizador y `Autorizar` definitivo, sellado de la auditoría y acceso excepcional en mantenimiento.
- **Iteraciones posteriores:** cada tipo de trabajo de la v1 se registra con su manejador (importación en I5; exportación, PDF y caché en I6; auditoría y seguridad en I2 e I7).
- **Hostinger:** configurar el cron en hPanel según el runbook y verificar `CORREO_*` contra el SMTP del dominio.

## Aprobación humana

Pendiente: revisión del líder técnico.
