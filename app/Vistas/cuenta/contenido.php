<?php
/**
 * «Mi cuenta» (RAC-008, RAC-011, RAC-014, RAC-020). Variables: $cuenta (UsuarioSesion), $codigosRestantes,
 * $sesiones.
 *
 * @var App\Nucleo\Vista $this
 * @var App\Politicas\UsuarioSesion $cuenta
 */
?>
<header class="encabezado-pagina">
    <div>
        <p class="sobretitulo">Mi cuenta</p>
        <h1 class="titulo-pagina"><?= $this->e($cuenta->nombre) ?></h1>
        <p class="texto-secundario"><?= $this->e($cuenta->correo) ?> · <?= $this->e($cuenta->nombreRol()) ?></p>
    </div>
</header>

<div class="rejilla-dos">
    <section class="tarjeta seccion" aria-labelledby="titulo-contrasena">
        <h2 id="titulo-contrasena" class="titulo-tarjeta">Contraseña</h2>
        <p class="texto-secundario">Al cambiarla se renueva esta sesión y te avisamos por correo.</p>
        <?= $this->parcial('cuenta/formulario_contrasena', ['valores' => [], 'errores' => []]) ?>
    </section>

    <section class="tarjeta seccion" aria-labelledby="titulo-2fa">
        <h2 id="titulo-2fa" class="titulo-tarjeta">Verificación en dos pasos</h2>
        <?php if ($cuenta->tiene2fa): ?>
            <p><span class="insignia insignia-exito">Activa</span></p>
            <p class="texto-secundario">Te quedan <strong><?= (int) $codigosRestantes ?></strong> códigos de respaldo de 10.</p>
            <div class="grupo-botones">
                <button type="button" class="boton" hx-post="<?= $this->e($this->url('/cuenta/2fa/codigos')) ?>" hx-target="#codigos-nuevos"
                        data-confirmar="Los códigos de respaldo actuales dejarán de funcionar." data-confirmar-titulo="Generar códigos nuevos" data-confirmar-accion="Generar">
                    Generar códigos nuevos
                </button>
                <?php if (!$cuenta->requiere2fa): ?>
                    <button type="button" class="boton boton-peligro" hx-post="<?= $this->e($this->url('/cuenta/2fa/desactivar')) ?>"
                            data-confirmar="Tu cuenta quedará protegida solo con la contraseña." data-confirmar-titulo="Desactivar la verificación en dos pasos" data-confirmar-accion="Desactivar" data-peligro>
                        Desactivar
                    </button>
                <?php else: ?>
                    <p class="campo-ayuda">Tu rol la exige; no se puede desactivar.</p>
                <?php endif; ?>
            </div>
            <div id="codigos-nuevos"></div>
        <?php else: ?>
            <p><span class="insignia insignia-advertencia">Inactiva</span></p>
            <p class="texto-secundario">Protege tu cuenta con un código de tu teléfono además de la contraseña. Con ella, la contraseña puede tener desde 8 caracteres.</p>
            <a class="boton boton-primario" href="<?= $this->url('/2fa/enrolar') ?>">Activar</a>
        <?php endif; ?>
    </section>
</div>

<section class="tarjeta seccion" aria-labelledby="titulo-sesiones">
    <h2 id="titulo-sesiones" class="titulo-tarjeta">Sesiones activas</h2>
    <p class="texto-secundario">Si no reconoces una sesión, ciérrala y cambia tu contraseña.</p>
    <div id="lista-sesiones">
        <?= $this->parcial('cuenta/sesiones', ['sesiones' => $sesiones]) ?>
    </div>
</section>
