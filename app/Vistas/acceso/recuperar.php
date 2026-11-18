<?php
/**
 * Solicitud de recuperación (RAC-005, RAC-006): la respuesta es la misma exista o no la cuenta.
 * Variables: $enviado.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<h1 id="titulo-acceso" class="acceso-titulo">Recupera tu contraseña</h1>
<?php if (!empty($enviado)): ?>
    <div class="alerta alerta-info"><?= $this->parcial('parciales/icono', ['nombre' => 'info', 'clase' => 'alerta-icono']) ?><div class="alerta-cuerpo"><p><?= $this->e(App\Servicios\ServicioCuentas::MENSAJE_RECUPERACION) ?> El enlace vence en 30 minutos.</p></div></div>
    <p class="acceso-enlaces"><a href="<?= $this->url('/ingresar') ?>">Volver a ingresar</a></p>
<?php else: ?>
    <p class="texto-secundario">Escribe el correo de tu cuenta y te enviaremos un enlace para definir una contraseña nueva.</p>
    <form class="formulario" method="post" action="<?= $this->url('/cuenta/recuperar') ?>" novalidate>
        <input type="hidden" name="_csrf" value="<?= $this->e($this->csrf()) ?>">
        <div class="campo-grupo">
            <label for="campo-correo">Correo</label>
            <input id="campo-correo" class="campo" type="email" name="correo" autocomplete="username" maxlength="190" required autofocus>
        </div>
        <button type="submit" class="boton boton-primario boton-ancho">Enviar enlace</button>
    </form>
    <p class="acceso-enlaces"><a href="<?= $this->url('/ingresar') ?>">Volver a ingresar</a></p>
<?php endif; ?>
