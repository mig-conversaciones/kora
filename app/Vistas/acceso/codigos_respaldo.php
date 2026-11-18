<?php
/**
 * Códigos de respaldo recién generados (RAC-011): se muestran una sola vez. Variables: $codigos, $continuar.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<h1 id="titulo-acceso" class="acceso-titulo">Guarda tus códigos de respaldo</h1>
<div class="alerta alerta-advertencia">
    <?= $this->parcial('parciales/icono', ['nombre' => 'alerta', 'clase' => 'alerta-icono']) ?>
    <div class="alerta-cuerpo"><p>Esta es la única vez que los verás. Cada código sirve una sola vez si pierdes el teléfono. Guárdalos en un lugar seguro, fuera del computador.</p></div>
</div>
<?= $this->parcial('cuenta/lista_codigos', ['codigos' => $codigos]) ?>
<a class="boton boton-primario boton-ancho" href="<?= $this->url($continuar ?? '/') ?>">Ya los guardé, continuar</a>
