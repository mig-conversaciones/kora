# FP-03 Clasificación y conciliación de recaudos

> Ficha de proceso (RDOC-001). Fuente: SRS v1.0, sección 3.18. Se implementa en la iteración I5; ningún PR de esta funcionalidad se aprueba sin actualizar la ficha con los archivos y pruebas reales.

| Campo | Detalle |
|---|---|
| Propósito | Asignar la unidad y el producto a los recaudos de la DG que llegan sin ellos, y conciliarlos con las ventas registradas. |
| Alcance | Bandeja de clasificación, sugerencias, clasificación individual o en lote y conciliación con salidas. |
| Actores | Grupo de Recaudo (gestión). Coordinación administrativa (consulta, Obs. 2). |
| Entradas | Recaudos pendientes, reglas de homologación y salidas de venta directa con prefactura o factura. |
| Salidas | Recaudos clasificados o conciliados, informe de diferencias e IND_001 actualizado. |
| Precondiciones | Recaudos importados (FP-02); periodo abierto. |
| Postcondiciones | Estado de clasificación y conciliación actualizado; versión de datos incrementada. |
| Flujo principal | 1) Filtrar la bandeja. 2) Revisar las sugerencias. 3) Confirmar la unidad y el producto (uno o en lote, con idempotencia). 4) Buscar la venta por prefactura, factura o referencia. 5) Vincular; si los valores difieren, el estado pasa a «con diferencia». |
| Flujos alternos | Recaudo ambiguo, como «Ganadería y Granja»: queda sin distribuir con una nota. Desvincular una conciliación, con motivo. |
| Reglas de negocio | Sin prorrateo automático (RF-031); los recaudos y las ventas no se suman (RF-065); sin datos personales en claro (RF-034). |
| Errores esperados | 403 para quien no es del Grupo de Recaudo; 409 por periodo cerrado o por versión concurrente; 422 por unidad o producto inválidos. |
| Controles de seguridad | Política de recaudos, CSRF, idempotencia, alcance de centro y enmascaramiento de la identificación. |
| Eventos de auditoría | `recaudo.clasificar`, `recaudo.conciliar`, `recaudo.desconciliar`, `recaudo.alta_manual`. |
| Pruebas | PI-RF-029, PE-RF-029, PI-RF-031, PE-RF-031, PI-RF-032, PS-RF-034. |
| Archivos afectados | `ClasificacionRecaudosControlador.php`, `ServicioClasificacion.php`, `ServicioConciliacion.php`, `PoliticaRecaudos.php`. |
