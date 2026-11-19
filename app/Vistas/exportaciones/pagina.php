<?php
/**
 * Página completa de una exportación (al abrir su URL de seguimiento directamente). Variables: $contenidoFragmento.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<header class="encabezado-pagina">
    <div>
        <p class="sobretitulo">Exportaciones</p>
        <h1 class="titulo-pagina">Tu exportación</h1>
        <p class="texto-secundario">El archivo se prepara en el servidor aunque cierres esta página y queda disponible durante 24 horas.</p>
    </div>
</header>
<section class="tarjeta seccion">
    <?= $contenidoFragmento ?>
</section>
