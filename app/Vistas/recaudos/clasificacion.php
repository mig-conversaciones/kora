<?php
/**
 * Bandeja de recaudos por clasificar (RF-031): uno a uno o en lote, con sugerencias que solo se aplican al confirmar.
 * Variables: $filas, $unidades, $productos, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$errores ??= [];
$opcionesUnidades = [];
$nombres = [];
foreach ($unidades as $u) {
    if ($u['codigo'] !== 'SIN') {
        $opcionesUnidades[(string) $u['id']] = $u['nombre'];
    }
    $nombres[(int) $u['id']] = $u['nombre'];
}
$opcionesProductos = [];
$productosNombre = [];
foreach ($productos as $p) {
    $opcionesProductos[(string) $p['id']] = $p['nombre'];
    $productosNombre[(int) $p['id']] = $p['nombre'];
}
?>
<div id="panel-modulo">
    <section class="tarjeta seccion" aria-labelledby="titulo-clasificar">
        <h2 id="titulo-clasificar" class="titulo-tarjeta">Recaudos por clasificar (<?= count($filas) ?>)</h2>
        <?php if ($filas === []): ?>
            <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'No hay recaudos por clasificar', 'descripcion' => 'Todos los recaudos tienen unidad y producto o concepto.', 'accion' => null]) ?>
        <?php else: ?>
            <form class="formulario" hx-post="<?= $this->e($this->url('/recaudos/clasificar')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
                <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-clasificar">
                    <table class="tabla">
                        <thead><tr><th scope="col"><span class="sr-only">Elegir</span></th><th scope="col">Fecha</th><th scope="col" class="numero">Valor</th><th scope="col">Concepto de la fuente</th><th scope="col">Unidad declarada</th><th scope="col">Estado</th><th scope="col">Sugerencias</th></tr></thead>
                        <tbody>
                            <?php foreach ($filas as $r): ?>
                                <tr>
                                    <td><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" aria-label="Elegir el recaudo del <?= $this->e($this->fecha($r['fecha_pago'])) ?> por <?= $this->e($this->moneda($r['valor'])) ?>"></td>
                                    <td class="numero"><?= $this->e($this->fecha($r['fecha_pago'])) ?></td>
                                    <td class="numero"><?= $this->e($this->moneda($r['valor'])) ?></td>
                                    <td><?= $this->e($r['concepto_fuente'] ?? '—') ?></td>
                                    <td><?= $this->e($r['unidad_fuente'] ?? '—') ?></td>
                                    <td><span class="insignia <?= $r['estado_clasificacion'] === 'ambiguo' ? 'insignia-error' : 'insignia-advertencia' ?>"><?= $r['estado_clasificacion'] === 'ambiguo' ? 'Ambiguo' : 'Pendiente' ?></span></td>
                                    <td>
                                        <?php if (isset($r['sugerencias']['unidad_id'])): ?><div><?= $this->e($nombres[$r['sugerencias']['unidad_id']['valor']] ?? '') ?> <span class="texto-secundario">(<?= $this->e($r['sugerencias']['unidad_id']['razon']) ?>)</span></div><?php endif; ?>
                                        <?php if (isset($r['sugerencias']['producto_id'])): ?><div><?= $this->e($productosNombre[$r['sugerencias']['producto_id']['valor']] ?? '') ?> <span class="texto-secundario">(<?= $this->e($r['sugerencias']['producto_id']['razon']) ?>)</span></div><?php endif; ?>
                                        <?php if (($r['sugerencias'] ?? []) === []): ?><span class="texto-secundario">Sin sugerencia</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="rejilla-campos">
                    <?= $this->parcial('parciales/campo', ['nombre' => 'unidad_id', 'etiqueta' => 'Unidad para los elegidos', 'tipo' => 'select', 'opciones' => $opcionesUnidades, 'vacio' => 'Elige…', 'errores' => $errores]) ?>
                    <?= $this->parcial('parciales/campo', ['nombre' => 'producto_id', 'etiqueta' => 'Producto (opcional)', 'tipo' => 'select', 'opciones' => $opcionesProductos, 'vacio' => 'Conservar el concepto de la fuente', 'errores' => $errores]) ?>
                </div>
                <p class="campo-ayuda">Un recaudo ambiguo («Ganadería y Granja») se asigna completo a la unidad que decidas; el sistema no lo reparte.</p>
                <button type="submit" class="boton boton-primario">Clasificar los elegidos</button>
            </form>
        <?php endif; ?>
    </section>
</div>
