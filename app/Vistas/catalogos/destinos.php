<?php
/**
 * Destinos y clientes internos (RF-023). Edición en línea: ?editar={id}. Variables: $destinos, $unidades, $editando,
 * $puedeEditar, $valores, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$opcionesUnidades = [];
foreach ($unidades as $u) {
    if ($u['codigo'] !== 'SIN') {
        $opcionesUnidades[(string) $u['id']] = $u['nombre'];
    }
}
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
$actual = null;
foreach ($destinos as $d) {
    if ((int) $d['id'] === (int) $editando) {
        $actual = $d;
    }
}
$v = $valores !== [] ? $valores : ($actual ?? []);
?>
<div id="panel-modulo">
    <div class="rejilla-dos">
        <section class="tarjeta seccion" aria-labelledby="titulo-destinos">
            <h2 id="titulo-destinos" class="titulo-tarjeta">Destinos</h2>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-destinos">
                <table class="tabla">
                    <thead><tr><th scope="col">Nombre</th><th scope="col">Unidad receptora</th><th scope="col">Estado</th><?php if ($puedeEditar): ?><th scope="col"><span class="sr-only">Acciones</span></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($destinos as $d): ?>
                            <tr>
                                <td><?= $this->e($d['nombre']) ?></td>
                                <td><?= $this->e($d['unidad_nombre'] ?? '—') ?></td>
                                <td><?= (int) $d['activo'] === 1 ? '<span class="insignia insignia-exito">Activo</span>' : '<span class="insignia insignia-neutra">Inactivo</span>' ?></td>
                                <?php if ($puedeEditar): ?>
                                    <td><a class="boton boton-pequeno boton-texto" href="<?= $this->e($this->url('/catalogos/destinos') . '?editar=' . (int) $d['id']) ?>" hx-get="<?= $this->e($this->url('/catalogos/destinos') . '?editar=' . (int) $d['id']) ?>" hx-target="#panel-modulo" hx-swap="outerHTML">Editar<span class="sr-only"> <?= $this->e($d['nombre']) ?></span></a></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php if ($puedeEditar): ?>
            <section class="tarjeta seccion" aria-labelledby="titulo-form-destino">
                <h2 id="titulo-form-destino" class="titulo-tarjeta"><?= $actual === null ? 'Nuevo destino' : 'Editar destino' ?></h2>
                <form class="formulario" hx-post="<?= $this->e($this->url('/catalogos/destinos' . ($actual === null ? '' : '/' . (int) $actual['id']))) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                    <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
                    <?= $campo(['nombre' => 'nombre', 'etiqueta' => 'Nombre', 'valor' => $v['nombre'] ?? '', 'atributos' => 'maxlength="120" required']) ?>
                    <?= $campo(['nombre' => 'unidad_id', 'etiqueta' => 'Unidad receptora', 'tipo' => 'select', 'opciones' => $opcionesUnidades, 'vacio' => 'Sin unidad receptora', 'valor' => $v['unidad_id'] ?? '', 'ayuda' => 'La unidad receptora puede consultar las entregas que recibe.']) ?>
                    <?php if ($actual !== null): ?>
                        <?= $campo(['nombre' => 'activo', 'etiqueta' => 'Destino activo', 'tipo' => 'checkbox', 'valor' => $valores !== [] ? !empty($valores['activo']) : (int) $actual['activo'] === 1]) ?>
                    <?php endif; ?>
                    <div class="grupo-botones">
                        <button type="submit" class="boton boton-primario"><?= $actual === null ? 'Crear destino' : 'Guardar cambios' ?></button>
                        <?php if ($actual !== null): ?><a class="boton boton-texto" href="<?= $this->e($this->url('/catalogos/destinos')) ?>">Cancelar</a><?php endif; ?>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>
</div>
