# Gráficos SVG

Decisión: ADR-009. Se implementan en la I6.

- Se generan como SVG en el servidor (PHP), sin librerías JavaScript y compatibles con la CSP.
- Tipos: barras combinadas (IND_001), anillo (IND_003), velocímetro y tendencia (IND_08, IND_17).
- Colores: tokens `--serie-venta`, `--serie-autoconsumo`, `--serie-presupuesto` y `--serie-asignacion` (ADR-016), distintos de los colores de estado y siempre con leyenda; cuando haga falta, también patrones o formas (regla 5 del kit KORA). El turquesa de acento solo para indicadores puntuales.
- Accesibilidad:
  - Cada gráfico tiene `role="img"` y un título.
  - Se acompaña de una **alternativa tabular** con los mismos datos.
  - Los *tooltips* se abren al enfocar con el teclado, no solo al pasar el puntero.
- Estados: un esqueleto de gráfico durante la carga y un estado vacío cuando no hay datos. Los huecos nunca se rellenan con cifras inventadas.
