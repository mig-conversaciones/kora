<?php
/**
 * Estado vacío explicativo (RUI-014): qué pasó y qué hacer, nunca una tabla vacía.
 * Variables: $titulo, $descripcion, $accion (['texto' => ..., 'url' => ...] o null).
 *
 * @var App\Nucleo\Vista $this
 */
?>
<div class="estado-vacio">
    <?= $this->parcial('parciales/icono', ['nombre' => 'caja', 'clase' => 'estado-vacio-icono']) ?>
    <h3><?= $this->e($titulo) ?></h3>
    <p><?= $this->e($descripcion) ?></p>
    <?php if (!empty($accion)): ?>
        <a class="boton boton-primario" href="<?= $this->url($accion['url']) ?>"><?= $this->e($accion['texto']) ?></a>
    <?php endif; ?>
</div>
