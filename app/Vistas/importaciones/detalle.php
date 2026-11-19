<?php
/**
 * Vista previa y detalle de un lote (RF-087 a RF-091, RF-094). Variables: $lote, $fuente, $resumen, $cifras,
 * $observaciones, $bloqueos, $puedeOperar, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$errores ??= [];
$conteo = $resumen['conteo'] ?? [];
$ulid = (string) $lote['ulid'];
$enCurso = in_array($lote['estado'], ['validando', 'aplicando', 'revirtiendo'], true);
$aplicable = in_array($lote['estado'], ['validado', 'con_errores'], true) && (int) $lote['filas_validas'] > 0;
$verificada = $cifras['verificada'] ?? null;
?>
<div id="panel-modulo">
    <section class="tarjeta seccion" aria-labelledby="titulo-lote">
        <h2 id="titulo-lote" class="titulo-tarjeta"><?= $this->e($fuente->nombre()) ?></h2>
        <?php if ($enCurso): ?>
            <div hx-get="<?= $this->e($this->url('/importaciones/' . $ulid)) ?>" hx-trigger="every 3s" hx-target="#panel-modulo" hx-swap="outerHTML">
                <span class="insignia insignia-info">En curso…</span>
                <p class="texto-secundario">El trabajo corre en segundo plano; esta página se actualiza sola y también recibirás una notificación.</p>
            </div>
        <?php else: ?>
            <p><?= $this->parcial('importaciones/estado', ['lote' => $lote, 'progreso' => null]) ?></p>
        <?php endif; ?>
        <dl class="lista-datos">
            <div><dt>Archivo</dt><dd><?= $this->e($lote['nombre_archivo']) ?> · hoja «<?= $this->e($resumen['hoja'] ?? '') ?>»<?php if ($puedeOperar): ?> · <a href="<?= $this->e($this->url('/importaciones/' . $ulid . '/original')) ?>">descargar original</a><?php endif; ?></dd></div>
            <div><dt>Huella del archivo</dt><dd><code><?= $this->e(substr((string) $lote['hash_archivo'], 0, 16)) ?>…</code></dd></div>
            <?php if (isset($resumen['error'])): ?><div><dt>Problema</dt><dd class="texto-advertencia"><?= $this->e($resumen['error']) ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($conteo !== []): ?>
            <ul class="resumen-lotes" aria-label="Resumen de la validación">
                <li><strong><?= (int) ($conteo['crear'] ?? 0) ?></strong> nuevas</li>
                <li><strong><?= (int) ($conteo['actualizar'] ?? 0) ?></strong> actualizadas</li>
                <li><strong><?= (int) ($conteo['sin_cambios'] ?? 0) ?></strong> sin cambios</li>
                <li><strong><?= (int) ($conteo['error'] ?? 0) ?></strong> con error</li>
                <li><strong><?= (int) ($conteo['advertencias'] ?? 0) ?></strong> con advertencias</li>
            </ul>
        <?php endif; ?>
        <?php if ($lote['estado'] === 'aplicado' && $verificada !== null): ?>
            <p class="<?= $verificada ? 'texto-exito' : 'texto-advertencia' ?>"><?= $verificada ? 'Cifras de control verificadas: coinciden con el archivo.' : 'Las cifras de control no coinciden con el archivo: revisa el acta de carga.' ?></p>
        <?php endif; ?>
        <?php if ($puedeOperar && $aplicable): ?>
            <form class="formulario" hx-post="<?= $this->e($this->url('/importaciones/' . $ulid . '/aplicar')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML"
                  data-confirmar="Se aplicarán <?= (int) $lote['filas_validas'] ?> filas válidas, con su procedencia. Podrás revertir el lote mientras no tenga registros dependientes." data-confirmar-titulo="Aplicar la importación" data-confirmar-accion="Aplicar" novalidate>
                <?= $this->parcial('parciales/resumen_errores', ['errores' => array_intersect_key($errores, ['solo_validas' => true])]) ?>
                <?php if ($lote['estado'] === 'con_errores'): ?>
                    <?= $this->parcial('parciales/campo', ['nombre' => 'solo_validas', 'etiqueta' => 'Aplicar solo las ' . (int) $lote['filas_validas'] . ' filas válidas; las ' . (int) $lote['filas_error'] . ' con error se corrigen y se cargan después', 'tipo' => 'checkbox', 'errores' => $errores]) ?>
                <?php endif; ?>
                <div class="grupo-botones">
                    <button type="submit" class="boton boton-primario">Aplicar</button>
                    <?php if ((int) $lote['filas_error'] > 0): ?><a class="boton" href="<?= $this->e($this->url('/importaciones/' . $ulid . '/errores')) ?>">Descargar el reporte de errores</a><?php endif; ?>
                </div>
            </form>
        <?php elseif ((int) $lote['filas_error'] > 0): ?>
            <p><a class="boton" href="<?= $this->e($this->url('/importaciones/' . $ulid . '/errores')) ?>">Descargar el reporte de errores</a></p>
        <?php endif; ?>
    </section>

    <?php if (($resumen['muestra'] ?? []) !== []): ?>
        <section class="tarjeta seccion" aria-labelledby="titulo-diferencias">
            <h2 id="titulo-diferencias" class="titulo-tarjeta">Cambios sobre registros existentes (muestra)</h2>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-diferencias">
                <table class="tabla">
                    <thead><tr><th scope="col">Fila</th><th scope="col">Campo</th><th scope="col">Valor actual</th><th scope="col">Valor nuevo</th></tr></thead>
                    <tbody>
                        <?php foreach ($resumen['muestra'] as $m): ?>
                            <?php foreach ($m['diferencias'] as $campo => $d): ?>
                                <tr><td class="numero"><?= (int) $m['fila'] ?></td><td><?= $this->e($campo) ?></td><td><?= $this->e((string) ($d['actual'] ?? '—')) ?></td><td><?= $this->e((string) ($d['nuevo'] ?? '—')) ?></td></tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($observaciones !== []): ?>
        <section class="tarjeta seccion" aria-labelledby="titulo-observaciones">
            <h2 id="titulo-observaciones" class="titulo-tarjeta">Errores y advertencias por fila</h2>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-observaciones">
                <table class="tabla">
                    <thead><tr><th scope="col">Fila</th><th scope="col">Tipo</th><th scope="col">Columna</th><th scope="col">Mensaje</th></tr></thead>
                    <tbody>
                        <?php foreach ($observaciones as $o): ?>
                            <?php $detalle = json_decode((string) $o['errores'], true) ?: []; ?>
                            <?php foreach ($detalle['errores'] ?? [] as $e): ?>
                                <tr><td class="numero"><?= (int) $o['fila'] ?></td><td><span class="insignia insignia-error">Error</span></td><td><?= $this->e($e['columna']) ?></td><td><?= $this->e($e['mensaje']) ?></td></tr>
                            <?php endforeach; ?>
                            <?php foreach ($detalle['advertencias'] ?? [] as $a): ?>
                                <tr><td class="numero"><?= (int) $o['fila'] ?></td><td><span class="insignia insignia-advertencia">Advertencia</span></td><td></td><td><?= $this->e($a) ?></td></tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="campo-ayuda">Se muestran hasta 100 filas; el reporte descargable tiene todas las filas con error.</p>
        </section>
    <?php endif; ?>

    <?php if ($puedeOperar && $lote['estado'] === 'aplicado'): ?>
        <details class="zona-peligro"<?= isset($errores['motivo']) ? ' open' : '' ?>>
            <summary>Revertir el lote</summary>
            <?php if ($bloqueos !== []): ?>
                <p class="texto-advertencia">No se puede revertir mientras existan registros que dependen del lote:</p>
                <ul><?php foreach ($bloqueos as $b): ?><li><?= $this->e($b) ?></li><?php endforeach; ?></ul>
            <?php else: ?>
                <form class="formulario" hx-post="<?= $this->e($this->url('/importaciones/' . $ulid . '/revertir')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML"
                      data-confirmar="Se archivará lo que el lote creó y lo actualizado recuperará sus valores anteriores." data-confirmar-titulo="Revertir la importación" data-confirmar-accion="Revertir" data-peligro novalidate>
                    <?= $this->parcial('parciales/campo', ['nombre' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'textarea', 'errores' => $errores, 'atributos' => 'maxlength="500" required']) ?>
                    <button type="submit" class="boton boton-peligro">Revertir</button>
                </form>
            <?php endif; ?>
        </details>
    <?php endif; ?>
</div>
