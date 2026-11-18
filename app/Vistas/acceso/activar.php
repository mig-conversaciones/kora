<?php
/**
 * Activación de la invitación (RAC-003, RAC-007). Variables: $token, $nombre, $correo, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$errores ??= [];
?>
<h1 id="titulo-acceso" class="acceso-titulo">Activa tu cuenta</h1>
<p class="texto-secundario">Hola, <?= $this->e($nombre) ?>. Define la contraseña de <strong><?= $this->e($correo) ?></strong>.</p>
<?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
<?= $this->parcial('acceso/campos_contrasena', ['errores' => $errores, 'accion' => '/cuenta/activar', 'token' => $token, 'boton' => 'Activar cuenta', 'correo' => $correo]) ?>
