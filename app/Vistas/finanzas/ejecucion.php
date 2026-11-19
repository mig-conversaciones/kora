<?php
/**
 * Ejecución por línea presupuestal (RF-026). Variables: $filas, $vigencia, $unidad, $unidades.
 *
 * @var App\Nucleo\Vista $this
 */
$lineas = App\Servicios\ServicioAsignaciones::LINEAS;
$opcionesUnidades = [];
foreach ($unidades as $u) {
    $opcionesUnidades[(string) $u['id']] = $u['nombre'];
}
$extra = $this->parcial('parciales/campo', ['nombre' => 'unidad', 'etiqueta' => 'Unidad', 'tipo' => 'select', 'opciones' => $opcionesUnidades, 'valor' => (string) $unidad, 'vacio' => 'Todas mis unidades']);
$porcentaje = static fn (string $ejecutado, string $asignado): ?float => (float) $asignado > 0 ? round((float) $ejecutado / (float) $asignado * 100, 1) : null;
?>
<div id="panel-modulo">
    <?= $this->parcial('parciales/selector_vigencia', ['ruta' => '/finanzas/ejecucion', 'vigencia' => $vigencia, 'extra' => $extra]) ?>
    <div class="alerta alerta-info">
        <?= $this->parcial('parciales/icono', ['nombre' => 'info', 'clase' => 'alerta-icono']) ?>
        <div class="alerta-cuerpo"><p><strong>No corresponde a ejecución CDP/RP.</strong> Compara la asignación de la unidad con los gastos registrados en KORA; la ejecución presupuestal oficial está en el sistema financiero del SENA.</p></div>
    </div>
    <?php if ($filas === []): ?>
        <section class="tarjeta seccion">
            <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'Sin asignaciones ni gastos en ' . (int) $vigencia, 'descripcion' => 'Cuando haya asignaciones o gastos registrados para tus unidades, aquí verás su ejecución por línea.', 'accion' => null]) ?>
        </section>
    <?php endif; ?>
    <?php foreach ($filas as $f): ?>
        <section class="tarjeta seccion" aria-labelledby="titulo-ejecucion-<?= (int) $f['unidad_id'] ?>">
            <h2 id="titulo-ejecucion-<?= (int) $f['unidad_id'] ?>" class="titulo-tarjeta"><?= $this->e($f['unidad']) ?></h2>
            <?php if ($f['asignacion'] === null): ?>
                <p class="texto-advertencia">Sin asignación activa para <?= (int) $vigencia ?>: los gastos se muestran sin saldo.</p>
            <?php else: ?>
                <p class="texto-secundario">Asignación total: <strong><?= $this->e($this->moneda($f['asignacion']['valor_total'])) ?></strong></p>
            <?php endif; ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-ejecucion-<?= (int) $f['unidad_id'] ?>">
                <table class="tabla">
                    <thead><tr><th scope="col">Línea</th><th scope="col" class="numero">Asignado</th><th scope="col" class="numero">Ejecutado</th><th scope="col" class="numero">Saldo</th><th scope="col">Avance</th></tr></thead>
                    <tbody>
                        <?php foreach ($lineas as $linea => $texto): ?>
                            <?php $l = $f['lineas'][$linea]; $p = $porcentaje($l['ejecutado'], $l['asignado']); $saldo = (float) $l['asignado'] - (float) $l['ejecutado']; ?>
                            <tr>
                                <th scope="row"><?= $this->e($texto) ?></th>
                                <td class="numero"><?= $this->e($this->moneda($l['asignado'])) ?></td>
                                <td class="numero"><?= $this->e($this->moneda($l['ejecutado'])) ?></td>
                                <td class="numero<?= $saldo < 0 ? ' texto-error' : '' ?>"><?= $this->e($this->moneda($saldo)) ?></td>
                                <td>
                                    <?php if ($p === null): ?>
                                        <span class="texto-secundario">Sin asignado</span>
                                    <?php else: ?>
                                        <meter class="medidor-avance" min="0" max="100" high="90" optimum="50" value="<?= $this->e(min(100, $p)) ?>" aria-label="<?= $this->e($this->decimal($p, 1)) ?> % ejecutado"></meter>
                                        <span class="numero"><?= $this->e($this->decimal($p, 1)) ?> %</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ((float) $f['otras'] > 0): ?>
                            <tr><th scope="row">Otras categorías (sin línea)</th><td class="numero">—</td><td class="numero"><?= $this->e($this->moneda($f['otras'])) ?></td><td class="numero">—</td><td></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
</div>
