<?php
/**
 * IND_002 (RF-067, RF-068): bullet por proceso con la marca del 100 % y la tabla de trazabilidad. El selector Pesos /
 * Unidades recarga solo esta sección (fragmento HTMX). Nunca se suman cantidades de medidas distintas.
 * Variables: $procesos, $sinProceso, $modo ('pesos' o 'unidades'), $filtros.
 *
 * @var App\Nucleo\Vista $this
 */
use App\Controladores\ControladorTableros;
use App\Tableros\Formato;
use App\Tableros\Graficos\GraficoBulletSvg;

$base = $filtros->aConsulta();
$enlaceModo = fn (string $m): string => ControladorTableros::enlace('/tableros/central/procesos', $base + ['modo' => $m]);
?>
<section id="seccion-procesos" class="seccion tarjeta" aria-labelledby="titulo-ind002">
    <div class="encabezado-seccion">
        <h2 id="titulo-ind002" class="titulo-tarjeta">IND_002 · Ejecución de los procesos de autoconsumo</h2>
        <div class="selector-tema" role="group" aria-label="Medir el avance en">
            <?php foreach (['pesos' => 'Pesos COP', 'unidades' => 'Unidades físicas'] as $clave => $texto): ?>
                <a class="boton boton-pequeno" href="<?= $this->e($enlaceModo($clave)) ?>" hx-get="<?= $this->e($enlaceModo($clave)) ?>" hx-target="#seccion-procesos" hx-swap="outerHTML" aria-pressed="<?= $modo === $clave ? 'true' : 'false' ?>"><?= $this->e($texto) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if ((int) $sinProceso['salidas'] > 0): ?>
        <div class="alerta alerta-advertencia" role="status">
            <?= $this->parcial('parciales/icono', ['nombre' => 'alerta', 'clase' => 'alerta-icono']) ?>
            <div class="alerta-cuerpo"><p><?= (int) $sinProceso['salidas'] ?> entregas por autoconsumo (<?= $this->e(Formato::moneda($sinProceso['valor'])) ?>) no tienen proceso: cuentan en IND_001 pero no en ninguna meta. <a href="<?= $this->e($this->url('/calidad?tipo=autoconsumo_sin_proceso')) ?>">Vincularlas en la bandeja de calidad</a>.</p></div>
        </div>
    <?php endif; ?>
    <?php if ($procesos === []): ?>
        <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'Sin procesos en la vigencia', 'descripcion' => 'No hay cupos de autoconsumo para esta vigencia y estos filtros.', 'accion' => null]) ?>
    <?php else: ?>
        <ul class="lista-bullets">
            <?php foreach ($procesos as $p): ?>
                <?php $avance = $modo === 'unidades' ? $p['avance_fisico'] : $p['avance_monetario']; ?>
                <li>
                    <span class="bullet-nombre"><strong><?= $this->e($p['codigo']) ?></strong> · <?= $this->e($p['producto']) ?></span>
                    <?= GraficoBulletSvg::svg($avance, $p['codigo']) ?>
                    <span class="cifras"><?= $this->e(Formato::porcentaje($avance)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="contenedor-tabla">
            <table class="tabla">
                <caption class="sr-only">Trazabilidad de los procesos de autoconsumo</caption>
                <thead>
                <tr>
                    <th scope="col">Proceso</th><th scope="col">Origen → destino</th><th scope="col">Producto</th>
                    <?php if ($modo === 'unidades'): ?>
                        <th scope="col" class="numero">Cupo</th><th scope="col" class="numero">Entregado</th><th scope="col" class="numero">Saldo</th>
                    <?php else: ?>
                        <th scope="col" class="numero">Cupo (COP)</th><th scope="col" class="numero">Entregado (COP)</th><th scope="col" class="numero">Saldo (COP)</th>
                    <?php endif; ?>
                    <th scope="col" class="numero">Avance</th><th scope="col" class="numero">Lotes vinculados</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($procesos as $p): ?>
                    <tr>
                        <th scope="row"><?= $this->e($p['codigo']) ?></th>
                        <td><?= $this->e($p['unidad_nombre']) ?> → <?= $this->e($p['destino']) ?></td>
                        <td><?= $this->e($p['producto']) ?><?= (int) $p['incompatibles'] > 0 ? ' <span class="insignia insignia-advertencia">' . (int) $p['incompatibles'] . ' con otra medida</span>' : '' ?></td>
                        <?php if ($modo === 'unidades'): ?>
                            <td class="numero cifras"><?= $this->e(Formato::cantidad($p['cupo_cantidad']) . ' ' . $p['medida']) ?></td>
                            <td class="numero cifras"><?= $this->e(Formato::cantidad($p['entregado']) . ' ' . $p['medida']) ?></td>
                            <td class="numero cifras"><?= $this->e(Formato::cantidad($p['saldo_cantidad']) . ' ' . $p['medida']) ?></td>
                            <td class="numero cifras"><?= $this->e(Formato::porcentaje($p['avance_fisico'])) ?></td>
                        <?php else: ?>
                            <td class="numero cifras"><?= $this->e(Formato::moneda($p['cupo_valor'])) ?></td>
                            <td class="numero cifras"><?= $this->e(Formato::moneda($p['valor'])) ?></td>
                            <td class="numero cifras"><?= $this->e(Formato::moneda($p['saldo_valor'])) ?></td>
                            <td class="numero cifras"><?= $this->e(Formato::porcentaje($p['avance_monetario'])) ?></td>
                        <?php endif; ?>
                        <td class="numero cifras"><?= (int) $p['lotes'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="8">No se suman cantidades de medidas distintas: cada proceso se mide en la suya.</td></tr></tfoot>
            </table>
        </div>
    <?php endif; ?>
</section>
