# FP-02 Importación asistida de una fuente Excel

> Ficha de proceso (RDOC-001). Fuente: SRS v1.0, sección 3.18. Se implementa en la iteración I5; ningún PR de esta funcionalidad se aprueba sin actualizar la ficha con los archivos y pruebas reales.

| Campo | Detalle |
|---|---|
| Propósito | Cargar las BBDD Excel actualizadas (Obs. 3) de forma verificable, con vista previa, sin duplicar y con posibilidad de revertir. |
| Alcance | Las 8 fuentes de RF-083, desde la carga hasta la aplicación y, si se requiere, la reversión. |
| Actores | Según la fuente (RF-092): Grupo de Recaudo, Coordinación administrativa, Líder de producción de centro, Líder de unidad y Coordinación académica. |
| Entradas | Archivo `.xlsx`, fuentes seleccionadas, clave de idempotencia y CSRF. |
| Salidas | Lote de importación con estado, resumen, errores por fila, registros creados o actualizados con procedencia e incidencias de calidad. |
| Precondiciones | Permiso de importación de la fuente; periodo abierto (RF-028); sin mantenimiento activo. |
| Postcondiciones | Lote en estado `aplicado`, versión de datos incrementada (caché invalidada) y copia previa registrada. |
| Flujo principal | 1) Carga del archivo (RF-084): almacenamiento privado, hash y lote `cargado`, con respuesta 202. 2) Trabajo `importacion.validar`: lectura en *streaming*, mapeo, normalización, reglas y filas de prueba en `filas_importacion`, hasta el estado `validado` o `con_errores`. 3) Vista previa (RF-087). 4) Confirmación con CSRF. 5) Trabajo `importacion.aplicar` con copia previa, bloques con punto de control y procedencia, hasta `aplicado`. 6) Trabajo `calidad.recalcular`. 7) Notificación al usuario. |
| Flujos alternos | Archivo ya aplicado: se informa y se enlaza el lote (RF-089). Aplicación parcial de las filas válidas si la fuente lo permite. Reversión (RF-090). Reintento tras un fallo transitorio desde el punto de control. |
| Reglas de negocio | Claves naturales por fuente (RF-089); filas fuera de alcance como error (RF-092); los conceptos genéricos no se homologan automáticamente. |
| Errores esperados | 413 por tamaño; 422 por formato o firma inválidos; errores por fila en el reporte; fallo definitivo en `trabajos_fallidos` con un mensaje sanitizado. |
| Controles de seguridad | Validación de firma y bomba ZIP (RS-011), autorización por fuente y unidad, reautenticación para revertir, sin ejecución de contenido. |
| Eventos de auditoría | `importacion.cargar`, `importacion.validar`, `importacion.aplicar`, `importacion.revertir`, `importacion.descargar_original`. |
| Pruebas | PI-RF-084 a PI-RF-093, PE-RF-083, PE-RF-087, PI-RF-Q-011, PCA-RNF-PERF-004. |
| Archivos afectados | `ImportacionesControlador.php`, `ServicioImportacion.php`, `NormalizadorFuentes.php`, `ValidadoresFuente/*`, `Trabajos/ValidarImportacion.php`, `Trabajos/AplicarImportacion.php`, `Trabajos/RevertirImportacion.php`. |
