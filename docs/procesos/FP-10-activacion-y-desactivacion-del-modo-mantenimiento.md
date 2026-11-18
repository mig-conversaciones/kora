# FP-10 Activación y desactivación del modo mantenimiento

> Ficha de proceso (RDOC-001). Fuente: SRS v1.0, sección 3.18. Se implementa en la iteración I1 (señal y CLI) e I2 (panel); ningún PR de esta funcionalidad se aprueba sin actualizar la ficha con los archivos y pruebas reales.

| Campo | Detalle |
|---|---|
| Propósito | Cerrar de forma controlada el servicio de negocio durante cambios de riesgo y reabrirlo con verificaciones. |
| Alcance | Panel Sistema → Mantenimiento, intermediario, señal mínima, CLI de recuperación, trabajador y aviso público. |
| Actores | Superadministrador (o permiso delegado documentado) y operador con SSH (recuperación). |
| Entradas | Motivo interno, título y mensaje públicos, estimación opcional, CSRF y reautenticación con 2FA. |
| Salidas | Estado persistido (base de datos y señal), 503 para los no exceptuados y pausa de los trabajos de negocio. |
| Precondiciones | Permiso; reautenticación reciente. |
| Postcondiciones | Activo: drenaje en curso. Desactivado: comprobaciones superadas y cola reanudada gradualmente. |
| Flujo principal | 1) Abrir el panel. 2) Configurar el mensaje. 3) Confirmar en un modal con reautenticación. 4) Guardar con versión optimista y escribir la señal de forma atómica. 5) El intermediario responde 503. 6) El trabajador pausa. 7) El panel muestra el drenaje. 8) Al desactivar: comprobaciones (RF-MAN-016), borrado de la señal y reanudación gradual. |
| Flujos alternos | Base de datos caída: la CLI opera sobre la señal (RF-MAN-012). Comprobación fallida: el modo se mantiene con el detalle. |
| Reglas de negocio | Alcance global; sin omisiones; rutas exceptuadas mínimas; 503 con `Retry-After` y `no-store`. |
| Errores esperados | 403 sin permiso o sin CSRF; 409 por versión concurrente; 503 del propio modo. |
| Controles de seguridad | Reautenticación con 2FA, CSRF, auditoría, señal fuera de `public` y escritura atómica. |
| Eventos de auditoría | `mantenimiento.activar`, `mantenimiento.desactivar`, `mantenimiento.configurar`, `mantenimiento.acceso_excepcional`, `mantenimiento.intento_no_autorizado`. |
| Pruebas | PI-RF-MAN-001 a PI-RF-MAN-017, PE-RF-MAN-006, PO-RF-MAN-012, PA-RF-MAN-018. |
| Archivos afectados | `MantenimientoControlador.php`, `ServicioMantenimiento.php`, `Intermediarios/Mantenimiento.php`, `cli/mantenimiento.php`, `public/index.php`, `vistas/errores/mantenimiento.php`. |

## Estado de implementación (I1, 2026-11-17)

| Parte | Estado | Archivos | Pruebas |
|---|---|---|---|
| Señal mínima, 503 por tipo de solicitud y ruta rápida sin base de datos | Hecho | `public/index.php`, `app/Intermediarios/Mantenimiento.php`, `app/Servicios/ServicioMantenimiento.php`, `app/Vistas/errores/mantenimiento.php`, `public/assets/mantenimiento/aviso.css` | `AplicacionTest::testMantenimientoDevuelve503SalvoRutasExceptuadas` |
| Estado en `configuracion_sistema` con versión optimista, orden seguro de escritura y auditoría | Hecho | `ServicioMantenimiento::activar`, `::desactivar`, `ServicioAuditoria` | `PlataformaTest::testActivarYDesactivarMantenimientoConSenalEstadoYAuditoria`, `::testMantenimientoConVersionDesactualizadaDevuelve409` |
| Comprobaciones de salida, reanudación gradual y drenaje | Hecho | `ServicioMantenimiento::verificarSalida`, `::enReanudacion`, `::estadoDrenaje`, `App\Cola\Trabajador` | `PlataformaTest`, `ColaTest::testEnMantenimientoSoloSeEjecutanTiposTecnicos` |
| CLI de recuperación sin base de datos con volcado posterior a la auditoría | Hecho | `cli/mantenimiento.php`, `ServicioAuditoria::volcarPendientes`, `App\Servicios\Programador` | `PlataformaTest::testCliDeMantenimientoSinBaseOperaSobreLaSenalYElEventoSeVuelcaDespues` |
| Panel web, reautenticación con 2FA, CSRF y acceso excepcional | Pendiente (I2) | `MantenimientoControlador.php` | — |

Operación: [runbook de cola, cron y mantenimiento](../runbooks/cola-cron-mantenimiento.md).
