<?php
/**
 * Gastos ejecutados (RF-025). Variables: $filas, $hayMas, $pagina, $filtros, $categorias, $unidades,
 * $nombresUnidades, $puedeEditar.
 *
 * @var App\Nucleo\Vista $this
 */
$meses = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$opcionesUnidades = [];
foreach ($unidades as $u) {
    $opcionesUnidades[(string) $u['id']] = $u['nombre'];
}
$select = function (string $nombre, string $etiqueta, array $opciones, mixed $valor, string $vacio): string {
    return $this->parcial('parciales/campo', ['nombre' => $nombre, 'etiqueta' => $etiqueta, 'tipo' => 'select', 'opciones' => $opciones, 'valor' => (string) $valor, 'vacio' => $vacio]);
};
$extra = $select('unidad', 'Unidad', $opcionesUnidades, $filtros['unidad'], 'Todas mis unidades')
    . $select('mes', 'Mes', array_map('strval', $meses), $filtros['mes'], 'Todo el año')
    . $select('categoria', 'Categoría', $categorias, $filtros['categoria'], 'Todas');
$nuevo = $this->url('/finanzas/gastos/nuevo');
$total = '0.00';
foreach ($filas as $g) {
    $total = App\Nucleo\Entrada::sumar($total, (string) $g['valor']);
}
?>
<div id="panel-modulo">
    <div class="barra-acciones">
        <?= $this->parcial('parciales/selector_vigencia', ['ruta' => '/finanzas/gastos', 'vigencia' => $filtros['vigencia'], 'extra' => $extra]) ?>
        <?php if ($puedeEditar && $unidades !== []): ?>
            <a class="boton boton-primario" href="<?= $this->e($nuevo) ?>" hx-get="<?= $this->e($nuevo) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Registrar gasto</a>
        <?php endif; ?>
    </div>
    <section class="tarjeta seccion" aria-labelledby="titulo-gastos">
        <h2 id="titulo-gastos" class="titulo-tarjeta">Gastos <?= $filtros['mes'] === null ? (int) $filtros['vigencia'] : $this->e($meses[$filtros['mes']] . ' de ' . (int) $filtros['vigencia']) ?></h2>
        <?php if ($filas === []): ?>
            <?= $this->parcial('parciales/estado_vacio', [
                'titulo' => 'No hay gastos con estos filtros',
                'descripcion' => $puedeEditar ? 'Registra un gasto con su categoría y, si lo tienes, su soporte en PDF o imagen.' : 'Prueba con otra vigencia, mes o categoría.',
                'accion' => $puedeEditar && $unidades !== [] ? ['texto' => 'Registrar gasto', 'url' => $nuevo] : null,
            ]) ?>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-gastos">
                <table class="tabla">
                    <thead><tr><th scope="col">Fecha</th><th scope="col">Unidad</th><th scope="col">Categoría</th><th scope="col">Descripción</th><th scope="col" class="numero">Valor</th><th scope="col" class="numero">Componente académico</th><?php if ($puedeEditar): ?><th scope="col"><span class="sr-only">Acciones</span></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($filas as $g): ?>
                            <tr>
                                <td class="numero"><?= $this->e($this->fecha($g['fecha'])) ?></td>
                                <td><?= $this->e($nombresUnidades[(string) $g['unidad_id']] ?? $g['unidad_id']) ?></td>
                                <td><?= $this->e($categorias[(string) $g['categoria_id']] ?? $g['categoria_id']) ?></td>
                                <td><?= $this->e(mb_strimwidth((string) ($g['descripcion'] ?? ''), 0, 80, '…')) ?></td>
                                <td class="numero"><?= $this->e($this->moneda($g['valor'])) ?></td>
                                <td class="numero"><?= $this->e($this->moneda($g['valor_componente_academico'])) ?></td>
                                <?php if ($puedeEditar): ?>
                                    <?php $editar = $this->url('/finanzas/gastos/' . (int) $g['id'] . '/editar'); ?>
                                    <td><a class="boton boton-pequeno boton-texto" href="<?= $this->e($editar) ?>" hx-get="<?= $this->e($editar) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Abrir</a></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><th scope="row" colspan="4">Total de la página</th><td class="numero"><strong><?= $this->e($this->moneda($total)) ?></strong></td><td colspan="<?= $puedeEditar ? 2 : 1 ?>"></td></tr></tfoot>
                </table>
            </div>
            <?= $this->parcial('parciales/paginacion', ['ruta' => '/finanzas/gastos', 'consulta' => ['vigencia' => $filtros['vigencia'], 'unidad' => $filtros['unidad'], 'mes' => $filtros['mes'], 'categoria' => $filtros['categoria']], 'pagina' => $pagina, 'hayMas' => $hayMas, 'objetivo' => '#panel-modulo']) ?>
        <?php endif; ?>
    </section>
</div>
