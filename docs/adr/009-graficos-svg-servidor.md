# ADR-009: Gráficos SVG generados en el servidor

- Estado: Aceptado
- Fecha: 2026-11-17
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RUI-017, D-31

## Contexto

Las librerías de gráficos en JavaScript aumentan el peso y complican la CSP.

## Decisión

Los gráficos (barras combinadas, anillo, velocímetro, tendencia) se generan como SVG en PHP, igual que en el prototipo, con alternativa tabular y tooltips accesibles.

## Alternativas consideradas

- Chart.js o ECharts: peso adicional y estilos en línea incompatibles con la CSP.

## Consecuencias

- Los colores de los gráficos usan los tokens semánticos (verde, ámbar, azul).
- Se implementa en la I6.

## Evolución

Si se requieren gráficos interactivos complejos, evaluar una librería compatible con la CSP en un ADR nuevo.
