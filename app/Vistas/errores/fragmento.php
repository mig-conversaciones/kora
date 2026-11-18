<?php
/**
 * Error controlado (RUI-015). Para HTMX se inyecta en #contenedor-errores; en una página completa lo envuelve
 * errores/pagina. Nunca muestra detalles internos (RS-010). Variables: $titulo, $mensaje, $estado, $codigoSoporte,
 * $errores (validación).
 *
 * @var App\Nucleo\Vista $this
 */
?>
<div class="alerta alerta-error" data-estado-http="<?= (int) $estado ?>">
    <?= $this->parcial('parciales/icono', ['nombre' => 'error', 'clase' => 'alerta-icono']) ?>
    <div class="alerta-cuerpo">
        <p class="alerta-titulo"><?= $this->e($titulo) ?></p>
        <p><?= $this->e($mensaje) ?></p>
        <?php if (!empty($errores)): ?>
            <ul class="lista-errores">
                <?php foreach ($errores as $campo => $texto): ?>
                    <li><?= $this->e($texto) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($codigoSoporte !== ''): ?>
            <p class="alerta-codigo">Código de soporte: <code><?= $this->e($codigoSoporte) ?></code></p>
        <?php endif; ?>
    </div>
</div>
