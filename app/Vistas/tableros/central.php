<?php
/**
 * Tablero central (RF-062 a RF-072): filtros, seis KPI, IND_001 con su tabla equivalente, IND_002, estado de la
 * información y aviso de conciliación. Todo se calcula con el alcance de la sesión (Obs. 4).
 * Variables: $filtros, $unidades, $productos, $modo, $tablero (o null), $rutaCentral, $puedeExportar, $puedeTecnico,
 * $errores.
 *
 * @var App\Nucleo\Vista $this
 */
use App\Controladores\ControladorTableros;
use App\Tableros\Formato;
use App\Tableros\Graficos\GraficoBarrasSvg;
use App\Tableros\ServicioExportacion;

$parametrosTecnico = ['vigencia' => (string) $filtros->vigencia] + array_intersect_key($filtros->aConsulta(), ['desde' => 1, 'hasta' => 1]) + ['volver' => $rutaCentral];
$enlaceTecnico = static fn (array $u): ?string => $puedeTecnico ? ControladorTableros::enlace('/tableros/unidad/' . $u['codigo'], $parametrosTecnico) : null;
?>
<div id="panel-tablero">
    <?= $this->parcial('tableros/filtros', ['ruta' => '/tableros/central', 'filtros' => $filtros, 'errores' => $errores, 'unidades' => $unidades, 'productos' => $productos]) ?>
    <?php if (isset($errores['unidad'])): ?>
        <div class="alerta alerta-advertencia" role="status"><div class="alerta-cuerpo"><?= $this->e($errores['unidad']) ?></div></div>
    <?php endif; ?>

    <?php if ($tablero === null): ?>
        <?php if (!$filtros->esValido()): ?>
            <div class="alerta alerta-error" role="alert"><div class="alerta-cuerpo"><p class="alerta-titulo">Revisa el rango de fechas</p><p>No se calculan cifras con un rango inválido. Corrige «desde» o «hasta».</p></div></div>
        <?php else: ?>
            <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'Sin unidades en tu alcance', 'descripcion' => 'Tu cuenta no tiene unidades productivas asignadas. Pide al administrador que te asigne una.', 'accion' => null]) ?>
        <?php endif; ?>
    <?php else: ?>
        <?php
        $k = $tablero['kpi'];
        $e = $tablero['estado'];
        $c = $tablero['conciliacion'];
        $periodo = Formato::fecha($tablero['periodo']['inicio']) . ' a ' . Formato::fecha($tablero['periodo']['fin']);
        ?>
        <section aria-labelledby="titulo-kpi" class="seccion-sin-tarjeta">
            <h2 id="titulo-kpi" class="sr-only">Indicadores del periodo <?= $this->e($periodo) ?></h2>
            <div class="rejilla-kpi rejilla-kpi-seis">
                <article class="tarjeta tarjeta-kpi kpi-presupuesto" tabindex="0" aria-describedby="nota-kpi-asignacion">
                    <h3 class="kpi-etiqueta">Asignación presupuestal <span class="insignia insignia-neutra">ANUAL</span></h3>
                    <p class="kpi-valor" title="<?= $this->e(Formato::moneda($k['asignacion'])) ?>"><?= $this->e(Formato::monedaCorta($k['asignacion'])) ?></p>
                    <p class="kpi-nota" id="nota-kpi-asignacion"><?= $this->e(Formato::moneda($k['asignacion'])) ?> · <?= (int) $k['asignaciones'] ?> asignaciones de la vigencia <?= (int) $filtros->vigencia ?>. No se prorratea por fechas.</p>
                </article>
                <article class="tarjeta tarjeta-kpi kpi-venta" tabindex="0" aria-describedby="nota-kpi-venta">
                    <h3 class="kpi-etiqueta">Recaudo de venta directa</h3>
                    <p class="kpi-valor"><?= $this->e(Formato::monedaCorta($k['venta_directa'])) ?></p>
                    <p class="kpi-nota" id="nota-kpi-venta"><?= $this->e(Formato::moneda($k['venta_directa'])) ?> · <?= (int) $k['recaudos'] ?> recaudos «Producción» del periodo.</p>
                </article>
                <article class="tarjeta tarjeta-kpi kpi-autoconsumo" tabindex="0" aria-describedby="nota-kpi-autoconsumo">
                    <h3 class="kpi-etiqueta">Ventas por autoconsumo</h3>
                    <p class="kpi-valor"><?= $this->e(Formato::monedaCorta($k['autoconsumo'])) ?></p>
                    <p class="kpi-nota" id="nota-kpi-autoconsumo"><?= $this->e(Formato::moneda($k['autoconsumo'])) ?> · <?= (int) $k['salidas'] ?> salidas por autoconsumo del periodo.</p>
                </article>
                <article class="tarjeta tarjeta-kpi" tabindex="0" aria-describedby="nota-kpi-total">
                    <h3 class="kpi-etiqueta">Total de ventas realizadas</h3>
                    <p class="kpi-valor"><?= $this->e(Formato::monedaCorta($k['total'])) ?></p>
                    <p class="kpi-nota" id="nota-kpi-total"><?= $this->e(Formato::moneda($k['total'])) ?> · venta directa + autoconsumo (<?= (int) $k['recaudos'] + (int) $k['salidas'] ?> registros).</p>
                </article>
                <article class="tarjeta tarjeta-kpi" tabindex="0" aria-describedby="nota-kpi-cobertura">
                    <h3 class="kpi-etiqueta">Cobertura sobre la asignación</h3>
                    <p class="kpi-valor"><?= $this->e(Formato::porcentaje($k['cobertura'])) ?></p>
                    <p class="kpi-nota" id="nota-kpi-cobertura">Total de ventas / asignación anual. Es cobertura comercial, no ejecución CDP/RP.</p>
                </article>
                <article class="tarjeta tarjeta-kpi" tabindex="0" aria-describedby="nota-kpi-saldo">
                    <h3 class="kpi-etiqueta">Saldo por cubrir</h3>
                    <p class="kpi-valor"><?= $this->e(Formato::monedaCorta($k['saldo'])) ?></p>
                    <p class="kpi-nota" id="nota-kpi-saldo"><?= $this->e(Formato::moneda($k['saldo'])) ?> · asignación − total de ventas.</p>
                </article>
            </div>
        </section>

        <div class="rejilla-bento">
            <section class="tarjeta tarjeta-bento bento-3x1" aria-labelledby="titulo-ind001">
                <h2 id="titulo-ind001" class="titulo-tarjeta">IND_001 · Asignación frente a ventas por unidad</h2>
                <p class="texto-secundario">Periodo <?= $this->e($periodo) ?>. Enfoca o pasa el puntero por una unidad para ver el detalle<?= $puedeTecnico ? '; actívala para abrir su tablero técnico' : '' ?>.</p>
                <?php if ($tablero['unidades'] === []): ?>
                    <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'Sin asignaciones ni ventas', 'descripcion' => 'No hay asignaciones de la vigencia ni ventas del periodo con estos filtros. Prueba otra vigencia o carga el presupuesto.', 'accion' => ['texto' => 'Ir a Importación de BBDD', 'url' => '/importaciones']]) ?>
                <?php else: ?>
                    <figure class="grafico-barras" aria-labelledby="titulo-ind001">
                        <?= GraficoBarrasSvg::html($tablero['unidades'], $enlaceTecnico) ?>
                    </figure>
                    <details class="tabla-equivalente">
                        <summary>Ver la tabla de datos del gráfico</summary>
                        <div class="contenedor-tabla">
                            <table class="tabla">
                                <caption class="sr-only">IND_001 por unidad, periodo <?= $this->e($periodo) ?></caption>
                                <thead><tr><th scope="col">Unidad</th><th scope="col" class="numero">Asignación</th><th scope="col" class="numero">Venta directa</th><th scope="col" class="numero">Autoconsumo</th><th scope="col" class="numero">Total</th><th scope="col" class="numero">Cobertura</th></tr></thead>
                                <tbody>
                                <?php foreach ($tablero['unidades'] as $u): ?>
                                    <tr>
                                        <th scope="row"><?php $url = $enlaceTecnico($u); ?><?php if ($url !== null): ?><a href="<?= $this->e($url) ?>"><?= $this->e($u['nombre']) ?></a><?php else: ?><?= $this->e($u['nombre']) ?><?php endif; ?></th>
                                        <td class="numero cifras"><?= $this->e(Formato::moneda($u['asignacion'])) ?></td>
                                        <td class="numero cifras"><?= $this->e(Formato::moneda($u['venta_directa'])) ?></td>
                                        <td class="numero cifras"><?= $this->e(Formato::moneda($u['autoconsumo'])) ?></td>
                                        <td class="numero cifras"><?= $this->e(Formato::moneda($u['total'])) ?></td>
                                        <td class="numero cifras"><?= $this->e(Formato::porcentaje($u['cobertura'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endif; ?>
                <?php if ($puedeTecnico && $filtros->unidadId !== null && count($tablero['unidades']) === 1): ?>
                    <p><a class="boton boton-primario" href="<?= $this->e((string) $enlaceTecnico($tablero['unidades'][0])) ?>">Explorar tablero técnico de la unidad</a></p>
                <?php endif; ?>
            </section>

            <section class="tarjeta tarjeta-bento" aria-labelledby="titulo-estado-informacion">
                <h2 id="titulo-estado-informacion" class="titulo-tarjeta">Estado de la información</h2>
                <dl class="lista-datos">
                    <dt>Recaudos</dt><dd><?= $e['recaudos_desde'] === null ? 'Sin recaudos cargados' : $this->e(Formato::fecha($e['recaudos_desde']) . ' al ' . Formato::fecha($e['recaudos_hasta'])) ?></dd>
                    <dt>Salidas</dt><dd><?= $e['salidas_desde'] === null ? 'Sin salidas cargadas' : $this->e(Formato::fecha($e['salidas_desde']) . ' al ' . Formato::fecha($e['salidas_hasta'])) ?></dd>
                    <dt>Salidas sin recepción</dt><dd class="cifras"><?= (int) $e['salidas_sin_recepcion'] ?></dd>
                    <dt>Corte del hato</dt><dd><?= $this->e(Formato::fecha($e['corte_hato'])) ?></dd>
                    <dt>Entregas sin proceso</dt><dd class="cifras"><?= (int) $e['entregas_sin_proceso'] ?></dd>
                    <dt>Recaudos sin unidad</dt><dd class="cifras"><?= (int) $e['recaudos_sin_unidad'] ?></dd>
                </dl>
                <p><a href="<?= $this->e($this->url('/calidad')) ?>">Ir a la bandeja de calidad</a></p>
            </section>
        </div>

        <?php if ((int) $c['transferencias_n'] > 0 || (int) $c['recaudo_externo_n'] > 0): ?>
            <div class="alerta alerta-info" role="note">
                <?= $this->parcial('parciales/icono', ['nombre' => 'info', 'clase' => 'alerta-icono']) ?>
                <div class="alerta-cuerpo">
                    <p class="alerta-titulo">Conciliación del periodo</p>
                    <p>Ventas por transferencia registradas: <strong class="cifras"><?= $this->e(Formato::moneda($c['transferencias'])) ?></strong> (<?= (int) $c['transferencias_n'] ?> salidas). Recaudo externo de la DG: <strong class="cifras"><?= $this->e(Formato::moneda($c['recaudo_externo'])) ?></strong> (<?= (int) $c['recaudo_externo_n'] ?> pagos).</p>
                    <p>Son fuentes con coberturas distintas: se muestran para conciliar y no se suman. La cobertura comercial no es ejecución CDP/RP ni saldo de caja.</p>
                </div>
            </div>
        <?php endif; ?>
        <?php foreach (ServicioExportacion::notasCentral($tablero) as $i => $nota): ?>
            <?php if ($i >= 3): ?><p class="texto-secundario"><?= $this->e($nota) ?></p><?php endif; ?>
        <?php endforeach; ?>

        <?= $this->parcial('tableros/procesos', ['procesos' => $tablero['procesos'], 'sinProceso' => $tablero['sin_proceso'], 'modo' => $modo, 'filtros' => $filtros]) ?>

        <?php if ($puedeExportar): ?>
            <?= $this->parcial('tableros/exportar', ['tipo' => 'tablero_central', 'formatos' => ['xlsx', 'csv', 'pdf'], 'parametros' => $filtros->aConsulta()]) ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
