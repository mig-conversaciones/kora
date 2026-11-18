<?php
/**
 * Formulario de contraseña nueva con la política NIST explicada (RAC-003): sin reglas de composición, longitud
 * mínima y frases bienvenidas. Variables: $accion, $token, $boton, $errores, $correo (opcional, para gestores).
 *
 * @var App\Nucleo\Vista $this
 */
$errores ??= [];
$atributosError = static fn (string $campo): string => isset($errores[$campo]) ? ' aria-invalid="true" aria-describedby="error-' . $campo . ' ayuda-' . $campo . '"' : ' aria-describedby="ayuda-' . $campo . '"';
?>
<form class="formulario" method="post" action="<?= $this->url($accion) ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= $this->e($this->csrf()) ?>">
    <input type="hidden" name="token" value="<?= $this->e($token) ?>">
    <?php if (!empty($correo)): ?>
        <input type="hidden" name="usuario" autocomplete="username" value="<?= $this->e($correo) ?>">
    <?php endif; ?>
    <div class="campo-grupo">
        <label for="campo-contrasena">Contraseña nueva</label>
        <input id="campo-contrasena" class="campo" type="password" name="contrasena" autocomplete="new-password" minlength="8" maxlength="128" required<?= $atributosError('contrasena') ?>>
        <p id="ayuda-contrasena" class="campo-ayuda">Al menos 15 caracteres (8 si usas verificación en dos pasos). Una frase de varias palabras funciona muy bien; no hace falta mezclar símbolos.</p>
        <?php if (isset($errores['contrasena'])): ?><p id="error-contrasena" class="campo-error"><?= $this->e($errores['contrasena']) ?></p><?php endif; ?>
    </div>
    <div class="campo-grupo">
        <label for="campo-confirmacion">Repite la contraseña</label>
        <input id="campo-confirmacion" class="campo" type="password" name="confirmacion" autocomplete="new-password" maxlength="128" required<?= $atributosError('confirmacion') ?>>
        <p id="ayuda-confirmacion" class="campo-ayuda sr-only">Debe coincidir con la anterior.</p>
        <?php if (isset($errores['confirmacion'])): ?><p id="error-confirmacion" class="campo-error"><?= $this->e($errores['confirmacion']) ?></p><?php endif; ?>
    </div>
    <button type="submit" class="boton boton-primario boton-ancho"><?= $this->e($boton) ?></button>
</form>
