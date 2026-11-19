<?php
/**
 * Conciliación de un recaudo con ventas directas (RF-032). Variables: $recaudo, $candidatas, $vinculadas, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$errores ??= [];
$estados = ['sin_conciliar' => ['Sin conciliar', 'neutra'], 'conciliado' => ['Conciliado', 'exito'], 'con_diferencia' => ['Con diferencia', 'advertencia']];
[$texto, $tono] = $estados[$recaudo['estado_conciliacion']] ?? ['—', 'neutra'];
?>
<div id="panel-modulo">
    <section class="tarjeta seccion" aria-labelledby="titulo-recaudo">
        <h2 id="titulo-recaudo" class="titulo-tarjeta">Recaudo del <?= $this->e($this->fecha($recaudo['fecha_pago'])) ?> por <?= $this->e($this->moneda($recaudo['valor'])) ?> <span class="insignia insignia-<?= $this->e($tono) ?>"><?= $this->e($texto) ?></span></h2>
        <dl class="lista-datos">
            <div><dt>Cupón</dt><dd><?= $this->e($recaudo['cupon_pago'] ?? '—') ?></dd></div>
            <div><dt>Prefactura</dt><dd><?= $this->e($recaudo['prefactura'] ?? '—') ?></dd></div>
            <div><dt>Factura electrónica</dt><dd><?= $this->e($recaudo['factura_electronica'] ?? '—') ?></dd></div>
        </dl>
        <?php if ($vinculadas !== []): ?>
            <h3>Ventas ya conciliadas</h3>
            <ul class="lista-enlaces"><?php foreach ($vinculadas as $v): ?><li><?= $this->e($this->fecha($v['fecha'])) ?> · <?= $this->e($this->moneda($v['valor'])) ?> · factura <?= $this->e($v['factura'] ?? '—') ?> (<?= $this->e($v['criterio']) ?>)</li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($candidatas === []): ?>
            <p class="texto-secundario">No hay ventas directas con la misma prefactura, factura o referencia de transacción.</p>
        <?php else: ?>
            <form class="formulario" hx-post="<?= $this->e($this->url('/recaudos/' . (int) $recaudo['id'] . '/conciliar')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
                <fieldset class="grupo-campos">
                    <legend>Ventas candidatas</legend>
                    <?php foreach ($candidatas as $c): ?>
                        <label class="casilla"><input type="checkbox" name="salidas[]" value="<?= (int) $c['id'] ?>"> <?= $this->e($this->fecha($c['fecha'])) ?> · <?= $this->e($this->moneda($c['valor'])) ?> · factura <?= $this->e($c['factura'] ?? '—') ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <?= $this->parcial('parciales/campo', ['nombre' => 'criterio', 'etiqueta' => 'Criterio', 'tipo' => 'select', 'opciones' => ['prefactura' => 'Prefactura', 'factura' => 'Factura', 'referencia' => 'Referencia de la transacción', 'manual' => 'Decisión manual'], 'errores' => $errores, 'valor' => 'prefactura']) ?>
                <button type="submit" class="boton boton-primario">Conciliar</button>
            </form>
        <?php endif; ?>
        <p class="campo-ayuda">Conciliar no suma recaudos y ventas: los indicadores de ingresos usan solo los recaudos.</p>
    </section>
</div>
