<?php
/**
 * Enrolamiento de la 2FA (RAC-010): QR generado en el servidor y clave manual; se activa al verificar un código.
 * Variables: $qr (data URI SVG), $semilla (agrupada de 4 en 4), $obligatorio, $error.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<h1 id="titulo-acceso" class="acceso-titulo">Configura la verificación en dos pasos</h1>
<?php if (!empty($obligatorio)): ?>
    <p class="texto-secundario">Tu rol exige este segundo factor. Necesitas una aplicación de autenticación en tu teléfono (por ejemplo, Google Authenticator, Microsoft Authenticator o FreeOTP).</p>
<?php else: ?>
    <p class="texto-secundario">Agrega un segundo factor a tu cuenta con una aplicación de autenticación en tu teléfono.</p>
<?php endif; ?>
<?= $this->parcial('parciales/resumen_errores', ['mensaje' => $error ?? null]) ?>
<ol class="pasos">
    <li>
        <p>Escanea este código QR con la aplicación:</p>
        <img class="qr" src="<?= $this->e($qr) ?>" alt="Código QR para configurar la aplicación de autenticación" width="200" height="200">
        <details class="clave-manual">
            <summary>¿No puedes escanearlo? Escribe la clave manualmente</summary>
            <p><code class="clave"><?= $this->e($semilla) ?></code></p>
            <p class="campo-ayuda">Tipo: basada en tiempo · 6 dígitos · 30 segundos.</p>
        </details>
    </li>
    <li>
        <form class="formulario" method="post" action="<?= $this->url('/2fa/enrolar') ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($this->csrf()) ?>">
            <div class="campo-grupo">
                <label for="campo-codigo">Escribe el código que muestra la aplicación</label>
                <input id="campo-codigo" class="campo campo-codigo" name="codigo" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
            </div>
            <button type="submit" class="boton boton-primario boton-ancho">Activar</button>
        </form>
    </li>
</ol>
