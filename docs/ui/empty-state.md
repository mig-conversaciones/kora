# Estado vacío

Nunca se muestra una tabla vacía (RUI-014). El estado vacío dice qué pasó y sugiere una acción.

- **Respuesta 200 (preferida, D-08):** el servidor devuelve `parciales/estado_vacio` con título, descripción y una acción opcional. Desde un controlador se usa `$this->estadoVacio($solicitud, $titulo, $descripcion, ['texto' => …, 'url' => …])`.
- **Respuesta 204:** se reserva para acciones sin contenido nuevo. Si el disparador declara `data-estado-vacio="#plantilla-…"`, `retroalimentacion-ui.js` inserta ese `<template>` en el destino.

## Redacción

| Parte | Ejemplo |
|---|---|
| Título | «No hay gastos para estos filtros» |
| Descripción | «Cambia los filtros o registra un gasto ejecutado de tu unidad» |
| Acción | «Registrar gasto», solo si el perfil puede hacerlo |
