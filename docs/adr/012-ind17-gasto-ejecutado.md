# ADR-012: IND_17 con gasto ejecutado y referencia presupuestada

- Estado: Propuesto (validar S-11)
- Fecha: 2026-11-17
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RF-077, D-20

## Contexto

La guía calcula IND_17 con los rubros presupuestados (45 M + 80 M) divididos entre litros; el prototipo usa el gasto ejecutado.

## Decisión

Se usa el gasto ejecutado (costo real) menos el componente académico de las vacas de práctica, dividido entre los litros recibidos; el costo presupuestado por litro se muestra como referencia identificada.

## Alternativas consideradas

- Solo el presupuesto: no refleja el costo real.

## Consecuencias

- Requiere registrar gastos ejecutados (I3).
- Pendiente de validación con la contraparte (S-11, en la I5).

## Evolución

Si la contraparte exige la fórmula de la guía, se muestran ambas con la diferencia explicada.
