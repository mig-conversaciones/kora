<?php
/**
 * Acceso al centro de notificaciones (RF-005). La tabla notificaciones existe desde la migración 006; la bandeja
 * se habilita con las sesiones (I2). Variables: $usuario.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<button type="button" class="boton boton-icono" disabled aria-label="Notificaciones (disponibles con el inicio de sesión)">
    <?= $this->parcial('parciales/icono', ['nombre' => 'campana']) ?>
</button>
