# FP-11 Registro de gasto ejecutado

> Ficha de proceso (RDOC-001). Fuente: SRS v1.0, sección 3.18. Implementada en la iteración I3: ver «Estado de implementación» al final.

| Campo | Detalle |
|---|---|
| Propósito | Registrar los gastos reales de la unidad para la ejecución por línea e IND_17 (incluye la categoría Materiales de formación de la Obs. 1). |
| Alcance | Altas, ediciones, archivo y soporte documental opcional. |
| Actores | Líder de unidad (propias) y Coordinación administrativa. |
| Entradas | Unidad, fecha, categoría, valor, componente académico, descripción, referencia del soporte y archivo opcional. |
| Salidas | Gasto registrado; ejecución por línea e IND_17 actualizados. |
| Precondiciones | Categoría activa; periodo abierto; unidad en el alcance. |
| Postcondiciones | Versión de datos incrementada. |
| Flujo principal | 1) Formulario con las tres categorías (RF-020). 2) Validar que el componente académico no supere el valor. 3) Guardar con auditoría. 4) Toast de éxito y actualización del listado filtrado por alcance. |
| Flujos alternos | Adjuntar soporte (RF-027). Periodo cerrado: 409. |
| Reglas de negocio | Materiales de formación cuenta para su línea presupuestal y no para IND_17 (S-14). |
| Errores esperados | 422 por campos; 403 por unidad fuera del alcance; 409 por periodo cerrado o versión. |
| Controles de seguridad | Alcance, CSRF, idempotencia y validación del archivo. |
| Eventos de auditoría | `gasto.crear`, `gasto.editar`, `gasto.archivar`, `gasto.adjuntar`. |
| Pruebas | PI-RF-020, PE-RF-020, PU-RF-025, PI-RF-025, PE-RF-025, PU-RF-077. |
| Archivos afectados | `GastosControlador.php`, `ReglasGasto.php`, `RepositorioGastos.php`, `vistas/gastos/*`. |

## Estado de implementación (I3, 2026-11-18)

Los nombres reales siguen [ADR-014](../adr/014-nombres-espanol.md). Los eventos de auditoría son `gastos.crear`, `gastos.actualizar`, `gastos.archivar` y `gastos.descargar_soporte`; el adjunto queda en los metadatos de `gastos.crear` o `gastos.actualizar` (`adjunto_id`).

| Parte | Archivos | Pruebas |
|---|---|---|
| Formulario con las categorías activas, validación de componente ≤ valor y fecha no futura | `ControladorFinanzas`, `ServicioGastos::guardar`, `finanzas/gasto_formulario.php` | `MaestrosFinanzasTest::testGastoConComponenteAcademicoYPeriodoCerradoConReaperturaAuditada` |
| Alcance por unidad: otra unidad → 403 auditado; gasto ajeno → 404; listado filtrado (Obs. 5) | `PoliticaAlcanceUnidad`, `AutorizacionRecurso`, `RepositorioGastos` | `MaestrosFinanzasTest::testElLiderSoloVeYGestionaLasFinanzasDeSusUnidades`, `ReglasNegocioTest::testPoliticaAlcanceUnidadDeniegaOtraUnidadYOtroCentro` |
| Periodo cerrado → 409 «Periodo cerrado» al crear, editar o archivar; reapertura con motivo y reautenticación | `ServicioPeriodos`, `finanzas/periodos.php` | `MaestrosFinanzasTest::testGastoConComponenteAcademicoYPeriodoCerradoConReaperturaAuditada` |
| Soporte PDF, PNG o JPG ≤ 5 MB con firma verificada, nombre aleatorio y descarga autorizada | `ServicioAdjuntos`, `ServicioGastos::soporte`, `ControladorFinanzas::soporte` | `MaestrosFinanzasTest::testSoporteVerificaLaFirmaYSeDescargaSoloConAcceso`; prueba de humo por Apache con `is_uploaded_file` |
| Bloqueo optimista y auditoría con antes y después | `RepositorioConAlcance::actualizar`, `ServicioAuditoria` (`RecursoAuditado`) | `MaestrosFinanzasTest` (versión vieja → 409) |
| Ejecución por línea (RF-026) con «No corresponde a ejecución CDP/RP» | `ServicioGastos::ejecucion`, `finanzas/ejecucion.php` | `MaestrosFinanzasTest::testElLiderSoloVeYGestionaLasFinanzasDeSusUnidades` |

**Idempotencia.** `retroalimentacion-ui.js` agrega `X-Idempotency-Key` a todo POST de HTMX (una clave por formulario renderizado). El intermediario `Idempotencia` devuelve la misma respuesta ante un reenvío, sin volver a registrar el gasto (RNF-010).
