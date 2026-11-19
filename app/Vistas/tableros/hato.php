<?php
/**
 * IND_003 y salud reproductiva (RF-073, RF-074, RF-078). El interruptor de práctica y los filtros rápidos recargan
 * solo esta sección. Para la Coordinación académica la tabla y la disponibilidad didáctica van antes que el gráfico.
 * Variables: $unidad, $filtros, $practica, $hato, $filtroSalud, $academica.
 *
 * @var App\Nucleo\Vista $this
 */
use App\Controladores\ControladorTableros;
use App\Tableros\Formato;
use App\Tableros\Graficos\GraficoAnilloSvg;

$ruta = '/tableros/unidad/' . $unidad['codigo'] . '/hato';
$base = ['vigencia' => (string) $filtros->vigencia] + array_intersect_key($filtros->aConsulta(), ['desde' => 1, 'hasta' => 1]);
$enlace = fn (array $extra): string => ControladorTableros::enlace($ruta, $base + ['practica' => $practica ? '1' : '0', 'salud' => $filtroSalud] + $extra);
$filtrosRapidos = ['poblacion' => 'Población seleccionada', 'alertas' => 'Con alertas', 'prenadas' => 'Preñadas', 'practica' => 'Práctica académica'];
$animales = array_values(array_filter($hato['animales'], static fn (array $a): bool => match ($filtroSalud) {
    'alertas' => $a['operativa'] && $a['alerta'],
    'prenadas' => $a['operativa'] && $a['prenada'],
    'practica' => $a['lote_hato'] === 'PRACTICA',
    default => $a['operativa'],
}));
$semaforo = fn (array $s, ?int $dias): string => '<span class="insignia insignia-' . $this->e($s['tono']) . ' semaforo">'
    . $this->parcial('parciales/icono', ['nombre' => $s['icono']]) . ' ' . ($dias === null ? '' : (int) $dias . ' d · ') . $this->e($s['texto']) . '</span>';
$lotes = ['PRODUCCION' => 'Producción', 'HORRA' => 'Horra', 'PRACTICA' => 'Práctica'];

ob_start(); ?>
    <div class="rejilla-dos">
        <article class="tarjeta tarjeta-bento" aria-labelledby="titulo-ind003">
            <h3 id="titulo-ind003" class="titulo-tarjeta">IND_003 · Vacas en ordeño</h3>
            <p class="kpi-valor"><?= $this->e(Formato::porcentaje($hato['tasa'])) ?></p>
            <p class="kpi-nota"><?= (int) $hato['produccion'] ?> en producción de <?= (int) $hato['poblacion'] ?> (población operativa<?= $practica ? ', con el lote de práctica' : ', sin el lote de práctica' ?>). Meta: <?= $this->e(Formato::porcentaje($hato['meta'], 0)) ?>.</p>
            <p><span class="insignia insignia-<?= $this->e($hato['semaforo']['tono']) ?>"><?= $this->e($hato['semaforo']['texto']) ?></span></p>
            <dl class="lista-datos">
                <dt>Tasa de preñez</dt><dd class="cifras"><?= $this->e(Formato::porcentaje($hato['prenez'])) ?> (<?= (int) $hato['prenadas'] ?> preñadas)</dd>
                <dt>Inventario completo</dt><dd class="cifras"><?= (int) $hato['inventario'] ?> hembras · <?= $this->e(Formato::porcentaje($hato['tasa_completa'])) ?> en producción</dd>
            </dl>
        </article>
        <article class="tarjeta tarjeta-bento" aria-labelledby="titulo-anillo">
            <h3 id="titulo-anillo" class="titulo-tarjeta">Inventario completo del corte</h3>
            <div class="anillo-con-leyenda">
                <?= GraficoAnilloSvg::svg([
                    ['clase' => 'anillo-produccion', 'etiqueta' => 'Producción', 'valor' => (int) $hato['produccion']],
                    ['clase' => 'anillo-horra', 'etiqueta' => 'Horra', 'valor' => (int) $hato['horra']],
                    ['clase' => 'anillo-practica', 'etiqueta' => 'Práctica', 'valor' => (int) $hato['practica']],
                ], (string) $hato['inventario'], sprintf('Inventario de %d hembras: %d en producción, %d horras y %d de práctica.', $hato['inventario'], $hato['produccion'], $hato['horra'], $hato['practica'])) ?>
                <ul class="grafico-leyenda grafico-leyenda-vertical">
                    <li><span class="leyenda-marca leyenda-produccion" aria-hidden="true"></span>Producción: <?= (int) $hato['produccion'] ?></li>
                    <li><span class="leyenda-marca leyenda-horra" aria-hidden="true"></span>Horra: <?= (int) $hato['horra'] ?></li>
                    <li><span class="leyenda-marca leyenda-practica leyenda-patron" aria-hidden="true"></span>Práctica: <?= (int) $hato['practica'] ?></li>
                </ul>
            </div>
        </article>
    </div>
