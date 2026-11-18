<?php
/**
 * Inicio de sesión (FP-01; RAC-001, RAC-005). Variables: $error, $correo, $volver, $aviso.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<h1 id="titulo-acceso" class="acceso-titulo">Ingresar</h1>
<p class="texto-secundario">Usa el correo con el que te invitaron a KORA.</p>
<?php if (!empty($aviso)): ?>
    <div class="alerta alerta-info"><?= $this->parcial('parciales/icono', ['nombre' => 'info', 'clase' => 'alerta-icono']) ?><div class="alerta-cuerpo"><p><?= $this->e($aviso) ?></p></div></div>
<?php endif; ?>
<?= $this->parcial('parciales/resumen_errores', ['mensaje' => $error ?? null]) ?>
<form class="formulario" method="post" action="<?= $this->url('/ingresar') ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= $this->e($this->csrf()) ?>">
    <input type="hidden" name="volver" value="<?= $this->e($volver ?? '') ?>">
    <div class="campo-grupo">
        <label for="campo-correo">Correo</label>
        <input id="campo-correo" class="campo" type="email" name="correo" autocomplete="username" required maxlength="190" value="<?= $this->e($correo ?? '') ?>" autofocus>
    </div>
    <div class="campo-grupo">
        <label for="campo-contrasena">Contraseña</label>
        <input id="campo-contrasena" class="campo" type="password" name="contrasena" autocomplete="current-password" required maxlength="256">
    </div>
    <button type="submit" class="boton boton-primario boton-ancho">Ingresar</button>
</form>
<p class="acceso-enlaces"><a href="<?= $this->url('/cuenta/recuperar') ?>">¿Olvidaste tu contraseña?</a></p>
