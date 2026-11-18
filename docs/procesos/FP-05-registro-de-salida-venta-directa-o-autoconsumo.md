# FP-05 Registro de salida (venta directa o autoconsumo)

> Ficha de proceso (RDOC-001). Fuente: SRS v1.0, sección 3.18. Se implementa en la iteración I4; ningún PR de esta funcionalidad se aprueba sin actualizar la ficha con los archivos y pruebas reales.

| Campo | Detalle |
|---|---|
| Propósito | Registrar la salida de producto de un lote como venta directa o como entrega de autoconsumo vinculada a un proceso. |
| Alcance | Altas, ediciones, archivo y vinculación posterior de salidas heredadas. |
| Actores | Líder de unidad (propias) y Líder de producción de centro. |
| Entradas | Lote, fecha, cantidad, precio unitario, canal, proceso (si es autoconsumo), destino, factura y referencia. |
| Salidas | Salida con su valor calculado, existencias y avance del proceso actualizados. |
| Precondiciones | Lote con saldo; periodo abierto; proceso coherente si es autoconsumo. |
| Postcondiciones | Saldo del lote reducido; IND_001, IND_002 e IND_12 actualizados. |
| Flujo principal | 1) Elegir el lote; se heredan la unidad, el producto y la medida. 2) Bloquear la fila del lote (`FOR UPDATE`) y calcular el saldo. 3) Validar (RF-043). 4) Guardar en una transacción. 5) Incrementar la versión de datos. |
| Flujos alternos | Venta directa: no genera recaudo (RF-044). Salida que alimenta transformaciones: campos restringidos (RF-046). |
| Reglas de negocio | Cantidad no mayor que el saldo; fecha no anterior a la recepción; coherencia del proceso (unidad, medida, vigencia, producto); valor = cantidad × precio. |
| Errores esperados | 422 con mensajes del prototipo; 409 por conflicto de concurrencia. |
| Controles de seguridad | Alcance, CSRF, idempotencia y bloqueo de fila. |
| Eventos de auditoría | `salida.crear`, `salida.editar`, `salida.archivar`, `salida.vincular`. |
| Pruebas | PU-RF-043, PI-RF-043, PE-RF-043, PI-RNF-009 (concurrencia). |
| Archivos afectados | `SalidasControlador.php`, `ReglasSalida.php`, `RepositorioSalidas.php`. |
