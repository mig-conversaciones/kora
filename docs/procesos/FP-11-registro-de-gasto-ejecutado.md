# FP-11 Registro de gasto ejecutado

> Ficha de proceso (RDOC-001). Fuente: SRS v1.0, sección 3.18. Se implementa en la iteración I3; ningún PR de esta funcionalidad se aprueba sin actualizar la ficha con los archivos y pruebas reales.

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
