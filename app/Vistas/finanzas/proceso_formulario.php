<?php
/**
 * Alta o edición de un proceso de autoconsumo (RF-036). Variables: $proceso (null al crear), $unidades, $destinos,
 * $productos, $valores, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$v = $valores;
$opcionesUnidades = [];
foreach ($unidades as $u) {
    $opcionesUnidades[(string) $u['id']] = $u['nombre'];
}
$opcionesDestinos = [];
foreach ($destinos as $d) {
    $opcionesDestinos[(string) $d['id']] = $d['nombre'];
}
$opcionesProductos = [];
foreach ($productos as $p) {
    $opcionesProductos[(string) $p['id']] = $p['nombre'] . ' (' . $p['medida'] . ')';
}
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
$accion = $proceso === null ? '/finanzas/procesos' : '/finanzas/procesos/' . (int) $proceso['id'];
$conEntregas = $proceso !== null && (int) $proceso['entregas'] > 0;
$numero = static fn (mixed $x): string => $x === null || $x === '' ? '' : (str_contains((string) $x, '.') ? rtrim(rtrim((string) $x, '0'), '.') : (string) $x);
?>
<div id="panel-modulo">
    <section class="tarjeta seccion">
        <h2 class="titulo-tarjeta"><?= $proceso === null ? 'Nuevo proceso de autoconsumo' : 'Proceso ' . $this->e($proceso['codigo']) ?></h2>
        <?php if ($conEntregas): ?>
            <p class="texto-advertencia">El proceso tiene <?= (int) $proceso['entregas'] ?> entregas: la unidad, el producto, la medida y la vigencia ya no pueden cambiar.</p>
        <?php endif; ?>
        <?php if ($productos === []): ?>
            <p class="texto-advertencia">El catálogo de productos está vacío: crea el producto antes de registrar el proceso.</p>
        <?php endif; ?>
        <form class="formulario" hx-post="<?= $this->e($this->url($accion)) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
            <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
            <?php if ($proceso !== null): ?><input type="hidden" name="version" value="<?= (int) $proceso['version'] ?>"><?php endif; ?>
            <div class="rejilla-campos">
                <?= $campo(['nombre' => 'codigo', 'etiqueta' => 'Código', 'valor' => $v['codigo'] ?? '', 'ayuda' => 'Formato AUTO_001; se sugiere el siguiente libre.', 'atributos' => 'maxlength="11" required']) ?>
                <?= $campo(['nombre' => 'vigencia', 'etiqueta' => 'Vigencia', 'tipo' => 'number', 'valor' => $v['vigencia'] ?? '', 'atributos' => 'min="2020" max="2100" required']) ?>
                <?= $campo(['nombre' => 'unidad_id', 'etiqueta' => 'Unidad de origen', 'tipo' => 'select', 'opciones' => $opcionesUnidades, 'vacio' => 'Elige…', 'valor' => $v['unidad_id'] ?? '']) ?>
                <?= $campo(['nombre' => 'producto_id', 'etiqueta' => 'Producto', 'tipo' => 'select', 'opciones' => $opcionesProductos, 'vacio' => 'Elige…', 'valor' => $v['producto_id'] ?? '', 'ayuda' => 'La medida del cupo es la del producto.']) ?>
                <?= $campo(['nombre' => 'destino_id', 'etiqueta' => 'Destino (catálogo)', 'tipo' => 'select', 'opciones' => $opcionesDestinos, 'vacio' => 'Otro destino (escribirlo)', 'valor' => $v['destino_id'] ?? '']) ?>
                <?= $campo(['nombre' => 'destino_texto', 'etiqueta' => 'Otro destino', 'valor' => $v['destino_texto'] ?? '', 'ayuda' => 'Solo si el destino no está en el catálogo.', 'atributos' => 'maxlength="150"']) ?>
                <?= $campo(['nombre' => 'cupo_cantidad', 'etiqueta' => 'Cupo físico', 'valor' => $numero($v['cupo_cantidad'] ?? ''), 'atributos' => 'inputmode="decimal" required']) ?>
                <?= $campo(['nombre' => 'cupo_valor', 'etiqueta' => 'Valor del cupo (COP)', 'valor' => $numero($v['cupo_valor'] ?? ''), 'atributos' => 'inputmode="decimal" required']) ?>
            </div>
            <div class="grupo-botones">
                <button type="submit" class="boton boton-primario"><?= $proceso === null ? 'Crear proceso' : 'Guardar cambios' ?></button>
                <a class="boton boton-texto" href="<?= $this->e($this->url('/finanzas/procesos')) ?>">Cancelar</a>
            </div>
        </form>
        <?php if ($proceso !== null && !$conEntregas): ?>
            <?= $this->parcial('finanzas/archivar', ['ruta' => '/finanzas/procesos/' . (int) $proceso['id'] . '/archivar', 'version' => (int) $proceso['version'], 'que' => 'el proceso', 'errores' => $errores]) ?>
        <?php endif; ?>
    </section>
</div>
