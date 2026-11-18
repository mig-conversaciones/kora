<?php
/**
 * Cambio de contraseña (RAC-008). Variables: $valores, $errores, $exito.
 *
 * @var App\Nucleo\Vista $this
 */
$errores ??= [];
$atributos = static fn (string $campo): string => isset($errores[$campo]) ? ' aria-invalid="true" aria-describedby="error-' . $campo . '"' : '';
?>
<form id="formulario-contrasena" class="formulario" hx-post="<?= $this->e($this->url('/cuenta/contrasena')) ?>" hx-target="this" hx-swap="outerHTML" novalidate>
    <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
    <?php if (!empty($exito)): ?>
        <div class="alerta alerta-exito"><?= $this->parcial('parciales/icono', ['nombre' => 'ok', 'clase' => 'alerta-icono']) ?><div class="alerta-cuerpo"><p>Contraseña actualizada.</p></div></div>
    <?php endif; ?>
    <div class="campo-grupo">
        <label for="campo-contrasena_actual">Contraseña actual</label>
        <input id="campo-contrasena_actual" class="campo" type="password" name="contrasena_actual" autocomplete="current-password" maxlength="256" required<?= $atributos('contrasena_actual') ?>>
        <?php if (isset($errores['contrasena_actual'])): ?><p id="error-contrasena_actual" class="campo-error"><?= $this->e($errores['contrasena_actual']) ?></p><?php endif; ?>
    </div>
    <div class="campo-grupo">
        <label for="campo-contrasena">Contraseña nueva</label>
        <input id="campo-contrasena" class="campo" type="password" name="contrasena" autocomplete="new-password" maxlength="128" required<?= $atributos('contrasena') ?>>
        <p class="campo-ayuda">Al menos 15 caracteres (8 con verificación en dos pasos). Una frase de varias palabras funciona muy bien.</p>
        <?php if (isset($errores['contrasena'])): ?><p id="error-contrasena" class="campo-error"><?= $this->e($errores['contrasena']) ?></p><?php endif; ?>
    </div>
    <div class="campo-grupo">
        <label for="campo-confirmacion">Repite la contraseña nueva</label>
        <input id="campo-confirmacion" class="campo" type="password" name="confirmacion" autocomplete="new-password" maxlength="128" required<?= $atributos('confirmacion') ?>>
        <?php if (isset($errores['confirmacion'])): ?><p id="error-confirmacion" class="campo-error"><?= $this->e($errores['confirmacion']) ?></p><?php endif; ?>
    </div>
    <label class="casilla"><input type="checkbox" name="cerrar_otras" value="1" checked> Cerrar mis demás sesiones</label>
    <button type="submit" class="boton boton-primario">Cambiar contraseña</button>
</form>
