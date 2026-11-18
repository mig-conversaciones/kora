<?php
/**
 * Página completa del estado de un trabajo (al abrir la URL de Location directamente). Variables: $contenidoFragmento.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<header class="encabezado-pagina">
    <div>
        <p class="sobretitulo">Trabajos en segundo plano</p>
        <h1 class="titulo-pagina">Estado del proceso</h1>
        <p class="texto-secundario">El proceso sigue en el servidor aunque cierres esta página.</p>
    </div>
</header>
<section class="tarjeta seccion">
    <?= $contenidoFragmento ?>
</section>
