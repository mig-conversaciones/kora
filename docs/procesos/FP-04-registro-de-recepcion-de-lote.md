# FP-04 Registro de recepción de lote

> Ficha de proceso (RDOC-001). Fuente: SRS v1.0, sección 3.18. Se implementa en la iteración I4; ningún PR de esta funcionalidad se aprueba sin actualizar la ficha con los archivos y pruebas reales.

| Campo | Detalle |
|---|---|
| Propósito | Ingresar a inventario un lote de producto terminado con su número interno trazable. |
| Alcance | Altas, ediciones, archivo y restauración de recepciones. |
| Actores | Líder de unidad (propias) y Líder de producción de centro. |
| Entradas | Unidad, fecha, producto, cantidad, precio sugerido, responsable y número interno (sugerido). |
| Salidas | Recepción con saldo inicial igual a la cantidad. |
| Precondiciones | Producto activo con medida; periodo abierto; unidad en el alcance. |
| Postcondiciones | Existencias disponibles para salidas y transformaciones; versión de datos incrementada. |
| Flujo principal | 1) Abrir el formulario (fragmento modal). 2) Sugerir el número interno `{PREFIJO}-REC-{DDMMAA}-{NNN}`. 3) Validar (RF-039 y RF-040). 4) Guardar en una transacción con `version` y auditoría. 5) Toast de éxito con `HX-Trigger` y actualización del listado. |
| Flujos alternos | Edición con salidas: se restringen los campos (RF-040). Archivo bloqueado si tiene salidas activas. |
| Reglas de negocio | Medida igual a la del catálogo; número interno único por producto; cantidad mayor o igual a lo entregado. |
| Errores esperados | 422 con mensajes por campo; 409 por conflicto de versión o consecutivo duplicado (se reintenta con el siguiente). |
| Controles de seguridad | Alcance de unidad, CSRF, lista de campos permitidos e idempotencia. |
| Eventos de auditoría | `recepcion.crear`, `recepcion.editar`, `recepcion.archivar`, `recepcion.restaurar`. |
| Pruebas | PU-RF-039, PU-RF-040, PI-RF-039, PE-RF-039. |
| Archivos afectados | `RecepcionesControlador.php`, `ReglasRecepcion.php`, `RepositorioRecepciones.php`, `vistas/recepciones/*`. |
