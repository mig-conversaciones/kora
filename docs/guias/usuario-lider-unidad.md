# Líder de unidad productiva

Tu rol registra la operación diaria de **tu unidad**: recepciones, salidas, gastos ejecutados, transformaciones y, en Ganadería, el hato. Todo lo que ves está limitado a tu unidad. El tablero central y las pestañas de gestión no muestran las demás unidades del centro (observaciones 4 y 5 de la revisión del prototipo).

Antes de empezar, lee [Primeros pasos](primeros-pasos.md).

## Lo que puedes hacer

| Módulo | Qué haces |
|---|---|
| Tablero central | Consultar el de tu unidad |
| Tablero técnico de Ganadería | Consultar, si tu unidad es Ganadería |
| Producción y lotes | Registrar e importar recepciones y salidas; registrar transformaciones |
| Presupuesto y gastos | Consultar la asignación de tu unidad; registrar sus gastos ejecutados |
| Hato | Registrar animales y cortes, e importar el estado reproductivo |
| Calidad de datos | Resolver las incidencias de tu unidad |

## Registrar una recepción

1. En **Producción y lotes**, pestaña **Recepciones y lotes**, crea la recepción.
2. La unidad ya viene fijada en la tuya. KORA sugiere el número interno, por ejemplo `GAN-REC-210126-001`.
3. Escribe la fecha, el producto, la cantidad y la medida, y guarda.

## Registrar una salida

1. En la pestaña **Salidas**, crea la salida y elige el lote de origen.
2. Elige el canal: **venta directa** o **autoconsumo**. Una entrega de autoconsumo necesita su proceso (AUTO_001…), si lo tiene.
3. KORA no deja salir más que el saldo del lote.

## Transformación láctea

En **Transformación láctea** registras qué leche se usó para cada derivado (queso, manjar blanco, cortado o yogur). El lote de leche y el producto derivado se eligen de listas que ya traen los productos con su factor de equivalencia en leche. El derivado se puede registrar aunque su recepción todavía no exista (observación 6 de la revisión del prototipo).

## Gastos de la unidad

En **Presupuesto y gastos**, pestaña **Gastos ejecutados**, registras los gastos de tu unidad con su categoría y su soporte. Si una parte es académica, regístrala en su campo para que no cuente en el costo por litro.

## Hato (Ganadería)

- **Hato** lista los animales del corte vigente con su lote: producción, horra o práctica académica.
- El estado reproductivo se importa del libro `INFO_Hato` (hoja *ESTADO REP*) con su **fecha de corte**: el hato es una fotografía fechada, no una historia.
- El tablero técnico muestra la tasa de vacas en ordeño (IND_003), con un interruptor para incluir o excluir el lote de práctica académica.

## Tablero técnico de Ganadería

- **IND_003:** vacas en ordeño sobre hembras adultas, con y sin el lote de práctica.
- **IND_08:** eficiencia de la transformación láctea, global o por derivado.
- **IND_12:** días que tarda un lote en venderse o entregarse. Los lotes abiertos muestran su antigüedad y no entran en el promedio.
- **IND_17:** costo operativo por litro, sin el componente académico.
- **Salud reproductiva:** semáforos de intervalo entre partos y de días abiertos por animal.

## Preguntas frecuentes

- **No encuentro un registro de otra unidad.** Es correcto: tu rol solo ve tu unidad.
- **El desplegable de derivado está vacío.** El producto derivado necesita su factor de equivalencia en leche en el catálogo. Pídele al líder de producción de centro o a la coordinación académica que lo configure.
