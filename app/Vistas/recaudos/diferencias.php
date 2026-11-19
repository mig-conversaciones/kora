<?php
/**
 * Informe de diferencias de conciliación por periodo (RF-032). Variables: $filas, $anio, $mes.
 *
 * @var App\Nucleo\Vista $this
 */
$meses = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d);
$campos = $campo(['nombre' => 'anio', 'etiqueta' => 'Año', 'tipo' => 'number', 'valor' => (string) $anio, 'atributos' => 'min="2020" max="2100"'])
    . $campo(['nombre' => 'mes', 'etiqueta' => 'Mes', 'tipo' => 'select', 'opciones' => array_map('strval', $meses), 'valor' => (string) $mes]);
?>
<div id="panel-modulo">
    <div class="barra-acciones"><?= $this->parcial('parciales/barra_filtros', ['ruta' => '/recaudos/diferencias', 'campos' => $campos]) ?></div>
    <section class="tarjeta seccion" aria-labelledby="titulo-diferencias">
        <h2 id="titulo-diferencias" class="titulo-tarjeta">Diferencias de <?= $this->e($meses[$mes] . ' de ' . $anio) ?></h2>
        <?php if ($filas === []): ?>
            <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'Sin diferencias en el periodo', 'descripcion' => 'Ningún recaudo conciliado del mes tiene un valor distinto al de sus ventas.', 'accion' => null]) ?>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-diferencias">
                <table class="tabla">
                    <thead><tr><th scope="col">Fecha de pago</th><th scope="col">Cupón / prefactura</th><th scope="col" class="numero">Recaudo</th><th scope="col" class="numero">Ventas (<?= '#' ?>)</th><th scope="col" class="numero">Diferencia</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
                    <tbody>
                        <?php foreach ($filas as $f): ?>
                            <tr>
                                <td class="numero"><?= $this->e($this->fecha($f['fecha_pago'])) ?></td>
                                <td><?= $this->e($f['cupon_pago'] ?? $f['prefactura'] ?? '—') ?></td>
                                <td class="numero"><?= $this->e($this->moneda($f['valor'])) ?></td>
                                <td class="numero"><?= $this->e($this->moneda($f['valor_ventas'])) ?> (<?= (int) $f['ventas'] ?>)</td>
                                <td class="numero"><strong><?= $this->e($this->moneda($f['diferencia'])) ?></strong></td>
                                <td><a class="boton boton-pequeno boton-texto" href="<?= $this->e($this->url('/recaudos/' . (int) $f['id'] . '/conciliar')) ?>">Revisar</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
