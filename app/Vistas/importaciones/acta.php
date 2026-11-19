<?php
/**
 * Acta de carga (RF-094): para cada lote aplicado, las cifras de control calculadas del archivo frente a las que quedaron
 * en la base. Una carga con diferencias no se marca como verificada. Variables: $lotes, $fuentes.
 *
 * @var App\Nucleo\Vista $this
 */
$verificados = 0;
foreach ($lotes as $l) {
    $c = json_decode((string) ($l['cifras_control'] ?? '{}'), true) ?: [];
    $verificados += !empty($c['verificada']) && $l['estado'] === 'aplicado' ? 1 : 0;
}
?>
<div id="panel-modulo">
    <section class="tarjeta seccion" aria-labelledby="titulo-acta">
        <h2 id="titulo-acta" class="titulo-tarjeta">Acta de carga — <?= $this->e($this->fecha(gmdate('Y-m-d'))) ?></h2>
        <?php if ($lotes === []): ?>
            <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'No hay lotes aplicados', 'descripcion' => 'El acta reúne las cifras de control de cada lote aplicado.', 'accion' => null]) ?>
        <?php else: ?>
            <p><strong><?= $verificados ?></strong> de <?= count($lotes) ?> lotes verificados.</p>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-acta">
                <table class="tabla">
                    <thead><tr><th scope="col">Fuente</th><th scope="col">Archivo</th><th scope="col">Aplicado</th><th scope="col" class="numero">Filas en el archivo</th><th scope="col" class="numero">Registros en la base</th><th scope="col" class="numero">Suma del archivo</th><th scope="col" class="numero">Suma en la base</th><th scope="col">Resultado</th></tr></thead>
                    <tbody>
                        <?php foreach ($lotes as $l): ?>
                            <?php $c = json_decode((string) ($l['cifras_control'] ?? '{}'), true) ?: []; ?>
                            <tr>
                                <td><?= $this->e(isset($fuentes[$l['fuente']]) ? $fuentes[$l['fuente']]->nombre() : $l['fuente']) ?></td>
                                <td><?= $this->e(mb_strimwidth((string) $l['nombre_archivo'], 0, 40, '…')) ?></td>
                                <td class="numero"><?= $this->e($this->fecha($l['aplicado_en'] ?? '')) ?></td>
                                <td class="numero"><?= (int) ($c['archivo']['filas'] ?? 0) ?></td>
                                <td class="numero"><?= (int) ($c['aplicado']['filas'] ?? 0) ?></td>
                                <td class="numero"><?= $this->e($this->cantidad($c['archivo']['suma'] ?? '0')) ?></td>
                                <td class="numero"><?= $this->e($this->cantidad($c['aplicado']['suma'] ?? '0')) ?></td>
                                <td>
                                    <?php if ($l['estado'] === 'revertido'): ?><span class="insignia insignia-neutra">Revertido</span>
                                    <?php elseif (!empty($c['verificada'])): ?><span class="insignia insignia-exito">Verificada</span>
                                    <?php else: ?><span class="insignia insignia-advertencia">Con diferencias</span><?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($c['archivo']['por_unidad'])): ?>
                                <tr><td></td><td colspan="7" class="texto-secundario">Por unidad en el archivo:
                                    <?php foreach ($c['archivo']['por_unidad'] as $u => $x): ?><?= $this->e($u) ?> <?= (int) $x['filas'] ?> filas (<?= $this->e($this->cantidad($x['suma'])) ?>) · <?php endforeach; ?>
                                </td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="campo-ayuda">La suma es la del valor de cada fuente (presupuesto, recaudos, ventas, gastos), la cantidad (recepciones) o el cupo (procesos). Las diferencias suelen venir de filas con error que no se aplicaron.</p>
        <?php endif; ?>
    </section>
</div>
