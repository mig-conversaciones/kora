<?php
/**
 * Acta de la carga inicial (RF-094, RDL-011): fuentes, archivos con su huella, cifras de control del archivo frente a
 * la base, diferencias, filas rechazadas (sin sus valores) y firma. Se imprime desde el navegador. Variables: $carga,
 * $resultado.
 *
 * @var App\Nucleo\Vista $this
 */
$estados = ['en_curso' => 'En curso, sin verificar', 'con_diferencias' => 'Con diferencias: no verificada', 'verificada' => 'Verificada, sin firmar', 'firmada' => 'Verificada y firmada', 'anulada' => 'Anulada'];
$fuentes = $resultado['fuentes'] ?? [];
?>
<div id="panel-modulo">
    <article class="tarjeta seccion acta" aria-labelledby="titulo-acta">
        <h2 id="titulo-acta" class="titulo-tarjeta">Acta de la carga inicial del piloto — vigencia <?= (int) $carga['vigencia'] ?></h2>
        <dl class="lista-datos">
            <dt>Estado</dt><dd><strong><?= $this->e($estados[$carga['estado']] ?? (string) $carga['estado']) ?></strong></dd>
            <dt>Iniciada</dt><dd><?= $this->e($this->fecha((string) $carga['iniciada_en'])) ?> por <?= $this->e((string) $carga['iniciada_por_nombre']) ?></dd>
            <dt>Copia previa</dt><dd><?= $carga['respaldo_previo'] === null ? 'No configurada en este entorno' : $this->e((string) $carga['respaldo_previo']) ?></dd>
            <dt>Verificación</dt><dd><?= $carga['verificada_en'] === null ? 'Sin verificar' : $this->e($this->fecha((string) $carga['verificada_en'])) . ': ' . (int) ($resultado['diferencias'] ?? 0) . ' diferencia(s)' ?></dd>
            <dt>Huella del resultado</dt><dd><code><?= $this->e((string) ($carga['huella'] ?? '—')) ?></code></dd>
            <?php if ($carga['firmada_en'] !== null): ?>
                <dt>Firma</dt><dd><?= $this->e((string) $carga['firmada_por_nombre']) ?>, <?= $this->e($this->fecha((string) $carga['firmada_en'])) ?></dd>
                <?php if ($carga['observaciones'] !== null): ?><dt>Observaciones</dt><dd><?= $this->e((string) $carga['observaciones']) ?></dd><?php endif; ?>
            <?php endif; ?>
            <?php if ($carga['anulada_motivo'] !== null): ?><dt>Motivo de anulación</dt><dd><?= $this->e((string) $carga['anulada_motivo']) ?></dd><?php endif; ?>
        </dl>

        <?php if ($fuentes === []): ?>
            <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'Aún no se ha verificado', 'descripcion' => 'El acta se llena al verificar las cifras de control.', 'accion' => null]) ?>
        <?php else: ?>
            <h3>Cifras de control por fuente</h3>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-label="Cifras de control por fuente">
                <table class="tabla">
                    <thead><tr><th scope="col">Fuente</th><th scope="col">Archivo</th><th scope="col" class="numero">Filas archivo</th><th scope="col" class="numero">Filas base</th><th scope="col" class="numero">Suma archivo</th><th scope="col" class="numero">Suma base</th><th scope="col">Resultado</th></tr></thead>
                    <tbody>
                        <?php foreach ($fuentes as $f): ?>
                            <tr>
                                <th scope="row"><?= $this->e((string) $f['nombre']) ?></th>
                                <?php if ($f['lote'] === null): ?>
                                    <td colspan="5" class="texto-secundario"><?= !empty($f['omitida']) ? 'Fuente opcional: no se cargó' : 'Sin lote aplicado' ?></td>
                                <?php else: ?>
                                    <td><?= $this->e(mb_strimwidth((string) $f['lote']['archivo'], 0, 40, '…')) ?><?php if (!empty($f['lote']['hash'])): ?><br><code class="texto-secundario" title="SHA-256 del archivo"><?= $this->e(substr((string) $f['lote']['hash'], 0, 16)) ?>…</code><?php endif; ?></td>
                                    <td class="numero"><?= (int) $f['archivo']['filas'] ?></td>
                                    <td class="numero"><?= (int) $f['base']['filas'] ?></td>
                                    <td class="numero"><?= $this->e($this->cantidad((string) $f['archivo']['suma'])) ?></td>
                                    <td class="numero"><?= $this->e($this->cantidad((string) $f['base']['suma'])) ?></td>
                                <?php endif; ?>
                                <td><?= $f['diferencias'] === [] ? (!empty($f['omitida']) ? 'Omitida' : 'Coinciden') : '<strong>' . count($f['diferencias']) . ' diferencia(s)</strong>' ?></td>
                            </tr>
                            <?php if ($f['lote'] !== null && count($f['archivo']['por_unidad']) > 1): ?>
                                <tr><td></td><td colspan="6" class="texto-secundario">Por unidad (archivo / base):
                                    <?php foreach ($f['archivo']['por_unidad'] as $u => $x): ?>
                                        <?= $this->e((string) $u) ?> <?= (int) $x['filas'] ?> / <?= (int) ($f['base']['por_unidad'][$u]['filas'] ?? 0) ?> filas ·
                                    <?php endforeach; ?>
                                </td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php $conDiferencias = array_filter($fuentes, static fn (array $f): bool => $f['diferencias'] !== [] || $f['rechazadas'] !== []); ?>
            <?php if ($conDiferencias !== []): ?>
                <h3>Diferencias y filas rechazadas</h3>
                <?php foreach ($conDiferencias as $f): ?>
                    <h4><?= $this->e((string) $f['nombre']) ?></h4>
                    <?php if ($f['diferencias'] !== []): ?>
                        <ul><?php foreach ($f['diferencias'] as $d): ?><li><?= $this->e((string) $d) ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <?php if ($f['rechazadas'] !== []): ?>
                        <p>Filas del archivo que no se aplicaron (corrígelas en el libro y vuelve a cargar la fuente):</p>
                        <ul><?php foreach ($f['rechazadas'] as $r): ?><li>Fila <?= (int) $r['fila'] ?>: <?= $this->e(implode('; ', $r['mensajes'])) ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            <p class="campo-ayuda">«Archivo» son las filas y la suma calculadas al leer el libro, incluidas las filas con error. «Base» se recalcula al verificar, con lo que quedó vigente de ese lote. La suma es el valor de cada fuente (presupuesto, recaudos, ventas, gastos), la cantidad (recepciones) o el cupo (procesos).</p>
        <?php endif; ?>
        <p class="no-imprimir"><button type="button" class="boton" data-imprimir>Imprimir o guardar en PDF</button> <a class="boton" href="<?= $this->e($this->url('/importaciones/carga-inicial')) ?>">Volver a la carga</a></p>
    </article>
</div>
