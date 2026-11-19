<?php
/**
 * Salidas: venta directa y autoconsumo (RF-043 a RF-046). Variables: $filas, $hayMas, $pagina, $filtros, $unidades,
 * $nombresUnidades, $puedeEditar.
 *
 * @var App\Nucleo\Vista $this
 */
$opcionesUnidades = [];
foreach ($unidades as $u) {
    $opcionesUnidades[(string) $u['id']] = $u['nombre'];
}
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d);
$campos = $campo(['nombre' => 'unidad', 'etiqueta' => 'Unidad', 'tipo' => 'select', 'opciones' => $opcionesUnidades, 'valor' => (string) $filtros['unidad'], 'vacio' => 'Todas mis unidades'])
    . $campo(['nombre' => 'canal', 'etiqueta' => 'Canal', 'tipo' => 'select', 'opciones' => ['VENTA_DIRECTA' => 'Venta directa', 'AUTOCONSUMO' => 'Autoconsumo'], 'valor' => (string) $filtros['canal'], 'vacio' => 'Todos'])
    . $campo(['nombre' => 'desde', 'etiqueta' => 'Desde', 'tipo' => 'date', 'valor' => (string) $filtros['desde']])
    . $campo(['nombre' => 'hasta', 'etiqueta' => 'Hasta', 'tipo' => 'date', 'valor' => (string) $filtros['hasta']]);
$nuevo = $this->url('/produccion/salidas/nueva');
$consulta = $filtros;
$consulta['sin_recepcion'] = $filtros['sin_recepcion'] ? '1' : '';
?>
<div id="panel-modulo">
    <div class="barra-acciones">
        <?= $this->parcial('parciales/barra_filtros', ['ruta' => '/produccion/salidas', 'campos' => $campos]) ?>
        <?php if ($puedeEditar && $unidades !== []): ?>
            <a class="boton boton-primario" href="<?= $this->e($nuevo) ?>" hx-get="<?= $this->e($nuevo) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Registrar salida</a>
        <?php endif; ?>
    </div>
    <section class="tarjeta seccion" aria-labelledby="titulo-salidas">
        <h2 id="titulo-salidas" class="titulo-tarjeta">Salidas<?= $filtros['sin_recepcion'] ? ' sin recepción vinculada' : '' ?></h2>
        <?php if ($filas === []): ?>
            <?= $this->parcial('parciales/estado_vacio', [
                'titulo' => 'No hay salidas con estos filtros',
                'descripcion' => $puedeEditar ? 'Registra una salida desde un lote con saldo: hereda su unidad, producto y medida.' : 'Prueba con otra unidad, canal o rango de fechas.',
                'accion' => $puedeEditar && $unidades !== [] ? ['texto' => 'Registrar salida', 'url' => '/produccion/salidas/nueva'] : null,
            ]) ?>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-salidas">
                <table class="tabla">
                    <thead><tr><th scope="col">Fecha</th><th scope="col">Lote</th><th scope="col">Unidad</th><th scope="col">Producto</th><th scope="col">Canal</th><th scope="col" class="numero">Cantidad</th><th scope="col" class="numero">Valor</th><th scope="col">Proceso o factura</th><?php if ($puedeEditar): ?><th scope="col"><span class="sr-only">Acciones</span></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($filas as $s): ?>
                            <tr>
                                <td class="numero"><?= $this->e($this->fecha($s['fecha'])) ?></td>
                                <td><?= $s['recepcion_id'] === null ? '<span class="insignia insignia-advertencia">Sin recepción</span>' : '<code>' . $this->e($s['numero_lote']) . '</code>' ?></td>
                                <td><?= $this->e($nombresUnidades[(string) $s['unidad_id']] ?? $s['unidad_id']) ?></td>
                                <td><?= $this->e($s['producto_nombre']) ?></td>
                                <td><?= $s['canal'] === 'AUTOCONSUMO' ? 'Autoconsumo' : 'Venta directa' ?></td>
                                <td class="numero"><?= $this->e($this->cantidad($s['cantidad'])) ?> <?= $this->e($s['medida']) ?></td>
                                <td class="numero"><?= $this->e($this->moneda($s['valor'])) ?></td>
                                <td><?= $s['proceso_codigo'] !== null ? '<code>' . $this->e($s['proceso_codigo']) . '</code>' : $this->e($s['factura'] ?? ($s['canal'] === 'AUTOCONSUMO' ? 'Sin proceso' : '—')) ?></td>
                                <?php if ($puedeEditar): ?>
                                    <td><?php if ($s['recepcion_id'] !== null): ?><?php $editar = $this->url('/produccion/salidas/' . (int) $s['id'] . '/editar'); ?><a class="boton boton-pequeno boton-texto" href="<?= $this->e($editar) ?>" hx-get="<?= $this->e($editar) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Abrir</a><?php endif; ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $this->parcial('parciales/paginacion', ['ruta' => '/produccion/salidas', 'consulta' => $consulta, 'pagina' => $pagina, 'hayMas' => $hayMas, 'objetivo' => '#panel-modulo']) ?>
        <?php endif; ?>
    </section>
</div>
