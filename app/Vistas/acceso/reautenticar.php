<?php
/**
 * Reautenticación para operaciones sensibles (RAC-026). Variables: $con2fa, $volver, $error.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<h1 id="titulo-acceso" class="acceso-titulo">Confirma tu identidad</h1>
<p class="texto-secundario">Esta operación es sensible. <?= !empty($con2fa) ? 'Escribe el código actual de tu aplicación de autenticación.' : 'Escribe tu contraseña.' ?> La confirmación vale 5 minutos.</p>
<?= $this->parcial('parciales/resumen_errores', ['mensaje' => $error ?? null]) ?>
<form class="formulario" method="post" action="<?= $this->url('/reautenticar') ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= $this->e($this->csrf()) ?>">
    <input type="hidden" name="volver" value="<?= $this->e($volver ?? '') ?>">
    <div class="campo-grupo">
        <?php if (!empty($con2fa)): ?>
            <label for="campo-secreto">Código de verificación</label>
            <input id="campo-secreto" class="campo campo-codigo" name="secreto" inputmode="numeric" autocomplete="one-time-code" maxlength="11" required autofocus>
        <?php else: ?>
            <label for="campo-secreto">Contraseña</label>
            <input id="campo-secreto" class="campo" type="password" name="secreto" autocomplete="current-password" maxlength="256" required autofocus>
        <?php endif; ?>
    </div>
    <button type="submit" class="boton boton-primario boton-ancho">Confirmar</button>
</form>
<p class="acceso-enlaces"><a href="<?= $this->url('/') ?>">Cancelar</a></p>
