# Tarjetas bento y KPI

## Rejilla

- `.rejilla-bento` tiene 4 columnas en escritorio, 2 hasta 1024 px y 1 hasta 640 px.
- Tamaños: 1×1 por defecto, `.bento-2x1` (dos columnas) y 2×2, que se agregará con los tableros (I6).
- Jerarquía: el indicador principal ocupa la tarjeta más grande.

## Tarjeta KPI

```html
<article class="tarjeta tarjeta-kpi kpi-venta">
  <h3 class="kpi-etiqueta">Venta directa</h3>
  <p class="kpi-valor">$ 3.265.000</p>
  <p class="kpi-nota">61 recaudos · fuente y fecha de corte</p>
</article>
```

- **Borde superior:** realce interior de 3 px con el color de la serie: `.kpi-venta` (`--serie-venta`), `.kpi-autoconsumo` (`--serie-autoconsumo`) y `.kpi-presupuesto` (`--serie-presupuesto`, azul pizarra).
- **Nota:** siempre indica la fuente o el corte. No se presentan cifras de periodos distintos como sincronizadas.
- **Vista previa al pasar el puntero o enfocar:** se define con los tableros (I6).
