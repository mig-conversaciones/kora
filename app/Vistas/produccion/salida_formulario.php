<?php
/**
 * Alta o edición de una salida desde un lote (RF-043, RF-044, RF-046). Variables: $salida (null al crear), $lotes,
 * $procesos, $valores, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$v = $valores;
$opcionesLotes = [];
foreach ($lotes as $l) {
    $opcionesLotes[(string) $l['id']] = $l['numero_interno'] . ' · ' . $l['producto_nombre'] . ' · saldo ' . $this->cantidad($l['saldo']) . ' ' . $l['medida'];
}
$opcionesProcesos = [];
foreach ($procesos as $p) {
    $opcionesProcesos[(string) $p['id']] = $p['codigo'] . ' (' . $p['vigencia'] . ') · ' . $p['producto_texto'] . ' en ' . $p['medida'];
}
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
$accion = $salida === null ? '/produccion/salidas' : '/produccion/salidas/' . (int) $salida['id'];
$numero = static fn (mixed $x): string => $x === null || $x === '' ? '' : (str_contains((string) $x, '.') ? rtrim(rtrim((string) $x, '0'), '.') : (string) $x);
$conTransformaciones = $salida !== null && (int) $salida['transformaciones']['cantidad'] > 0;
?>
<div id="panel-modulo">
    <section class="tarjeta seccion">
        <h2 class="titulo-tarjeta"><?= $salida === null ? 'Nueva salida' : 'Salida del ' . $this->e($this->fecha($salida['fecha'])) ?></h2>
        <?php if ($conTransformaciones): ?>
            <p class="texto-advertencia">Esta entrega alimenta <?= (int) $salida['transformaciones']['cantidad'] ?> transformaciones lácteas (<?= $this->e($this->cantidad($salida['transformaciones']['litros'])) ?> L asignados): conserva su canal, su producto y su medida.</p>
        <?php endif; ?>
        <?php if ($lotes === []): ?>
            <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'No hay lotes con saldo', 'descripcion' => 'Una salida siempre sale de un lote. Registra primero la recepción.', 'accion' => ['texto' => 'Registrar recepción', 'url' => '/produccion/recepciones/nueva']]) ?>
        <?php else: ?>
            <form class="formulario" hx-post="<?= $this->e($this->url($accion)) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
                <?php if ($salida !== null): ?><input type="hidden" name="version" value="<?= (int) $salida['version'] ?>"><?php endif; ?>
                <div class="rejilla-campos">
                    <?= $campo(['nombre' => 'recepcion_id', 'etiqueta' => 'Lote', 'tipo' => 'select', 'opciones' => $opcionesLotes, 'vacio' => 'Elige…', 'valor' => (string) ($v['recepcion_id'] ?? ''), 'ayuda' => 'La salida hereda la unidad, el producto y la medida del lote.']) ?>
                    <?= $campo(['nombre' => 'fecha', 'etiqueta' => 'Fecha', 'tipo' => 'date', 'valor' => (string) ($v['fecha'] ?? ''), 'atributos' => 'required']) ?>
                    <?= $campo(['nombre' => 'cantidad', 'etiqueta' => 'Cantidad', 'valor' => $numero($v['cantidad'] ?? ''), 'atributos' => 'inputmode="decimal" required']) ?>
                    <?= $campo(['nombre' => 'precio_unitario', 'etiqueta' => 'Precio unitario (COP)', 'valor' => $numero($v['precio_unitario'] ?? ''), 'ayuda' => 'El valor es cantidad × precio, redondeado a 2 decimales.', 'atributos' => 'inputmode="decimal" required']) ?>
                    <?= $campo(['nombre' => 'canal', 'etiqueta' => 'Canal', 'tipo' => 'select', 'opciones' => ['VENTA_DIRECTA' => 'Venta directa', 'AUTOCONSUMO' => 'Autoconsumo'], 'valor' => (string) ($v['canal'] ?? '')]) ?>
                    <?= $campo(['nombre' => 'proceso_id', 'etiqueta' => 'Proceso de autoconsumo', 'tipo' => 'select', 'opciones' => $opcionesProcesos, 'vacio' => 'Elige… (solo autoconsumo)', 'valor' => (string) ($v['proceso_id'] ?? ''), 'ayuda' => 'Obligatorio en autoconsumo: misma unidad de origen, medida, vigencia y producto.']) ?>
                    <?= $campo(['nombre' => 'factura', 'etiqueta' => 'Factura o prefactura', 'valor' => (string) ($v['factura'] ?? ''), 'atributos' => 'maxlength="40"']) ?>
                    <?= $campo(['nombre' => 'referencia_transaccion', 'etiqueta' => 'Referencia de la transacción', 'valor' => (string) ($v['referencia_transaccion'] ?? ''), 'atributos' => 'maxlength="80"']) ?>
                    <?= $campo(['nombre' => 'destino', 'etiqueta' => 'Destino o cliente', 'valor' => (string) ($v['destino'] ?? ''), 'ayuda' => 'En autoconsumo se toma el destino del proceso.', 'atributos' => 'maxlength="150"']) ?>
                </div>
                <p class="campo-ayuda">Registrar una venta directa no crea un recaudo: el recaudo lo registra el Grupo de Recaudo y se cruza con la venta por conciliación.</p>
                <div class="grupo-botones">
                    <button type="submit" class="boton boton-primario"><?= $salida === null ? 'Registrar salida' : 'Guardar cambios' ?></button>
                    <a class="boton boton-texto" href="<?= $this->e($this->url('/produccion/salidas')) ?>">Cancelar</a>
                </div>
            </form>
        <?php endif; ?>
        <?php if ($salida !== null && !$conTransformaciones): ?>
            <?= $this->parcial('finanzas/archivar', ['ruta' => '/produccion/salidas/' . (int) $salida['id'] . '/archivar', 'version' => (int) $salida['version'], 'que' => 'la salida', 'errores' => $errores]) ?>
        <?php endif; ?>
    </section>
</div>
