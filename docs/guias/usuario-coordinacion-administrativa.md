# Coordinación administrativa

Tu rol controla el presupuesto del centro y su ejecución: asignaciones por unidad, gastos ejecutados, procesos de autoconsumo y cierres de periodo. También conduces la carga inicial del piloto. Consultas los recaudos, la producción y los tableros, pero **no registras recaudos** (observación 2 de la revisión del prototipo): eso le corresponde al Grupo de Recaudo.

Antes de empezar, lee [Primeros pasos](primeros-pasos.md).

## Lo que puedes hacer

| Módulo | Qué haces |
|---|---|
| Tablero central | Consultar y exportar, con todo el centro o una unidad |
| Presupuesto y gastos | Crear, editar, archivar e importar asignaciones y gastos ejecutados |
| Procesos de autoconsumo | Crear, editar e importar los cupos de cada proceso |
| Cierres de periodo | Cerrar y reabrir periodos |
| Recaudos, producción, catálogos y calidad | Consultar |
| Importación de BBDD | Importar el presupuesto, los cupos y los gastos; conducir y firmar la carga inicial |

## Tablero central

1. Abre **Tablero central** en *Vista general*.
2. Elige la **vigencia** y, si quieres, el periodo, la unidad, el producto y el canal.
3. Lee las tarjetas: asignación, recaudo de venta directa, autoconsumo, total realizado, cobertura y saldo.
4. Para ver el detalle técnico de Ganadería, usa el enlace al tablero de la unidad; los filtros se conservan.

> El presupuesto es anual: al filtrar por meses no se prorratea. Las ventas por transferencia se muestran para conciliar, pero no se suman al recaudo.

## Asignaciones presupuestales

1. Abre **Presupuesto y gastos** y la pestaña **Asignaciones**.
2. Crea una asignación por unidad y línea de gasto (mantenimiento, alimentación, materiales de formación…).
3. La pestaña **Ejecución por línea** compara lo asignado con lo ejecutado.

Para cargar todo el presupuesto de una vez, usa **Importación de BBDD** con el libro `BBDD_DATOS ADMON` (hoja *Ingresos - Gastos*) e indica la vigencia.

## Gastos ejecutados

1. En **Gastos ejecutados**, crea el gasto con su unidad, su categoría, la fecha y el valor.
2. Si una parte del gasto es académica, regístrala en su campo: así no infla el costo operativo por litro (IND_17).
3. Adjunta el soporte: PDF, PNG o JPEG de hasta 5 MB.

La categoría **Materiales de formación** existe porque la pidió la coordinación en la revisión del prototipo (observación 1). No cuenta en el costo operativo de IND_17.

## Procesos de autoconsumo

Cada proceso (AUTO_001, AUTO_002…) tiene una unidad de origen, un destino, un producto, una cantidad, una medida y un valor. Las entregas que registran las unidades se descuentan del cupo y el tablero central muestra el avance (IND_002).

## Cierres de periodo

En **Cierres de periodo** cierras un mes cuando sus registros están revisados. Un periodo cerrado no admite registros nuevos ni cambios en ese mes. Reabrirlo exige un motivo, que queda en la auditoría.

## Importaciones

1. En **Importación de BBDD**, carga el libro de Excel (.xlsx de hasta 10 MB).
2. KORA reconoce sus hojas y te deja elegir las fuentes que tu rol puede importar.
3. Cada fuente se valida en segundo plano. Nada cambia en la base hasta que la apliques.
4. Revisa la vista previa: filas nuevas, filas actualizadas, filas sin cambios y filas con error. El reporte de errores se descarga en Excel con la fila exacta.
5. **Aplica** el lote. Mientras nada dependa de él, se puede revertir con un motivo.

El **Acta de carga** reúne las cifras de control de cada lote: filas y sumas del archivo frente a lo que quedó en la base.

## Carga inicial del piloto

La producción empieza con una carga guiada de los libros actualizados, con un acta que firman la coordinación o el Grupo de Recaudo. Los pasos están en [Carga inicial del piloto](carga-inicial.md).

## Preguntas frecuentes

- **No veo el botón para crear un recaudo.** Es correcto: tu rol los consulta, no los registra.
- **El tablero no muestra un gasto que acabo de crear.** Los tableros se actualizan al guardar. Si el gasto está en un periodo cerrado o archivado, no cuenta.
- **No puedo archivar una asignación.** Tiene gastos o procesos que dependen de ella; KORA te dice cuáles.
