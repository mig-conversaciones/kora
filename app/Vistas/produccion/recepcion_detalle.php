<?php
/**
 * Detalle del lote (RF-041): recibido, entregado, saldo, número de salidas y fecha de cierre, con sus salidas.
 * Variables: $lote, $salidas, $nombresUnidades, $puedeEditar, $puedeSalida.
 *
 * @var App\Nucleo\Vista $this
 */
$editar = $this->url('/produccion/recepciones/' . (int) $lote['id'] . '/editar');
$nuevaSalida = $this->url('/produccion/salidas/nueva') . '?lote=' . (int) $lote['id'];
?>
<div id="panel-modulo">
    <section class="tarjeta seccion" aria-labelledby="titulo-lote">
        <h2 id="titulo-lote" class="titulo-tarjeta">Lote <code><?= $this->e($lote['numero_interno']) ?></code>
            <span class="insignia <?= $lote['estado_lote'] === 'abierto' ? 'insignia-info' : 'insignia-neutra' ?>"><?= $lote['estado_lote'] === 'abierto' ? 'Abierto' : 'Cerrado' ?></span></h2>
        <dl class="lista-datos">
            <div><dt>Producto</dt><dd><?= $this->e($lote['producto_nombre']) ?> <span class="texto-secundario">(<?= $this->e($lote['producto_codigo']) ?>)</span></dd></div>
            <div><dt>Unidad</dt><dd><?= $this->e($nombresUnidades[(string) $lote['unidad_id']] ?? '') ?></dd></div>
            <div><dt>Fecha de recepción</dt><dd><?= $this->e($this->fecha($lote['fecha_recepcion'])) ?></dd></div>
            <div><dt>Recibido</dt><dd><?= $this->e($this->cantidad($lote['cantidad'])) ?> <?= $this->e($lote['medida']) ?></dd></div>
            <div><dt>Entregado</dt><dd><?= $this->e($this->cantidad($lote['entregado'])) ?> <?= $this->e($lote['medida']) ?> en <?= (int) $lote['salidas'] ?> salidas</dd></div>
            <div><dt>Saldo</dt><dd><strong><?= $this->e($this->cantidad($lote['saldo'])) ?> <?= $this->e($lote['medida']) ?></strong></dd></div>
            <div><dt>Cierre</dt><dd><?= $lote['fecha_cierre'] === null ? 'Sin cerrar' : $this->e($this->fecha($lote['fecha_cierre'])) ?></dd></div>
            <?php if ($lote['precio_sugerido'] !== null): ?><div><dt>Precio sugerido</dt><dd><?= $this->e($this->moneda($lote['precio_sugerido'])) ?></dd></div><?php endif; ?>
        </dl>
        <div class="grupo-botones">
            <?php if ($puedeSalida && $lote['estado_lote'] === 'abierto'): ?>
                <a class="boton boton-primario" href="<?= $this->e($nuevaSalida) ?>" hx-get="<?= $this->e($nuevaSalida) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Registrar salida</a>
            <?php endif; ?>
            <?php if ($puedeEditar): ?>
                <a class="boton" href="<?= $this->e($editar) ?>" hx-get="<?= $this->e($editar) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Editar lote</a>
            <?php endif; ?>
            <a class="boton boton-texto" href="<?= $this->e($this->url('/produccion/recepciones')) ?>">Volver a los lotes</a>
        </div>
    </section>
    <section class="tarjeta seccion" aria-labelledby="titulo-salidas-lote">
        <h2 id="titulo-salidas-lote" class="titulo-tarjeta">Salidas del lote</h2>
        <?php if ($salidas === []): ?>
            <p class="texto-secundario">El lote aún no tiene salidas.</p>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-salidas-lote">
                <table class="tabla">
                    <thead><tr><th scope="col">Fecha</th><th scope="col">Canal</th><th scope="col" class="numero">Cantidad</th><th scope="col" class="numero">Precio</th><th scope="col" class="numero">Valor</th><th scope="col">Destino o factura</th></tr></thead>
                    <tbody>
                        <?php foreach ($salidas as $s): ?>
                            <tr>
                                <td class="numero"><?= $this->e($this->fecha($s['fecha'])) ?></td>
                                <td><?= $s['canal'] === 'AUTOCONSUMO' ? 'Autoconsumo' : 'Venta directa' ?></td>
                                <td class="numero"><?= $this->e($this->cantidad($s['cantidad'])) ?> <?= $this->e($s['medida']) ?></td>
                                <td class="numero"><?= $this->e($this->moneda($s['precio_unitario'])) ?></td>
                                <td class="numero"><?= $this->e($this->moneda($s['valor'])) ?></td>
                                <td><?= $this->e($s['destino'] ?? $s['factura'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
