<?php
/**
 * Barra de filtros de un listado: se envía sola al cambiar un campo (HTMX) y con un botón si no hay JavaScript.
 * Variables: $ruta, $campos (HTML de los campos, armado por la plantilla que la incluye).
 *
 * @var App\Nucleo\Vista $this
 */
?>
<form class="barra-filtros" hx-get="<?= $this->e($this->url($ruta)) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true" hx-trigger="change">
    <?= $campos ?>
    <noscript><button type="submit" class="boton">Filtrar</button></noscript>
</form>