<?php $graficos = (string) ob_get_clean();

ob_start(); ?>
    <article class="tarjeta tarjeta-bento" aria-labelledby="titulo-disponibilidad">
        <h3 id="titulo-disponibilidad" class="titulo-tarjeta">Disponibilidad didáctica</h3>
        <p class="kpi-valor"><?= (int) $hato['practica'] ?> <span class="tamano-sm texto-secundario">animales</span></p>
        <p class="kpi-nota"><?= $this->e(Formato::porcentaje($hato['disponibilidad'])) ?> del inventario completo está en el lote de práctica académica.</p>
    </article>
    <article class="seccion tarjeta" aria-labelledby="titulo-salud">
        <h3 id="titulo-salud" class="titulo-tarjeta">Panel de salud reproductiva</h3>
        <nav class="pestanas" aria-label="Filtros rápidos de la tabla">
            <?php foreach ($filtrosRapidos as $clave => $texto): ?>
                <a class="pestana" href="<?= $this->e($enlace(['salud' => $clave])) ?>" hx-get="<?= $this->e($enlace(['salud' => $clave])) ?>" hx-target="#seccion-hato" hx-swap="outerHTML"<?= $filtroSalud === $clave ? ' aria-current="true"' : '' ?>><?= $this->e($texto) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($animales === []): ?>
            <p class="texto-secundario">Ningún animal cumple este filtro en el corte vigente.</p>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-label="Salud reproductiva del hato">
                <table class="tabla">
                    <caption class="sr-only">Salud reproductiva: <?= $this->e($filtrosRapidos[$filtroSalud]) ?></caption>
                    <thead><tr><th scope="col">Animal</th><th scope="col">Lote</th><th scope="col">Estado reproductivo</th><th scope="col">Último parto</th><th scope="col">IEP</th><th scope="col">Días abiertos</th><th scope="col"><span class="sr-only">Detalle</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($animales as $a): ?>
                        <tr>
                            <th scope="row"><?= $this->e($a['identificador']) ?></th>
                            <td><?= $this->e($lotes[$a['lote_hato']] ?? $a['lote_hato']) ?></td>
                            <td><?= $this->e($a['estado_reproductivo'] ?? 'Sin dato') ?></td>
                            <td><?= $this->e(Formato::fecha($a['ultimo_parto'])) ?></td>
                            <td><?= $semaforo($a['semaforo_iep'], $a['iep_dias']) ?></td>
                            <td><?= $semaforo($a['semaforo_abiertos'], $a['dias_abiertos']) ?></td>
                            <td><a href="<?= $this->e($this->url('/hato/animales/' . (int) $a['id'])) ?>">Ver detalle</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
<?php $tabla = (string) ob_get_clean(); ?>
<section id="seccion-hato" class="seccion-sin-tarjeta" aria-labelledby="titulo-seccion-hato">
    <div class="encabezado-seccion">
        <h2 id="titulo-seccion-hato" class="titulo-tarjeta">Hato · corte del <?= $this->e(Formato::fecha($hato['corte'])) ?></h2>
        <form class="barra-filtros" action="<?= $this->e($this->url($ruta)) ?>" method="get" hx-get="<?= $this->e($this->url($ruta)) ?>" hx-target="#seccion-hato" hx-swap="outerHTML" hx-trigger="change">
            <?php foreach ($base + ['salud' => $filtroSalud] as $nombre => $valor): ?>
                <input type="hidden" name="<?= $this->e($nombre) ?>" value="<?= $this->e($valor) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="practica" value="0">
            <label class="casilla interruptor" for="campo-practica">
                <input id="campo-practica" type="checkbox" role="switch" name="practica" value="1"<?= $practica ? ' checked' : '' ?>>
                Incluir lote de práctica académica
            </label>
            <noscript><button type="submit" class="boton boton-pequeno">Aplicar</button></noscript>
        </form>
    </div>
    <?php if ($hato['corte'] === null): ?>
        <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'Sin corte del hato', 'descripcion' => 'Esta unidad aún no tiene un corte vigente. Carga el estado reproductivo o crea un corte.', 'accion' => ['texto' => 'Ir al hato', 'url' => '/hato']]) ?>
    <?php elseif ($academica): ?>
        <?= $tabla . $graficos ?>
    <?php else: ?>
        <?= $graficos . $tabla ?>
    <?php endif; ?>
</section>
