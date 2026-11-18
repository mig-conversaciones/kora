<?php
/**
 * Códigos de respaldo regenerados (RAC-011): se muestran una sola vez. Variables: $codigos.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<div class="alerta alerta-advertencia">
    <?= $this->parcial('parciales/icono', ['nombre' => 'alerta', 'clase' => 'alerta-icono']) ?>
    <div class="alerta-cuerpo"><p class="alerta-titulo">Códigos nuevos: guárdalos ahora</p><p>No volverán a mostrarse. Los anteriores ya no funcionan.</p></div>
</div>
<?= $this->parcial('cuenta/lista_codigos', ['codigos' => $codigos]) ?>
