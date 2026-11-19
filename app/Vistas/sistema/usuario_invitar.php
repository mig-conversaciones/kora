<?php
/**
 * Invitación de una cuenta (RF-009, RAC-007). Variables: $roles, $unidades, $valores, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$opcionesRoles = [];
foreach ($roles as $r) {
    $opcionesRoles[$r['codigo']] = $r['nombre'] . ((int) $r['requiere_2fa'] === 1 ? ' (exige 2FA)' : '');
}
$opcionesUnidades = [];
foreach ($unidades as $u) {
    $opcionesUnidades[(string) $u['id']] = $u['nombre'];
}
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
?>
<div id="panel-modulo">
    <section class="tarjeta seccion">
        <h2 class="titulo-tarjeta">Invitar usuario</h2>
        <p class="texto-secundario">La persona recibe un enlace de activación de un solo uso que vence en 72 horas. Nunca se envían contraseñas.</p>
        <form class="formulario formulario-estrecho" hx-post="<?= $this->e($this->url('/sistema/usuarios')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
            <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
            <?= $campo(['nombre' => 'nombre', 'etiqueta' => 'Nombre completo', 'valor' => $valores['nombre'] ?? '', 'atributos' => 'maxlength="150" autocomplete="off" required']) ?>
            <?= $campo(['nombre' => 'correo', 'etiqueta' => 'Correo institucional', 'tipo' => 'email', 'valor' => $valores['correo'] ?? '', 'atributos' => 'maxlength="190" autocomplete="off" required']) ?>
            <?= $campo(['nombre' => 'rol', 'etiqueta' => 'Rol', 'tipo' => 'select', 'opciones' => $opcionesRoles, 'vacio' => 'Elige…', 'valor' => $valores['rol'] ?? '']) ?>
            <?= $campo(['nombre' => 'unidades[]', 'etiqueta' => 'Unidades (obligatorio para el líder de unidad)', 'tipo' => 'casillas', 'opciones' => $opcionesUnidades, 'valor' => $valores['unidades'] ?? []]) ?>
            <div class="grupo-botones">
                <button type="submit" class="boton boton-primario">Enviar invitación</button>
                <a class="boton boton-texto" href="<?= $this->e($this->url('/sistema/usuarios')) ?>">Cancelar</a>
            </div>
        </form>
    </section>
</div>
