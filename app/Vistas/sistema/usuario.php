<?php
/**
 * Gestión de una cuenta (RF-009, RF-010): datos, rol y unidades (con reautenticación), estado e invitación.
 * Variables: $cuenta, $roles, $unidades, $valores, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$estados = App\Servicios\ServicioUsuarios::ESTADOS;
$global = $cuenta['centro_id'] === null;
$opcionesRoles = [];
foreach ($roles as $r) {
    if (($r['alcance'] === 'global') === $global) {
        $opcionesRoles[$r['codigo']] = $r['nombre'];
    }
}
$opcionesUnidades = [];
foreach ($unidades as $u) {
    $opcionesUnidades[(string) $u['id']] = $u['nombre'];
}
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
$base = '/sistema/usuarios/' . (int) $cuenta['id'];
?>
<div id="panel-modulo">
    <p><a class="boton boton-texto boton-pequeno" href="<?= $this->e($this->url('/sistema/usuarios')) ?>">← Volver a usuarios</a></p>
    <div class="rejilla-dos">
        <section class="tarjeta seccion" aria-labelledby="titulo-acceso">
            <h2 id="titulo-acceso" class="titulo-tarjeta">Datos y acceso</h2>
            <dl class="lista-datos">
                <dt>Correo</dt><dd><?= $this->e($cuenta['correo']) ?></dd>
                <dt>Estado</dt><dd><?= $this->e($estados[$cuenta['estado']] ?? $cuenta['estado']) ?></dd>
                <dt>Verificación en dos pasos</dt><dd><?= $cuenta['totp_activado_en'] === null ? 'No configurada' : 'Activa' ?></dd>
                <dt>Último acceso (UTC)</dt><dd><?= $cuenta['ultimo_acceso_en'] === null ? 'Nunca' : $this->e(substr((string) $cuenta['ultimo_acceso_en'], 0, 16)) ?></dd>
            </dl>
            <form class="formulario" hx-post="<?= $this->e($this->url($base . '/acceso')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                <?= $this->parcial('parciales/resumen_errores', ['errores' => array_diff_key($errores, ['motivo' => true])]) ?>
                <?= $campo(['nombre' => 'nombre', 'etiqueta' => 'Nombre completo', 'valor' => $valores['nombre'] ?? $cuenta['nombre'], 'atributos' => 'maxlength="150" required']) ?>
                <?= $campo(['nombre' => 'rol', 'etiqueta' => 'Rol', 'tipo' => 'select', 'opciones' => $opcionesRoles, 'valor' => $valores['rol'] ?? '', 'ayuda' => $global ? 'Una cuenta de superadministrador no recibe roles de negocio.' : null]) ?>
                <?php if (!$global): ?>
                    <?= $campo(['nombre' => 'unidades[]', 'etiqueta' => 'Unidades asignadas', 'tipo' => 'casillas', 'opciones' => $opcionesUnidades, 'valor' => $valores['unidades'] ?? []]) ?>
                <?php endif; ?>
                <p class="campo-ayuda">Guardar pide confirmar tu identidad si no lo hiciste en los últimos 5 minutos. El cambio queda auditado con el antes y el después.</p>
                <button type="submit" class="boton boton-primario">Guardar acceso</button>
            </form>
        </section>
        <section class="tarjeta seccion" aria-labelledby="titulo-estado">
            <h2 id="titulo-estado" class="titulo-tarjeta">Estado de la cuenta</h2>
            <?php if ($cuenta['estado'] === 'invitado'): ?>
                <p class="texto-secundario">La persona aún no activó su cuenta.</p>
                <button type="button" class="boton" hx-post="<?= $this->e($this->url($base . '/reenviar')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML">Reenviar invitación</button>
            <?php endif; ?>
            <?php $accion = $cuenta['estado'] === 'desactivado' ? 'reactivar' : 'desactivar'; ?>
            <form class="formulario" hx-post="<?= $this->e($this->url($base . '/' . $accion)) ?>" hx-target="#panel-modulo" hx-swap="outerHTML"
                  <?= $accion === 'desactivar' ? 'data-confirmar="La persona no podrá ingresar y sus sesiones se cerrarán de inmediato." data-confirmar-titulo="Desactivar cuenta" data-confirmar-accion="Desactivar" data-peligro' : '' ?> novalidate>
                <?= $campo(['nombre' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'textarea', 'valor' => '', 'atributos' => 'maxlength="500" required']) ?>
                <button type="submit" class="boton <?= $accion === 'desactivar' ? 'boton-peligro' : 'boton-primario' ?>"><?= $accion === 'desactivar' ? 'Desactivar cuenta' : 'Reactivar cuenta' ?></button>
            </form>
        </section>
        <?php $abiertas = (int) ($sesionesActivas ?? 0); ?>
        <?php if ($cuenta['estado'] !== 'desactivado'): ?>
            <section class="tarjeta seccion" aria-labelledby="titulo-sesiones">
                <h2 id="titulo-sesiones" class="titulo-tarjeta">Sesiones abiertas</h2>
                <p><?= $abiertas === 1 ? 'Hay 1 sesión abierta.' : "Hay {$abiertas} sesiones abiertas." ?> Cerrarlas obliga a ingresar de nuevo sin desactivar la cuenta; sirve, por ejemplo, ante un equipo perdido.</p>
                <?php if ($abiertas > 0): ?>
                    <form class="formulario" hx-post="<?= $this->e($this->url($base . '/cerrar-sesiones')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML"
                          data-confirmar="Todas las sesiones de esta persona se cerrarán de inmediato." data-confirmar-titulo="Cerrar sus sesiones" data-confirmar-accion="Cerrar sesiones" data-peligro novalidate>
                        <?= $campo(['nombre' => 'motivo', 'etiqueta' => 'Motivo del cierre', 'tipo' => 'textarea', 'valor' => '', 'atributos' => 'maxlength="500" required']) ?>
                        <button type="submit" class="boton boton-peligro">Cerrar sus sesiones</button>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</div>
