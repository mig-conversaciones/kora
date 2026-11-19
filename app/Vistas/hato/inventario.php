<?php
/**
 * Inventario del hato en un corte (RF-052, RF-053, RF-056). Variables: $unidades, $unidad, $corte, $cortes, $animales,
 * $totales, $lotes, $puedeEditar, $puedePractica, $valores, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$opcionesUnidades = [];
foreach ($unidades as $u) {
    $opcionesUnidades[(string) $u['id']] = $u['nombre'];
}
$opcionesCortes = [];
foreach ($cortes as $c) {
    $opcionesCortes[(string) $c['id']] = $this->fecha($c['fecha_corte']) . ((int) $c['vigente'] === 1 ? ' (vigente)' : '');
}
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d);
$semaforo = function (array $s, ?int $dias): string {
    $texto = $dias === null ? $s['texto'] : $this->e((string) $dias) . ' d · ' . $this->e($s['texto']);
    return '<span class="insignia insignia-' . $this->e($s['tono']) . ' semaforo">' . $this->parcial('parciales/icono', ['nombre' => $s['icono']]) . ' ' . $texto . '</span>';
};
$nuevo = $this->url('/hato/animales/nuevo') . '?unidad=' . (int) $unidad;
?>
<div id="panel-modulo">
    <?php if ($unidades === []): ?>
        <section class="tarjeta seccion">
            <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'No tienes unidades pecuarias asignadas', 'descripcion' => 'El hato se consulta por unidad pecuaria (por ejemplo, Ganadería). Pide a la administración que te asigne la unidad.', 'accion' => null]) ?>
        </section>
    <?php else: ?>
        <div class="barra-acciones">
            <?= $this->parcial('parciales/barra_filtros', ['ruta' => '/hato', 'campos' => $campo(['nombre' => 'unidad', 'etiqueta' => 'Unidad', 'tipo' => 'select', 'opciones' => $opcionesUnidades, 'valor' => (string) $unidad])
                . ($opcionesCortes === [] ? '' : $campo(['nombre' => 'corte', 'etiqueta' => 'Corte', 'tipo' => 'select', 'opciones' => $opcionesCortes, 'valor' => (string) ($corte['id'] ?? '')]))]) ?>
            <?php if ($puedeEditar && $corte !== null): ?>
                <a class="boton boton-primario" href="<?= $this->e($nuevo) ?>" hx-get="<?= $this->e($nuevo) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Registrar animal</a>
            <?php endif; ?>
        </div>
        <section class="tarjeta seccion" aria-labelledby="titulo-hato">
            <h2 id="titulo-hato" class="titulo-tarjeta"><?= $corte === null ? 'Sin cortes registrados' : 'Inventario biológico al ' . $this->e($this->fecha($corte['fecha_corte'])) ?></h2>
            <?php if ($corte !== null): ?>
                <p class="texto-secundario">Cada corte es una fotografía del hato en su fecha. Cambiar las fechas de un tablero no simula historia: se muestra este corte.</p>
                <ul class="resumen-lotes">
                    <?php foreach ($lotes as $clave => $nombre): ?>
                        <li><strong><?= (int) ($totales[$clave] ?? 0) ?></strong> <?= $this->e($nombre) ?></li>
                    <?php endforeach; ?>
                    <li><strong><?= (int) array_sum($totales) ?></strong> hembras en total</li>
                </ul>
            <?php endif; ?>
            <?php if ($corte === null || $animales === []): ?>
                <?= $this->parcial('parciales/estado_vacio', [
                    'titulo' => $corte === null ? 'Crea el primer corte del hato' : 'El corte no tiene animales',
                    'descripcion' => $corte === null ? 'Un corte fija la fecha del inventario; luego se registran los animales y su estado reproductivo.' : 'Registra las hembras de la unidad con su estado reproductivo.',
                    'accion' => null,
                ]) ?>
            <?php else: ?>
                <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-hato">
                    <table class="tabla">
                        <thead><tr><th scope="col">Animal</th><th scope="col">Lote</th><th scope="col">Estado</th><th scope="col">Último parto</th><th scope="col">IEP</th><th scope="col">Días abiertos</th><th scope="col">Última palpación</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
                        <tbody>
                            <?php foreach ($animales as $a): ?>
                                <?php $abrir = $this->url('/hato/animales/' . (int) $a['id']); ?>
                                <tr>
                                    <th scope="row"><?= $this->e($a['identificador']) ?></th>
                                    <td><?= $this->e($lotes[$a['lote_hato']] ?? $a['lote_hato']) ?></td>
                                    <td><?= $this->e($a['estado_reproductivo'] ?? '—') ?><?= $a['edad_gestacion'] !== null ? ' <span class="texto-secundario">(' . $this->e($a['edad_gestacion']) . ')</span>' : '' ?></td>
                                    <td class="numero"><?= $a['ultimo_parto'] === null ? '—' : $this->e($this->fecha($a['ultimo_parto'])) ?></td>
                                    <td><?= $semaforo($a['semaforo_iep'], $a['iep_dias'] === null ? null : (int) $a['iep_dias']) ?></td>
                                    <td><?= $semaforo($a['semaforo_abiertos'], $a['dias_abiertos'] === null ? null : (int) $a['dias_abiertos']) ?></td>
                                    <td class="numero"><?= $a['ultima_palpacion'] === null ? '—' : $this->e($this->fecha($a['ultima_palpacion'])) ?></td>
                                    <td><?php if ($puedeEditar || $puedePractica): ?><a class="boton boton-pequeno boton-texto" href="<?= $this->e($abrir) ?>" hx-get="<?= $this->e($abrir) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Abrir<span class="sr-only"> animal <?= $this->e($a['identificador']) ?></span></a><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="campo-ayuda">IEP: verde hasta 365 días, ámbar de 366 a 400 y rojo desde 401. Días abiertos: sin umbral por debajo de 91, verde de 91 a 120, ámbar de 121 a 135 y rojo desde 136.</p>
            <?php endif; ?>
        </section>
        <?php if ($puedeEditar): ?>
            <details class="tarjeta seccion"<?= isset($errores['fecha_corte']) ? ' open' : '' ?>>
                <summary>Nuevo corte del hato</summary>
                <form class="formulario" hx-post="<?= $this->e($this->url('/hato/cortes')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                    <input type="hidden" name="unidad_id" value="<?= (int) $unidad ?>">
                    <?= $this->parcial('parciales/campo', ['nombre' => 'fecha_corte', 'etiqueta' => 'Fecha del corte', 'tipo' => 'date', 'errores' => $errores, 'valor' => (string) ($valores['fecha_corte'] ?? ''), 'ayuda' => 'El nuevo corte copia el estado del vigente y recalcula los días abiertos con su fecha.', 'atributos' => 'required']) ?>
                    <button type="submit" class="boton">Crear corte</button>
                </form>
            </details>
        <?php endif; ?>
    <?php endif; ?>
</div>
