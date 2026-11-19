<?php
/**
 * Alta o edición de una recepción (RF-039, RF-040). Variables: $lote (null al crear), $unidades, $productos,
 * $responsables, $valores, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$v = $valores;
$opcionesUnidades = [];
foreach ($unidades as $u) {
    $opcionesUnidades[(string) $u['id']] = $u['nombre'];
}
$opcionesProductos = [];
foreach ($productos as $p) {
    $opcionesProductos[(string) $p['id']] = $p['nombre'] . ' (' . $p['medida'] . ')';
}
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
$accion = $lote === null ? '/produccion/recepciones' : '/produccion/recepciones/' . (int) $lote['id'];
$conSalidas = $lote !== null && (int) ($lote['salidas'] ?? 0) > 0;
$numero = static fn (mixed $x): string => $x === null || $x === '' ? '' : (str_contains((string) $x, '.') ? rtrim(rtrim((string) $x, '0'), '.') : (string) $x);
$sugerir = $this->url('/produccion/recepciones/numero-sugerido');
$refrescar = 'hx-get="' . $this->e($sugerir) . '" hx-target="#numero-sugerido" hx-swap="outerHTML" hx-include="[name=unidad_id],[name=fecha_recepcion]" hx-trigger="change"';
?>
<div id="panel-modulo">
    <section class="tarjeta seccion">
        <h2 class="titulo-tarjeta"><?= $lote === null ? 'Nueva recepción' : 'Lote ' . $this->e($lote['numero_interno']) ?></h2>
        <?php if ($conSalidas): ?>
            <p class="texto-advertencia">El lote tiene <?= (int) $lote['salidas'] ?> salidas: el producto, la unidad, la medida y el número interno ya no cambian, y la cantidad no puede quedar por debajo de lo entregado.</p>
        <?php endif; ?>
        <?php if ($productos === []): ?>
            <p class="texto-advertencia">El catálogo de productos está vacío: crea el producto antes de registrar la recepción.</p>
        <?php endif; ?>
        <form class="formulario" hx-post="<?= $this->e($this->url($accion)) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
            <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
            <?php if ($lote !== null): ?><input type="hidden" name="version" value="<?= (int) $lote['version'] ?>"><?php endif; ?>
            <div class="rejilla-campos">
                <?= $campo(['nombre' => 'unidad_id', 'etiqueta' => 'Unidad que recibe', 'tipo' => 'select', 'opciones' => $opcionesUnidades, 'vacio' => 'Elige…', 'valor' => (string) ($v['unidad_id'] ?? ''), 'atributos' => $lote === null ? $refrescar : '']) ?>
                <?= $campo(['nombre' => 'fecha_recepcion', 'etiqueta' => 'Fecha de recepción', 'tipo' => 'date', 'valor' => (string) ($v['fecha_recepcion'] ?? ''), 'atributos' => ($lote === null ? $refrescar . ' ' : '') . 'required']) ?>
                <?= $this->parcial('produccion/numero_sugerido', ['numero' => (string) ($v['numero_interno'] ?? ''), 'errores' => $errores]) ?>
                <?= $campo(['nombre' => 'producto_id', 'etiqueta' => 'Producto', 'tipo' => 'select', 'opciones' => $opcionesProductos, 'vacio' => 'Elige…', 'valor' => (string) ($v['producto_id'] ?? '')]) ?>
                <?= $campo(['nombre' => 'medida', 'etiqueta' => 'Medida', 'tipo' => 'select', 'opciones' => ['L' => 'Litros (L)', 'kg' => 'Kilogramos (kg)', 'ud' => 'Unidades (ud)', 'lb' => 'Libras (lb)', 'cubeta' => 'Cubetas'], 'vacio' => 'La del catálogo', 'valor' => (string) ($v['medida'] ?? ''), 'ayuda' => 'Debe coincidir con la medida del producto en el catálogo.']) ?>
                <?= $campo(['nombre' => 'cantidad', 'etiqueta' => 'Cantidad recibida', 'valor' => $numero($v['cantidad'] ?? ''), 'atributos' => 'inputmode="decimal" required']) ?>
                <?= $campo(['nombre' => 'precio_sugerido', 'etiqueta' => 'Precio sugerido (COP)', 'valor' => $numero($v['precio_sugerido'] ?? ''), 'ayuda' => 'Opcional. Se propone al registrar las salidas.', 'atributos' => 'inputmode="decimal"']) ?>
                <?= $campo(['nombre' => 'responsable_id', 'etiqueta' => 'Responsable que recibe', 'tipo' => 'select', 'opciones' => $responsables, 'vacio' => 'Elige…', 'valor' => (string) ($v['responsable_id'] ?? '')]) ?>
            </div>
            <div class="grupo-botones">
                <button type="submit" class="boton boton-primario"><?= $lote === null ? 'Registrar recepción' : 'Guardar cambios' ?></button>
                <a class="boton boton-texto" href="<?= $this->e($this->url('/produccion/recepciones')) ?>">Cancelar</a>
            </div>
        </form>
        <?php if ($lote !== null && !$conSalidas): ?>
            <?= $this->parcial('finanzas/archivar', ['ruta' => '/produccion/recepciones/' . (int) $lote['id'] . '/archivar', 'version' => (int) $lote['version'], 'que' => 'la recepción', 'errores' => $errores]) ?>
        <?php endif; ?>
    </section>
</div>
