<?php
/**
 * Tablero técnico de unidad (RF-073 a RF-079): hato (IND_003 y salud reproductiva), IND_08, IND_12 e IND_17. El
 * corte del hato es independiente de las fechas. «Volver» recupera los filtros del tablero central (RF-071).
 * Variables: $unidad, $filtros, $practica, $habilitado, $tablero (o null), $filtroSalud, $academica, $volver,
 * $puedeCentral, $puedeExportar, $errores, $solicitud.
 *
 * @var App\Nucleo\Vista $this
 */
use App\Controladores\ControladorTableros;
use App\Tableros\Formato;
use App\Tableros\Graficos\GraficoVelocimetroSvg;

$ruta = '/tableros/unidad/' . $unidad['codigo'];
$derivado = (string) ($solicitud->consulta['derivado'] ?? 'global');
?>
<div id="panel-tablero">
    <div class="barra-acciones">
        <?php if ($puedeCentral): ?>
            <a class="boton" href="<?= $this->e($this->url($volver)) ?>">← Volver al tablero central</a>
        <?php endif; ?>
    </div>
    <?= $this->parcial('tableros/filtros', ['ruta' => $ruta, 'filtros' => $filtros, 'errores' => $errores, 'unidades' => null, 'productos' => null, 'ocultos' => ['practica' => $practica ? '1' : '0', 'volver' => $volver]]) ?>

    <?php if (!$habilitado): ?>
        <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'Tablero técnico en preparación', 'descripcion' => 'En esta versión solo Ganadería tiene tablero técnico. Los indicadores de esta unidad siguen disponibles en el tablero central.', 'accion' => $puedeCentral ? ['texto' => 'Volver al tablero central', 'url' => $volver] : null]) ?>
    <?php elseif ($tablero === null): ?>
        <div class="alerta alerta-error" role="alert"><div class="alerta-cuerpo"><p class="alerta-titulo">Revisa el rango de fechas</p><p>No se calculan cifras con un rango inválido.</p></div></div>
    <?php else: ?>
        <?php
        $t = $tablero['transformacion'];
        $r = $tablero['rotacion'];
        $co = $tablero['costo'];
        $periodo = Formato::fecha($filtros->inicio()) . ' a ' . Formato::fecha($filtros->fin());
        ?>
        <?= $this->parcial('tableros/hato', ['unidad' => $unidad, 'filtros' => $filtros, 'practica' => $practica, 'hato' => $tablero['hato'], 'filtroSalud' => $filtroSalud, 'academica' => $academica]) ?>

        <div class="rejilla-bento">
            <section class="tarjeta tarjeta-bento bento-2x1" aria-labelledby="titulo-ind08">
                <h2 id="titulo-ind08" class="titulo-tarjeta">IND_08 · Eficiencia de transformación láctea</h2>
                <?php if ($t['lotes'] === []): ?>
                    <div class="estado-vacio">
                        <h3>Pendiente de trazabilidad</h3>
                        <p>No hay transformaciones con vínculos verificables en <?= $this->e($periodo) ?>: registra la transformación vinculando la salida de leche y el lote del derivado, y revisa que el derivado tenga factor de leche.</p>
                        <a class="boton" href="<?= $this->e($this->url('/produccion/transformaciones')) ?>">Ir a transformaciones</a>
                    </div>
                <?php else: ?>
                    <?php
                    $opciones = ['global' => 'Global'];
                    foreach ($t['derivados'] as $d) {
                        $opciones[(string) $d['id']] = $d['producto'];
                    }
                    $seleccionado = null;
                    foreach ($t['derivados'] as $d) {
                        if ((string) $d['id'] === $derivado) {
                            $seleccionado = $d;
                        }
                    }
                    ?>
                    <form class="barra-filtros" action="<?= $this->e($this->url($ruta)) ?>" method="get" hx-get="<?= $this->e($this->url($ruta)) ?>" hx-target="#panel-tablero" hx-swap="outerHTML" hx-push-url="true" hx-trigger="change">
                        <?php foreach ($filtros->aConsulta() + ['practica' => $practica ? '1' : '0', 'volver' => $volver] as $nombre => $valor): ?>
                            <?php if ($nombre !== 'unidad'): ?><input type="hidden" name="<?= $this->e($nombre) ?>" value="<?= $this->e($valor) ?>"><?php endif; ?>
                        <?php endforeach; ?>
                        <?= $this->parcial('parciales/campo', ['nombre' => 'derivado', 'etiqueta' => 'Ver', 'tipo' => 'select', 'opciones' => $opciones, 'valor' => $seleccionado === null ? 'global' : $derivado]) ?>
                    </form>
                    <div class="velocimetro-con-cifra">
                        <?php if ($seleccionado === null): ?>
                            <?php $enMeta = $t['global'] !== null && $t['meta'] !== null && $t['global'] + 1e-9 >= $t['meta']; ?>
                            <?= GraficoVelocimetroSvg::svg($t['global'], 0, 120, $t['meta'], $enMeta, 'Aprovechamiento global ' . Formato::porcentaje($t['global']) . ', meta ' . Formato::porcentaje($t['meta'], 0)) ?>
                            <div>
                                <p class="kpi-valor"><?= $this->e(Formato::porcentaje($t['global'])) ?></p>
                                <p class="kpi-nota">Litros equivalentes (<?= $this->e(Formato::cantidad($t['equivalentes'])) ?> L) / litros asignados (<?= $this->e(Formato::cantidad($t['litros'])) ?> L). Meta: <?= $this->e(Formato::porcentaje($t['meta'], 0)) ?> o más.</p>
                                <span class="insignia insignia-<?= $enMeta ? 'exito' : 'advertencia' ?>"><?= $enMeta ? 'Cumple la meta' : 'Bajo la meta' ?></span>
                            </div>
                        <?php else: ?>
                            <?php $maximo = $seleccionado['meta_max'] ?? $seleccionado['rendimiento']; ?>
                            <?= GraficoVelocimetroSvg::svg($seleccionado['rendimiento'], 0, max(1.0, $maximo * 2), $seleccionado['meta_max'], $seleccionado['en_meta'] !== false, $seleccionado['producto'] . ': ' . Formato::decimal($seleccionado['rendimiento']) . ' L por ' . $seleccionado['medida']) ?>
                            <div>
                                <p class="kpi-valor"><?= $this->e(Formato::decimal($seleccionado['rendimiento'])) ?> <span class="tamano-sm">L / <?= $this->e($seleccionado['medida']) ?></span></p>
                                <p class="kpi-nota">Litros asignados / cantidad de derivado en <?= (int) $seleccionado['lotes'] ?> lotes. Rango meta: <?= $this->e(Formato::decimal($seleccionado['meta_min']) . ' a ' . Formato::decimal($seleccionado['meta_max'])) ?> L; menos es mejor.</p>
                                <span class="insignia insignia-<?= $seleccionado['en_meta'] === false ? 'advertencia' : 'exito' ?>"><?= $seleccionado['en_meta'] === false ? 'Sobre el máximo del rango' : 'Dentro de la meta' ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <details class="tabla-equivalente">
                        <summary>Tendencia por lote</summary>
                        <div class="contenedor-tabla" tabindex="0" role="region" aria-label="Tendencia por lote">
                            <table class="tabla">
                                <thead><tr><th scope="col">Fecha</th><th scope="col">Lote</th><th scope="col">Derivado</th><th scope="col" class="numero">Litros</th><th scope="col" class="numero">Cantidad</th><th scope="col" class="numero">Rendimiento</th><th scope="col" class="numero">Aprovechamiento</th></tr></thead>
                                <tbody>
                                <?php foreach ($t['lotes'] as $l): ?>
                                    <tr><td><?= $this->e(Formato::fecha($l['fecha'])) ?></td><th scope="row"><?= $this->e($l['lote']) ?></th><td><?= $this->e($l['producto']) ?></td>
                                        <td class="numero cifras"><?= $this->e(Formato::cantidad($l['litros'])) ?> L</td><td class="numero cifras"><?= $this->e(Formato::cantidad($l['cantidad']) . ' ' . $l['medida']) ?></td>
                                        <td class="numero cifras"><?= $this->e(Formato::decimal($l['rendimiento'])) ?></td><td class="numero cifras"><?= $this->e(Formato::porcentaje($l['aprovechamiento'])) ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endif; ?>
            </section>

            <section class="tarjeta tarjeta-bento bento-2x1" aria-labelledby="titulo-ind17">
                <h2 id="titulo-ind17" class="titulo-tarjeta">IND_17 · Costo operativo por litro</h2>
                <?php if ($co['costo'] === null): ?>
                    <p class="kpi-valor">Pendiente</p>
                    <p class="kpi-nota">Hace falta gasto ejecutado de Mantenimiento o Alimentación y litros de leche recibidos en <?= $this->e($periodo) ?>.</p>
                <?php else: ?>
                    <p class="kpi-valor"><?= $this->e(Formato::moneda($co['costo'])) ?> <span class="tamano-sm">/ L</span></p>
                    <p><span class="insignia insignia-<?= $co['en_meta'] ? 'exito' : 'advertencia' ?>"><?= $co['en_meta'] ? 'Dentro de la meta' : 'Sobre la meta' ?></span></p>
                <?php endif; ?>
                <dl class="lista-datos">
                    <dt>Gasto ejecutado</dt><dd class="cifras"><?= $this->e(Formato::moneda($co['bruto'])) ?></dd>
                    <dt>− Componente académico</dt><dd class="cifras"><?= $this->e(Formato::moneda($co['academico'])) ?></dd>
                    <dt>Gasto operativo</dt><dd class="cifras"><?= $this->e(Formato::moneda($co['operativo'])) ?></dd>
                    <dt>Litros recibidos</dt><dd class="cifras"><?= $this->e(Formato::cantidad($co['litros'])) ?> L</dd>
                    <dt>Precio de referencia</dt><dd class="cifras"><?= $this->e(Formato::moneda($co['precio'])) ?> / L</dd>
                    <dt>Meta de costo</dt><dd class="cifras"><?= $this->e(Formato::moneda($co['meta'])) ?> / L o menos</dd>
                    <dt>Margen operativo</dt><dd class="cifras"><?= $this->e(Formato::moneda($co['margen'])) ?> / L</dd>
                    <?php if ($co['presupuestado'] !== null): ?>
                        <dt>Referencia: costo presupuestado</dt><dd class="cifras"><?= $this->e(Formato::moneda($co['presupuestado'])) ?> / L (asignación anual / litros)</dd>
                    <?php endif; ?>
                </dl>
                <p class="kpi-nota">Excluye Materiales de formación; los cupos de autoconsumo no se suman a la producción.</p>
            </section>
        </div>

        <section class="seccion tarjeta" aria-labelledby="titulo-ind12">
            <h2 id="titulo-ind12" class="titulo-tarjeta">IND_12 · Rotación de lotes terminados</h2>
            <p class="texto-secundario">Promedio de los lotes cerrados: <strong class="cifras"><?= $r['promedio'] === null ? Formato::SIN_DATO : $this->e(Formato::decimal($r['promedio'], 1)) . ' días' ?></strong> (<?= (int) $r['cerrados'] ?> cerrados, <?= (int) $r['abiertos'] ?> abiertos). Corte: <?= $this->e(Formato::fecha($r['corte'])) ?>. Referencia: <?= (int) $r['meta_frescos'] ?> días para frescos y <?= (int) $r['meta_animales'] ?> para animales.</p>
            <?php if ($r['lotes'] === []): ?>
                <p class="texto-secundario">No hay lotes recibidos en <?= $this->e($periodo) ?>.</p>
            <?php else: ?>
                <div class="contenedor-tabla" tabindex="0" role="region" aria-label="Rotación de lotes">
                    <table class="tabla">
                        <thead><tr><th scope="col">Lote</th><th scope="col">Producto</th><th scope="col">Recepción</th><th scope="col" class="numero">Cantidad</th><th scope="col" class="numero">Saldo</th><th scope="col">Estado</th><th scope="col" class="numero">Días</th></tr></thead>
                        <tbody>
                        <?php foreach ($r['lotes'] as $l): ?>
                            <tr>
                                <th scope="row"><?= $this->e($l['lote']) ?></th><td><?= $this->e($l['producto']) ?></td><td><?= $this->e(Formato::fecha($l['fecha'])) ?></td>
                                <td class="numero cifras"><?= $this->e(Formato::cantidad($l['cantidad']) . ' ' . $l['medida']) ?></td>
                                <td class="numero cifras"><?= $this->e(Formato::cantidad($l['saldo']) . ' ' . $l['medida']) ?></td>
                                <td><?= $l['cierre'] === null ? '<span class="insignia insignia-neutra">Abierto (antigüedad)</span>' : 'Cerrado el ' . $this->e(Formato::fecha($l['cierre'])) ?></td>
                                <td class="numero cifras"><?= $l['dias'] === null ? Formato::SIN_DATO : (int) $l['dias'] ?><?= $l['cierre'] !== null && $l['en_meta'] === false ? ' <span class="insignia insignia-advertencia">sobre ' . (int) $l['meta'] . '</span>' : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($puedeExportar): ?>
            <?= $this->parcial('tableros/exportar', ['tipo' => 'tablero_unidad', 'formatos' => ['xlsx', 'csv', 'pdf'], 'parametros' => ['vigencia' => (string) $filtros->vigencia] + array_intersect_key($filtros->aConsulta(), ['desde' => 1, 'hasta' => 1]) + ['unidad_tecnica' => (string) $unidad['id'], 'practica' => $practica ? '1' : '0']]) ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
