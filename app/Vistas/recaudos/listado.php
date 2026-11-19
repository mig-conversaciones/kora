<?php
/**
 * Consulta de recaudos (RF-035). Sin acciones de escritura para quien no es del Grupo de Recaudo (RF-029).
 * Variables: $filas, $hayMas, $pagina, $filtros, $unidades, $productos, $nombresUnidades, $puedeRegistrar.
 *
 * @var App\Nucleo\Vista $this
 */
$opcionesUnidades = [];
foreach ($unidades as $u) {
    $opcionesUnidades[(string) $u['id']] = $u['nombre'];
}
$opcionesProductos = [];
foreach ($productos as $p) {
    $opcionesProductos[(string) $p['id']] = $p['nombre'];
}
$pasarelas = array_combine(App\Servicios\ServicioRecaudos::PASARELAS, App\Servicios\ServicioRecaudos::PASARELAS);
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d);
$campos = $campo(['nombre' => 'desde', 'etiqueta' => 'Desde', 'tipo' => 'date', 'valor' => (string) $filtros['desde']])
    . $campo(['nombre' => 'hasta', 'etiqueta' => 'Hasta', 'tipo' => 'date', 'valor' => (string) $filtros['hasta']])
    . $campo(['nombre' => 'unidad', 'etiqueta' => 'Unidad', 'tipo' => 'select', 'opciones' => $opcionesUnidades, 'valor' => (string) $filtros['unidad'], 'vacio' => 'Todas mis unidades'])
    . $campo(['nombre' => 'producto', 'etiqueta' => 'Producto', 'tipo' => 'select', 'opciones' => $opcionesProductos, 'valor' => (string) $filtros['producto'], 'vacio' => 'Todos'])
    . $campo(['nombre' => 'pasarela', 'etiqueta' => 'Pasarela', 'tipo' => 'select', 'opciones' => $pasarelas, 'valor' => (string) $filtros['pasarela'], 'vacio' => 'Todas'])
    . $campo(['nombre' => 'clasificacion', 'etiqueta' => 'Clasificación', 'tipo' => 'select', 'opciones' => ['pendiente' => 'Pendiente', 'clasificado' => 'Clasificado', 'ambiguo' => 'Ambiguo'], 'valor' => (string) $filtros['clasificacion'], 'vacio' => 'Todas'])
    . $campo(['nombre' => 'conciliacion', 'etiqueta' => 'Conciliación', 'tipo' => 'select', 'opciones' => ['sin_conciliar' => 'Sin conciliar', 'conciliado' => 'Conciliado', 'con_diferencia' => 'Con diferencia'], 'valor' => (string) $filtros['conciliacion'], 'vacio' => 'Todas'])
    . $campo(['nombre' => 'orden', 'etiqueta' => 'Ordenar por', 'tipo' => 'select', 'opciones' => App\Servicios\ServicioRecaudos::ORDENES, 'valor' => $filtros['orden']]);
$nuevo = $this->url('/recaudos/nuevo');
$estados = ['pendiente' => ['Pendiente', 'advertencia'], 'clasificado' => ['Clasificado', 'exito'], 'ambiguo' => ['Ambiguo', 'error']];
$conciliacion = ['sin_conciliar' => 'Sin conciliar', 'conciliado' => 'Conciliado', 'con_diferencia' => 'Con diferencia'];
?>
<div id="panel-modulo">
    <div class="barra-acciones">
        <?= $this->parcial('parciales/barra_filtros', ['ruta' => '/recaudos', 'campos' => $campos]) ?>
        <?php if ($puedeRegistrar): ?>
            <a class="boton boton-primario" href="<?= $this->e($nuevo) ?>" hx-get="<?= $this->e($nuevo) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Alta manual</a>
        <?php endif; ?>
    </div>
    <section class="tarjeta seccion" aria-labelledby="titulo-recaudos">
        <h2 id="titulo-recaudos" class="titulo-tarjeta">Recaudos</h2>
        <?php if ($filas === []): ?>
            <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'No hay recaudos con estos filtros', 'descripcion' => 'Los recaudos llegan del archivo de la Dirección General (importación en la I5) o los registra el Grupo de Recaudo.', 'accion' => null]) ?>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-recaudos">
                <table class="tabla">
                    <thead><tr><th scope="col">Fecha de pago</th><th scope="col">Unidad</th><th scope="col">Producto o concepto</th><th scope="col">Pasarela</th><th scope="col">Cupón / prefactura</th><th scope="col" class="numero">Valor</th><th scope="col">Clasificación</th><th scope="col">Conciliación</th><?php if ($puedeGestionar): ?><th scope="col"><span class="sr-only">Acciones</span></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($filas as $r): ?>
                            <?php [$textoEstado, $tono] = $estados[$r['estado_clasificacion']] ?? ['—', 'neutra']; ?>
                            <tr>
                                <td class="numero"><?= $this->e($this->fecha($r['fecha_pago'])) ?></td>
                                <td><?= $r['unidad_id'] === null ? '<span class="texto-secundario">Sin unidad</span>' : $this->e($nombresUnidades[(string) $r['unidad_id']] ?? '') ?></td>
                                <td><?= $this->e($r['producto_nombre'] ?? $r['concepto_fuente'] ?? '—') ?></td>
                                <td><?= $this->e($r['pasarela'] ?? '—') ?></td>
                                <td><?= $this->e($r['cupon_pago'] ?? $r['prefactura'] ?? '—') ?></td>
                                <td class="numero"><?= $this->e($this->moneda($r['valor'])) ?></td>
                                <td><span class="insignia insignia-<?= $this->e($tono) ?>"><?= $this->e($textoEstado) ?></span></td>
                                <td><?= $this->e($conciliacion[$r['estado_conciliacion']] ?? '') ?></td>
                                <?php if ($puedeGestionar): ?><td><a class="boton boton-pequeno boton-texto" href="<?= $this->e($this->url('/recaudos/' . (int) $r['id'] . '/conciliar')) ?>">Conciliar</a></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $this->parcial('parciales/paginacion', ['ruta' => '/recaudos', 'consulta' => $filtros, 'pagina' => $pagina, 'hayMas' => $hayMas, 'objetivo' => '#panel-modulo']) ?>
        <?php endif; ?>
    </section>
</div>
