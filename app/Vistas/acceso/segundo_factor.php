<?php
/**
 * Segundo paso del inicio de sesión (RAC-009, RAC-011). Variables: $error, $volver.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<h1 id="titulo-acceso" class="acceso-titulo">Verificación en dos pasos</h1>
<p class="texto-secundario">Escribe el código de 6 dígitos de tu aplicación de autenticación. Si no tienes el teléfono, usa uno de tus códigos de respaldo.</p>
<?= $this->parcial('parciales/resumen_errores', ['mensaje' => $error ?? null]) ?>
<form class="formulario" method="post" action="<?= $this->url('/2fa') ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= $this->e($this->csrf()) ?>">
    <input type="hidden" name="volver" value="<?= $this->e($volver ?? '') ?>">
    <div class="campo-grupo">
        <label for="campo-codigo">Código</label>
        <input id="campo-codigo" class="campo campo-codigo" name="codigo" inputmode="numeric" autocomplete="one-time-code" required maxlength="11" autofocus>
        <p class="campo-ayuda">6 dígitos, o un código de respaldo con el formato XXXXX-XXXXX.</p>
    </div>
    <button type="submit" class="boton boton-primario boton-ancho">Verificar</button>
</form>
<form class="acceso-enlaces" method="post" action="<?= $this->url('/salir') ?>">
    <input type="hidden" name="_csrf" value="<?= $this->e($this->csrf()) ?>">
    <button type="submit" class="boton boton-texto">Cancelar e ingresar con otra cuenta</button>
</form>
