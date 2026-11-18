<?php
/**
 * Enlace de invitación o recuperación vencido o ya usado. Variables: $tipo, $mensaje.
 *
 * @var App\Nucleo\Vista $this
 */
$esInvitacion = ($tipo ?? '') === 'invitacion';
?>
<h1 id="titulo-acceso" class="acceso-titulo">Enlace no disponible</h1>
<div class="alerta alerta-advertencia">
    <?= $this->parcial('parciales/icono', ['nombre' => 'alerta', 'clase' => 'alerta-icono']) ?>
    <div class="alerta-cuerpo"><p><?= $this->e($mensaje ?? ($esInvitacion
        ? 'El enlace de activación venció o ya se usó. Pide una nueva invitación a la coordinación del centro.'
        : 'El enlace de recuperación venció o ya se usó. Solicita uno nuevo.')) ?></p></div>
</div>
<p class="acceso-enlaces">
    <?php if ($esInvitacion): ?>
        <a href="<?= $this->url('/ingresar') ?>">Ir a ingresar</a>
    <?php else: ?>
        <a href="<?= $this->url('/cuenta/recuperar') ?>">Solicitar un enlace nuevo</a>
    <?php endif; ?>
</p>
