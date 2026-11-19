<?php
/**
 * Carga inicial guiada del piloto (RF-094, RDL-011). Variables: $carga, $pasos, $resultado, $historial,
 * $puedeGestionar, $puedeFirmar, $vigenciaSugerida, $errores, $valores.
 *
 * @var App\Nucleo\Vista $this
 */
$errores ??= [];
$valores ??= [];
$estadosCarga = [
    'en_curso' => ['En curso', 'info'], 'con_diferencias' => ['Con diferencias', 'advertencia'], 'verificada' => ['Verificada', 'exito'],
    'firmada' => ['Firmada', 'exito'], 'anulada' => ['Anulada', 'neutra'],
];
$porFuente = [];
foreach (($resultado['fuentes'] ?? []) as $f) {
    $porFuente[(string) $f['fuente']] = $f;
}
$ulid = $carga === null ? '' : (string) $carga['ulid'];
?>
<div id="panel-modulo">
    <?php if ($carga === null): ?>
        <section class="tarjeta seccion" aria-labelledby="titulo-iniciar">
            <h2 id="titulo-iniciar" class="titulo-tarjeta">Iniciar la carga inicial</h2>
            <p>La producción empieza con los libros de Excel actualizados del piloto. Los datos y respaldos del prototipo no se importan (RDL-011).</p>
            <ol class="pasos">
                <li><p>Al iniciar se toma una copia cifrada de la base.</p></li>
                <li><p>Importa cada fuente en el orden indicado, con el flujo normal: revisar, validar y aplicar.</p></li>
                <li><p>Verifica: el sistema compara las filas y las sumas del archivo con lo que quedó en la base, en total y por unidad.</p></li>
                <li><p>Sin diferencias, la coordinación administrativa o el Grupo de Recaudo firma el acta.</p></li>
            </ol>
            <?php if ($puedeGestionar): ?>
                <form class="formulario" hx-post="<?= $this->e($this->url('/importaciones/carga-inicial')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML"
                      data-confirmar="Se tomará una copia de la base y empezará la carga inicial del centro." data-confirmar-titulo="Iniciar la carga inicial" data-confirmar-accion="Iniciar" novalidate>
                    <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
                    <?= $this->parcial('parciales/campo', ['nombre' => 'vigencia', 'etiqueta' => 'Vigencia del presupuesto y los cupos', 'tipo' => 'number', 'valor' => $valores['vigencia'] ?? (string) $vigenciaSugerida, 'errores' => $errores, 'atributos' => 'min="2020" max="2100" required inputmode="numeric"']) ?>
                    <button type="submit" class="boton boton-primario">Iniciar la carga</button>
                </form>
            <?php else: ?>
                <p class="texto-secundario">La conduce la coordinación administrativa o el administrador funcional.</p>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <?php [$textoEstado, $tonoEstado] = $estadosCarga[$carga['estado']] ?? [$carga['estado'], 'neutra']; ?>
        <section class="tarjeta seccion" aria-labelledby="titulo-carga">
            <h2 id="titulo-carga" class="titulo-tarjeta">Carga inicial, vigencia <?= (int) $carga['vigencia'] ?> <span class="insignia insignia-<?= $this->e($tonoEstado) ?>"><?= $this->e($textoEstado) ?></span></h2>
            <dl class="lista-datos">
                <dt>Iniciada</dt><dd><?= $this->e($this->fecha((string) $carga['iniciada_en'])) ?> por <?= $this->e((string) $carga['iniciada_por_nombre']) ?></dd>
                <dt>Copia previa</dt><dd><?= $carga['respaldo_previo'] === null ? 'No configurada en este entorno' : $this->e((string) $carga['respaldo_previo']) ?></dd>
                <?php if ($carga['verificada_en'] !== null): ?><dt>Última verificación</dt><dd><?= $this->e($this->fecha((string) $carga['verificada_en'])) ?>: <?= (int) ($resultado['diferencias'] ?? 0) ?> diferencia(s)</dd><?php endif; ?>
                <?php if ($carga['firmada_en'] !== null): ?><dt>Firmada</dt><dd><?= $this->e($this->fecha((string) $carga['firmada_en'])) ?> por <?= $this->e((string) $carga['firmada_por_nombre']) ?></dd><?php endif; ?>
            </dl>
            <?= $this->parcial('parciales/resumen_errores', ['errores' => array_intersect_key($errores, ['carga' => true])]) ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-carga">
                <table class="tabla">
                    <caption class="sr-only">Fuentes de la carga inicial en orden</caption>
                    <thead><tr><th scope="col">Paso</th><th scope="col">Fuente</th><th scope="col">De dónde sale</th><th scope="col">Lote aplicado</th><th scope="col">Cifras</th></tr></thead>
                    <tbody>
                        <?php foreach ($pasos as $i => $paso): ?>
                            <?php $f = $porFuente[$paso['clave']] ?? null; ?>
                            <tr>
                                <td class="numero"><?= $i + 1 ?></td>
                                <th scope="row"><?= $this->e((string) $paso['nombre']) ?><?= $paso['obligatoria'] ? '' : ' <span class="texto-secundario">(opcional)</span>' ?></th>
                                <td><?= $this->e((string) $paso['libro']) ?></td>
                                <td>
                                    <?php if ($paso['aplicado'] !== null): ?>
                                        <a href="<?= $this->e($this->url('/importaciones/' . $paso['aplicado']['ulid'])) ?>"><?= $this->e(mb_strimwidth((string) $paso['aplicado']['nombre_archivo'], 0, 32, '…')) ?></a>
                                        <?php if ($paso['pendientes'] > 0): ?><br><span class="texto-advertencia"><?= (int) $paso['pendientes'] ?> lote(s) más reciente(s) sin aplicar</span><?php endif; ?>
                                    <?php elseif ($paso['ultimo'] !== null): ?>
                                        <a href="<?= $this->e($this->url('/importaciones/' . $paso['ultimo']['ulid'])) ?>">Lote sin aplicar</a>
                                    <?php else: ?>
                                        <span class="texto-secundario">Sin cargar</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($f === null): ?><span class="texto-secundario">Sin verificar</span>
                                    <?php elseif (!empty($f['omitida'])): ?><span class="insignia insignia-neutra">Omitida</span>
                                    <?php elseif ($f['diferencias'] === []): ?><span class="insignia insignia-exito">Coinciden</span>
                                    <?php else: ?><span class="insignia insignia-advertencia"><?= count($f['diferencias']) ?> diferencia(s)</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="grupo-botones">
                <?php if ($puedeGestionar && in_array($carga['estado'], ['en_curso', 'con_diferencias', 'verificada'], true)): ?>
                    <a class="boton" href="<?= $this->e($this->url('/importaciones')) ?>">Ir a importar</a>
                    <form hx-post="<?= $this->e($this->url('/importaciones/carga-inicial/' . $ulid . '/verificar')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                        <button type="submit" class="boton boton-primario">Verificar las cifras de control</button>
                    </form>
                <?php endif; ?>
                <?php if ($carga['resultado'] !== null): ?>
                    <a class="boton" href="<?= $this->e($this->url('/importaciones/carga-inicial/' . $ulid . '/acta')) ?>">Ver el acta</a>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($carga['estado'] === 'verificada' && $puedeFirmar): ?>
            <section class="tarjeta seccion" aria-labelledby="titulo-firma">
                <h2 id="titulo-firma" class="titulo-tarjeta">Firmar el acta</h2>
                <p>Antes de firmar, el sistema vuelve a calcular las cifras: si algo cambió desde la verificación, no se firma.</p>
                <form class="formulario" hx-post="<?= $this->e($this->url('/importaciones/carga-inicial/' . $ulid . '/firmar')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML"
                      data-confirmar="La firma queda registrada con tu nombre y la huella del acta." data-confirmar-titulo="Firmar el acta de la carga inicial" data-confirmar-accion="Firmar" novalidate>
                    <?= $this->parcial('parciales/resumen_errores', ['errores' => array_intersect_key($errores, ['declaracion' => true, 'observaciones' => true])]) ?>
                    <?= $this->parcial('parciales/campo', ['nombre' => 'declaracion', 'etiqueta' => 'Revisé las cifras del acta con los archivos fuente y son correctas', 'tipo' => 'checkbox', 'valor' => $valores['declaracion'] ?? '', 'errores' => $errores]) ?>
                    <?= $this->parcial('parciales/campo', ['nombre' => 'observaciones', 'etiqueta' => 'Observaciones (opcional)', 'tipo' => 'textarea', 'valor' => $valores['observaciones'] ?? '', 'errores' => $errores, 'atributos' => 'maxlength="1000"']) ?>
                    <button type="submit" class="boton boton-primario">Firmar el acta</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($puedeGestionar && $carga['estado'] !== 'firmada'): ?>
            <details class="zona-peligro"<?= isset($errores['motivo']) ? ' open' : '' ?>>
                <summary>Anular la carga</summary>
                <p>Los lotes aplicados no se revierten al anular: revísalos en Importaciones. Después podrás empezar otra carga.</p>
                <form class="formulario" hx-post="<?= $this->e($this->url('/importaciones/carga-inicial/' . $ulid . '/anular')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML"
                      data-confirmar="La carga quedará anulada con su motivo." data-confirmar-titulo="Anular la carga inicial" data-confirmar-accion="Anular" data-peligro novalidate>
                    <?= $this->parcial('parciales/campo', ['nombre' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'textarea', 'valor' => $valores['motivo'] ?? '', 'errores' => $errores, 'atributos' => 'maxlength="500" required']) ?>
                    <button type="submit" class="boton boton-peligro">Anular</button>
                </form>
            </details>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (count($historial) > ($carga === null ? 0 : 1)): ?>
        <section class="tarjeta seccion" aria-labelledby="titulo-historial">
            <h2 id="titulo-historial" class="titulo-tarjeta">Cargas anteriores</h2>
            <ul class="lista-enlaces">
                <?php foreach ($historial as $h): ?>
                    <?php if ($carga !== null && $h['ulid'] === $carga['ulid']) { continue; } ?>
                    <li>Vigencia <?= (int) $h['vigencia'] ?>, iniciada el <?= $this->e($this->fecha((string) $h['iniciada_en'])) ?> por <?= $this->e((string) $h['iniciada_por_nombre']) ?>:
                        <?= $this->e($estadosCarga[$h['estado']][0] ?? (string) $h['estado']) ?><?= $h['anulada_motivo'] !== null ? ' (' . $this->e((string) $h['anulada_motivo']) . ')' : '' ?>.
                        <a href="<?= $this->e($this->url('/importaciones/carga-inicial/' . $h['ulid'] . '/acta')) ?>">Acta</a></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</div>
