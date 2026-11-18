<?php
/**
 * Resumen de errores de un formulario (RUI-012): enlaces a cada campo; retroalimentacion-ui.js le lleva el foco.
 * Variables: $errores (campo => mensaje), $mensaje (error general opcional).
 *
 * @var App\Nucleo\Vista $this
 */
$errores ??= [];
$mensaje ??= null;
if ($errores === [] && ($mensaje === null || $mensaje === '')) {
    return;
}
?>
<div class="alerta alerta-error" data-resumen-errores tabindex="-1">
    <?= $this->parcial('parciales/icono', ['nombre' => 'error', 'clase' => 'alerta-icono']) ?>
    <div class="alerta-cuerpo">
        <?php if ($mensaje !== null && $mensaje !== ''): ?>
            <p class="alerta-titulo"><?= $this->e($mensaje) ?></p>
        <?php endif; ?>
        <?php if ($errores !== []): ?>
            <ul class="lista-errores">
                <?php foreach ($errores as $campo => $texto): ?>
                    <li><a href="#campo-<?= $this->e($campo) ?>"><?= $this->e($texto) ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
