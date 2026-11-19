# ADR-018: Operación productiva con reglas puras, bloqueo del lote y transacción única para la transformación

- Estado: Aceptado
- Fecha: 2026-11-18
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RF-029, RF-035, RF-039 a RF-061, RDI-007, RBD-015, RF-Q-006, RF-Q-010

## Contexto

La I4 trae la trazabilidad de la operación: recepciones de lotes, salidas, transformación láctea, hato por cortes, bandeja de calidad y consulta de recaudos. Hay cinco problemas de diseño:

1. **Reglas con mensajes fijos.** El prototipo tiene más de veinte reglas («La entrega supera el saldo del lote (X L)», «La recepción no puede ser menor que las cantidades ya entregadas»…). Hay que conservar los mensajes y probar cada regla sin base de datos.
2. **Sobreventa concurrente.** Dos personas pueden registrar a la vez la salida del último saldo de un lote.
3. **Transformación y recepción del derivado.** RF-048 pide crearlas juntas: si una regla de la transformación falla, la recepción no debe quedar.
4. **Visibilidad entre unidades.** El líder de Ganadería debe ver el resultado de sus transformaciones aunque el derivado lo reciba Agroindustria, sin ver nada más de esa unidad (RF-051).
5. **Interbloqueos de la cola en MariaDB.** La CI de MariaDB 10.6 mostró que dos trabajadores con `SKIP LOCKED` se interbloquean (1213) y el trabajador terminaba con un error fatal.

## Decisión

- **Reglas puras.** `ReglasOperacion` (lote, salida, transformación) y `ReglasHato` (IEP, días abiertos, fechas y semáforos) reciben los datos ya leídos y devuelven errores por campo, con los mensajes del prototipo. Las cantidades son decimales canónicos de 3 decimales: `Entrada::aUnidades()`, `sumarCon()` y `valorSalida()` operan con enteros y nunca con `float`. El valor de una salida es cantidad × precio redondeado a 2 decimales con medio hacia arriba (RBD-015), con una cota explícita contra el desbordamiento.
- **El lote se bloquea al registrar la salida.** `ServicioSalidas` lee el lote con `SELECT … FOR UPDATE` dentro de la transacción y solo entonces suma las demás salidas y valida el saldo. Dos salidas simultáneas se serializan sobre la fila del lote. La recepción del número interno se protege con la restricción única (centro, número, producto).
- **Una sola transacción para la transformación.** `ServicioTransformaciones::crear()` bloquea la entrega de leche, crea la recepción del derivado con `ServicioRecepciones::insertarEnTransaccion()` (que no abre transacción propia) y valida las reglas al final. Una `ErrorValidacion` revierte todo, incluida la recepción. Los errores de la recepción anidada se devuelven con los nombres de campo del formulario de transformación.
- **El alcance de la transformación va por el origen de la leche.** `transformaciones.unidad_id` es la unidad de origen. El listado filtra por ella con el alcance normal y agrega, con una consulta sin alcance, solo los campos del resultado del derivado (producto, cantidad, medida y metas). El listado de recepciones con un filtro de otra unidad responde 403 auditado (RF-051). En recaudos, un filtro fuera del alcance devuelve vacío (RF-035).
- **Recaudos: rol además de permiso.** `PoliticaRecaudos` exige el rol `grupo_recaudo` para cualquier escritura, aunque la matriz concediera el permiso a otro rol (RF-029). La identificación del pagador se guarda como HMAC-SHA256 y los últimos 4 dígitos, y nunca se reenvía en un 422 (RF-034).
- **Bandeja de calidad idempotente.** `ServicioCalidad::recalcular()` detecta cada tipo con una consulta de conjunto, inserta con `ON DUPLICATE KEY UPDATE` sobre la clave (entidad, id, tipo) y marca «resuelta» la incidencia cuya causa desapareció. Respeta la «aceptada sin corrección». Corre en línea o como trabajo `calidad.recalcular`.
- **El reclamo de la cola se reintenta ante 1213 y 1205** hasta cinco veces, con espera aleatoria creciente. Si se agotan, no reclama en esa vuelta y los trabajos siguen pendientes. Solo se reintenta fuera de una transacción exterior.
- **Ampliación del Anexo B.** `animales` gana `archivado_por` y `motivo_archivo`, para que el archivado común con motivo (RDL-003) funcione igual que en las demás tablas.

## Consecuencias

- Cada regla tiene su prueba unitaria con el mensaje exacto (`tests/Unitarias/ReglasOperacionTest.php`). Los límites de los semáforos se prueban uno a uno.
- La recepción del derivado creada en la transformación usa el permiso `transformaciones.gestionar`, no `recepciones.gestionar` de la unidad receptora: el diseño de RF-048 permite que Ganadería registre el derivado que recibe Agroindustria.
- `uq_transformaciones_derivado` es única también entre las archivadas. Un derivado cuya transformación se archivó no se vuelve a vincular; se registra una recepción nueva.
- El recálculo marca «resuelta» una incidencia cuando su causa desaparece; una corrección hecha fuera de la bandeja también la resuelve en el siguiente recálculo.

## Alternativas consideradas

- **Validar el saldo con una restricción en la base.** MySQL 5.7 no aplica CHECK y un CHECK no ve otras filas. Se descartó a favor del bloqueo del lote.
- **Reintentar toda transacción ante 1213 en `BaseDatos::transaccion()`.** Sería transparente, pero repetiría efectos de casos de uso que no son idempotentes. Se limita al reclamo de la cola, que sí lo es.
- **Dar a los líderes un alcance de lectura sobre la unidad receptora.** Revelaría las demás recepciones de esa unidad (RDI-007). Se descartó.
