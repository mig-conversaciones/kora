# Tabla densa

## Estructura

```html
<div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="…">
  <table class="tabla">
    <caption class="sr-only">…</caption>
    …
  </table>
</div>
```

- **Contenedor:** se desplaza en horizontal y es enfocable con el teclado.
- **Encabezado:** `th scope="col"` sobre fondo tenue. La columna ordenada declara `aria-sort`.
- **Cifras:** la clase `.numero` alinea a la derecha con cifras tabulares.
- **Densidad:** `data-densidad="compacta"` en `<html>` reduce el relleno vertical. Es una preferencia del usuario desde la I2.
- **Paginación:** sin `COUNT` costoso. El repositorio pide una fila extra y responde con `X-Has-More` (RNF-PERF-010).
- **Filtros:** van en un formulario con `hx-get`, `hx-swap="morph:innerHTML"` y `hx-push-url="true"` (sección 4.3.3 del SRS).
- **Selección y acciones masivas:** se definen con el primer listado real (I3).
