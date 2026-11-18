<?php
/**
 * Perfil y cierre de sesión (RAC-020). La salida es un POST con CSRF, nunca un enlace GET. Variables: $usuario.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<?php if ($usuario instanceof App\Politicas\UsuarioSesion): ?>
    <a class="chip" href="<?= $this->url('/cuenta') ?>" title="Mi cuenta">
        <?= $this->parcial('parciales/icono', ['nombre' => 'persona']) ?>
        <span><?= $this->e($usuario->nombre) ?></span>
    </a>
    <form method="post" action="<?= $this->url('/salir') ?>" class="formulario-salir">
        <input type="hidden" name="_csrf" value="<?= $this->e($this->csrf()) ?>">
        <button type="submit" class="boton boton-icono" aria-label="Cerrar sesión" title="Cerrar sesión"><?= $this->parcial('parciales/icono', ['nombre' => 'salir']) ?></button>
    </form>
<?php else: ?>
    <a class="boton boton-primario boton-pequeno" href="<?= $this->url('/ingresar') ?>"><?= $this->parcial('parciales/icono', ['nombre' => 'candado']) ?> Ingresar</a>
<?php endif; ?>
