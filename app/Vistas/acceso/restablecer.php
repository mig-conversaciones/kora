<?php
/**
 * Contraseña nueva desde el enlace de recuperación (RAC-006). Variables: $token, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$errores ??= [];
?>
<h1 id="titulo-acceso" class="acceso-titulo">Define una contraseña nueva</h1>
<p class="texto-secundario">Al guardarla se cerrarán todas tus sesiones abiertas.</p>
<?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
<?= $this->parcial('acceso/campos_contrasena', ['errores' => $errores, 'accion' => '/cuenta/restablecer', 'token' => $token, 'boton' => 'Guardar contraseña']) ?>
