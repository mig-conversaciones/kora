# Esqueleto de carga

Requisitos: RUI-013 y RUI-016.

## Funcionamiento

1. El disparador declara `data-esqueleto="#esqueleto-tabla"` (o `#esqueleto-kpi`). Las plantillas están en `app/Vistas/parciales/esqueletos.php`.
2. Mientras dura la solicitud, el destino recibe `aria-busy="true"`.
3. Si la espera supera **300 ms**, el `<template>` se inserta en el destino y se anuncia «Cargando información…» en `#anuncio-estado`.
4. Si pasan **8 s**, el esqueleto se reemplaza por un mensaje de progreso lento con el botón «Recargar esta sección».
5. Si la respuesta es exitosa, htmx intercambia el contenido. Si hay error, se restaura el contenido anterior y el error se muestra aparte.

## Variantes

| Plantilla | Uso |
|---|---|
| `#esqueleto-tabla` | Tabla |
| `#esqueleto-kpi` | Tarjetas KPI |
| Gráfico | Se agrega en la I6 |

## Movimiento reducido

Con `prefers-reduced-motion`, la animación de pulso se desactiva.

## Prueba manual

En `/sistema-diseno`, botón «Cargar lotes (1,2 s)»: el servidor espera 1,2 s a propósito. La prueba E2E con Playwright llega en la I1.
