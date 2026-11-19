<?php
/**
 * Recepciones y lotes con su saldo (RF-039 a RF-041). Variables: $filas, $hayMas, $pagina, $filtros, $unidades,
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
    . $campo(['nombre' => 'estado', 'etiqueta' => 'Estado del lote', 'tipo' => 'select', 'opciones' => ['abierto' => 'Abiertos (con saldo)', 'cerrado' => 'Cerrados'], 'valor' => (string) $filtros['estado'], 'vacio' => 'Todos'])
    . $campo(['nombre' => 'desde', 'etiqueta' => 'Desde', 'tipo' => 'date', 'valor' => (string) $filtros['desde']])
    . $campo(['nombre' => 'hasta', 'etiqueta' => 'Hasta', 'tipo' => 'date', 'valor' => (string) $filtros['hasta']]);
$nuevo = $this->url('/produccion/recepciones/nueva');
?>
<div id="panel-modulo">
    <div class="barra-acciones">
        <?= $this->parcial('parciales/barra_filtros', ['ruta' => '/produccion/recepciones', 'campos' => $campos]) ?>
        <?php if ($puedeEditar && $unidades !== []): ?>
            <a class="boton boton-primario" href="<?= $this->e($nuevo) ?>" hx-get="<?= $this->e($nuevo) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Registrar recepción</a>
        <?php endif; ?>
    </div>
    <section class="tarjeta seccion" aria-labelledby="titulo-recepciones">
        <h2 id="titulo-recepciones" class="titulo-tarjeta">Lotes recibidos</h2>
        <?php if ($filas === []): ?>
            <?= $this->parcial('parciales/estado_vacio', [
                'titulo' => 'No hay recepciones con estos filtros',
                'descripcion' => $puedeEditar ? 'Registra lo que recibe la unidad con su número interno; el sistema sugiere el siguiente consecutivo.' : 'Prueba con otra unidad o rango de fechas.',
                'accion' => $puedeEditar && $unidades !== [] ? ['texto' => 'Registrar recepción', 'url' => '/produccion/recepciones/nueva'] : null,
            ]) ?>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-recepciones">
                <table class="tabla">
                    <thead><tr><th scope="col">Número interno</th><th scope="col">Fecha</th><th scope="col">Unidad</th><th scope="col">Producto</th><th scope="col" class="numero">Recibido</th><th scope="col" class="numero">Entregado</th><th scope="col" class="numero">Saldo</th><th scope="col">Estado</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
                    <tbody>
                        <?php foreach ($filas as $l): ?>
                            <?php $detalle = $this->url('/produccion/recepciones/' . (int) $l['id']); ?>
                            <tr>
                                <td><code><?= $this->e($l['numero_interno']) ?></code></td>
                                <td class="numero"><?= $this->e($this->fecha($l['fecha_recepcion'])) ?></td>
                                <td><?= $this->e($nombresUnidades[(string) $l['unidad_id']] ?? $l['unidad_id']) ?></td>
                                <td><?= $this->e($l['producto_nombre']) ?></td>
                                <td class="numero"><?= $this->e($this->cantidad($l['cantidad'])) ?> <?= $this->e($l['medida']) ?></td>
                                <td class="numero"><?= $this->e($this->cantidad($l['entregado'])) ?></td>
                                <td class="numero"><?= $this->e($this->cantidad($l['saldo'])) ?></td>
                                <td><span class="insignia <?= $l['estado_lote'] === 'abierto' ? 'insignia-info' : 'insignia-neutra' ?>"><?= $l['estado_lote'] === 'abierto' ? 'Abierto' : 'Cerrado' ?></span></td>
                                <td><a class="boton boton-pequeno boton-texto" href="<?= $this->e($detalle) ?>" hx-get="<?= $this->e($detalle) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Ver lote<span class="sr-only"> <?= $this->e($l['numero_interno']) ?></span></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $this->parcial('parciales/paginacion', ['ruta' => '/produccion/recepciones', 'consulta' => $filtros, 'pagina' => $pagina, 'hayMas' => $hayMas, 'objetivo' => '#panel-modulo']) ?>
        <?php endif; ?>
    </section>
</div>
