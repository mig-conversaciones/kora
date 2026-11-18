<?php
/**
 * Plantillas de esqueleto reutilizables (RUI-016). retroalimentacion-ui.js las inserta cuando la espera supera
 * 300 ms en un disparador con data-esqueleto="#esqueleto-…".
 *
 * @var App\Nucleo\Vista $this
 */
?>
<template id="esqueleto-tabla">
    <div class="esqueleto" aria-hidden="true">
        <div class="esqueleto-linea esqueleto-ancho-40"></div>
        <div class="esqueleto-linea"></div>
        <div class="esqueleto-linea"></div>
        <div class="esqueleto-linea esqueleto-ancho-80"></div>
    </div>
    <span class="sr-only">Cargando la tabla…</span>
</template>
<template id="esqueleto-kpi">
    <div class="rejilla-kpi" aria-hidden="true">
        <div class="tarjeta tarjeta-kpi"><div class="esqueleto-linea esqueleto-ancho-40"></div><div class="esqueleto-bloque"></div></div>
        <div class="tarjeta tarjeta-kpi"><div class="esqueleto-linea esqueleto-ancho-40"></div><div class="esqueleto-bloque"></div></div>
        <div class="tarjeta tarjeta-kpi"><div class="esqueleto-linea esqueleto-ancho-40"></div><div class="esqueleto-bloque"></div></div>
    </div>
    <span class="sr-only">Cargando los indicadores…</span>
</template>
