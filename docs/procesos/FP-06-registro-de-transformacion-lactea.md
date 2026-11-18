# FP-06 Registro de transformación láctea

> Ficha de proceso (RDOC-001). Fuente: SRS v1.0, sección 3.18. Se implementa en la iteración I4; ningún PR de esta funcionalidad se aprueba sin actualizar la ficha con los archivos y pruebas reales.

| Campo | Detalle |
|---|---|
| Propósito | Vincular la leche entregada por autoconsumo con el derivado obtenido para calcular IND_08 (Obs. 6). |
| Alcance | Selección de la entrega de leche y del producto derivado, y vinculación o creación de la recepción del derivado. |
| Actores | Líder de unidad (origen propio) y Coordinación académica (unidades asignadas). |
| Entradas | Entrega de leche, producto derivado, litros asignados, fecha y datos de la recepción del derivado (existente o nueva). |
| Salidas | Transformación y, si aplica, recepción del derivado creada en la misma transacción. |
| Precondiciones | Entregas de leche con litros disponibles; derivados con factor configurado (RF-018); periodo abierto. |
| Postcondiciones | IND_08 recalculable con vínculo verificable. |
| Flujo principal | 1) Abrir el formulario; si faltan derivados o entregas, se muestra el estado vacío con la acción (RF-050). 2) Elegir la entrega, que muestra sus litros disponibles. 3) Elegir el derivado (4 en la semilla). 4) Elegir una recepción existente o crear una. 5) Validar (RF-049). 6) Guardar ambas en una sola transacción. |
| Flujos alternos | Recepción existente ya vinculada: error. Edición: se mantienen los vínculos. |
| Reglas de negocio | Litros acumulados no mayores que la entrega; fecha de la transformación igual a la de la recepción del derivado y no anterior a la entrega; una recepción por transformación. |
| Errores esperados | 422 con mensajes del prototipo; reversión de la transacción completa ante cualquier error. |
| Controles de seguridad | Política de transformaciones (visibilidad cruzada, RDI-007), CSRF e idempotencia. |
| Eventos de auditoría | `transformacion.crear` (con `recepcion.crear` vinculada), `transformacion.editar`, `transformacion.archivar`. |
| Pruebas | PI-RF-047, PE-RF-047, PI-RF-048, PU-RF-049, PE-RF-050. |
| Archivos afectados | `TransformacionesControlador.php`, `ServicioTransformaciones.php`, `ReglasTransformacion.php`, `vistas/transformaciones/formulario.php`. |
