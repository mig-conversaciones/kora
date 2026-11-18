<?php
/**
 * Selector de unidad productiva según el alcance del usuario (RDI-003). Se activa en la I2 con AlcanceDatos; en la
 * I0 informa la unidad piloto sin permitir cambiarla. Variables: $usuario.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<span class="chip" title="El cambio de unidad se habilita con el alcance de datos (iteración I2)">
    <?= $this->parcial('parciales/icono', ['nombre' => 'unidad']) ?>
    <span>Ganadería (piloto)</span>
</span>
