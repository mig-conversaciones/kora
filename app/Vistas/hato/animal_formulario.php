<?php
/**
 * Ficha de un animal en el corte vigente (RF-052, RF-054, RF-057). El líder de la unidad edita todo; la Coordinación
 * académica solo el lote de práctica y las observaciones, con motivo.
 * Variables: $animal (null al crear), $registro, $corte, $unidades, $lotes, $puedeEditar, $puedePractica, $valores,
 * $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$v = $valores;
$opcionesUnidades = [];
foreach ($unidades as $u) {
    $opcionesUnidades[(string) $u['id']] = $u['nombre'];
}
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
$accion = $animal === null ? '/hato/animales' : '/hato/animales/' . (int) $animal['id'];
$fecha = fn (string $nombre, string $etiqueta): string => $campo(['nombre' => $nombre, 'etiqueta' => $etiqueta, 'tipo' => 'date', 'valor' => (string) ($v[$nombre] ?? '')]);
?>
<div id="panel-modulo">
    <section class="tarjeta seccion">
        <h2 class="titulo-tarjeta"><?= $animal === null ? 'Nuevo animal' : 'Animal ' . $this->e($animal['identificador']) ?></h2>
        <?php if ($corte !== null): ?><p class="texto-secundario">Registro del corte vigente al <?= $this->e($this->fecha($corte['fecha_corte'])) ?>.</p><?php endif; ?>
        <?php if ($registro !== null): ?>
            <dl class="lista-datos">
                <div><dt>IEP calculado</dt><dd><?= $registro['iep_dias'] === null ? 'Sin dato' : (int) $registro['iep_dias'] . ' días' ?></dd></div>
                <div><dt>Días abiertos calculados</dt><dd><?= $registro['dias_abiertos'] === null ? 'Sin dato' : (int) $registro['dias_abiertos'] . ' días' ?></dd></div>
            </dl>
        <?php endif; ?>
        <?php if ($puedeEditar): ?>
            <form class="formulario" hx-post="<?= $this->e($this->url($accion)) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
                <?php if ($animal !== null): ?>
                    <input type="hidden" name="version" value="<?= (int) $animal['version'] ?>">
                    <?php if ($registro !== null): ?><input type="hidden" name="version_registro" value="<?= (int) $registro['version'] ?>"><?php endif; ?>
                <?php endif; ?>
                <div class="rejilla-campos">
                    <?php if ($animal === null): ?>
                        <?= $campo(['nombre' => 'unidad_id', 'etiqueta' => 'Unidad pecuaria', 'tipo' => 'select', 'opciones' => $opcionesUnidades, 'vacio' => 'Elige…', 'valor' => (string) ($v['unidad_id'] ?? '')]) ?>
                    <?php endif; ?>
                    <?= $campo(['nombre' => 'identificador', 'etiqueta' => 'Número del animal', 'valor' => (string) ($v['identificador'] ?? ''), 'atributos' => 'maxlength="30" required']) ?>
                    <?= $fecha('fecha_nacimiento', 'Fecha de nacimiento') ?>
                    <?= $campo(['nombre' => 'lote_hato', 'etiqueta' => 'Lote del hato', 'tipo' => 'select', 'opciones' => $lotes, 'valor' => (string) ($v['lote_hato'] ?? '')]) ?>
                    <?= $fecha('penultimo_parto', 'Penúltimo parto') ?>
                    <?= $fecha('ultimo_parto', 'Último parto') ?>
                    <?= $campo(['nombre' => 'servicios_posparto', 'etiqueta' => 'Servicios posparto', 'tipo' => 'number', 'valor' => (string) ($v['servicios_posparto'] ?? ''), 'atributos' => 'min="0" max="99"']) ?>
                    <?= $fecha('servicio_efectivo', 'Servicio efectivo') ?>
                    <?= $fecha('ultima_palpacion', 'Última palpación') ?>
                    <?= $campo(['nombre' => 'estado_reproductivo', 'etiqueta' => 'Estado reproductivo', 'valor' => (string) ($v['estado_reproductivo'] ?? ''), 'ayuda' => 'Por ejemplo PREÑADA o VACÍA; se guarda en mayúsculas.', 'atributos' => 'maxlength="30"']) ?>
                    <?= $campo(['nombre' => 'edad_gestacion', 'etiqueta' => 'Edad de gestación', 'valor' => (string) ($v['edad_gestacion'] ?? ''), 'atributos' => 'maxlength="30"']) ?>
                    <?= $campo(['nombre' => 'observaciones_academicas', 'etiqueta' => 'Observaciones académicas', 'tipo' => 'textarea', 'valor' => (string) ($v['observaciones_academicas'] ?? ''), 'atributos' => 'maxlength="500"']) ?>
                </div>
                <p class="campo-ayuda">El IEP (último menos penúltimo parto) y los días abiertos (servicio efectivo, o la fecha del corte, menos el último parto) se calculan al guardar.</p>
                <div class="grupo-botones">
                    <button type="submit" class="boton boton-primario"><?= $animal === null ? 'Registrar animal' : 'Guardar cambios' ?></button>
                    <a class="boton boton-texto" href="<?= $this->e($this->url('/hato')) ?>">Cancelar</a>
                </div>
            </form>
        <?php elseif ($puedePractica && $animal !== null && $registro !== null): ?>
            <form class="formulario" hx-post="<?= $this->e($this->url('/hato/animales/' . (int) $animal['id'] . '/practica')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
                <input type="hidden" name="version" value="<?= (int) $registro['version'] ?>">
                <p>Lote actual: <strong><?= $this->e($lotes[$registro['lote_hato']] ?? $registro['lote_hato']) ?></strong>. La Coordinación académica cambia solo el lote de práctica y las observaciones.</p>
                <?= $campo(['nombre' => 'en_practica', 'etiqueta' => 'En el lote de práctica académica (palpación e inseminación)', 'tipo' => 'checkbox', 'valor' => $registro['lote_hato'] === 'PRACTICA' ? '1' : '']) ?>
                <?= $campo(['nombre' => 'lote_retorno', 'etiqueta' => 'Al retirarla de práctica, vuelve a', 'tipo' => 'select', 'opciones' => ['PRODUCCION' => 'Producción', 'HORRA' => 'Horra'], 'valor' => 'PRODUCCION']) ?>
                <?= $campo(['nombre' => 'observaciones_academicas', 'etiqueta' => 'Observaciones académicas', 'tipo' => 'textarea', 'valor' => (string) ($registro['observaciones_academicas'] ?? ''), 'atributos' => 'maxlength="500"']) ?>
                <?= $campo(['nombre' => 'motivo', 'etiqueta' => 'Motivo del cambio', 'tipo' => 'textarea', 'atributos' => 'maxlength="500" required']) ?>
                <div class="grupo-botones">
                    <button type="submit" class="boton boton-primario">Guardar el lote de práctica</button>
                    <a class="boton boton-texto" href="<?= $this->e($this->url('/hato')) ?>">Cancelar</a>
                </div>
            </form>
        <?php endif; ?>
        <?php if ($animal !== null && $puedeEditar): ?>
            <?= $this->parcial('finanzas/archivar', ['ruta' => '/hato/animales/' . (int) $animal['id'] . '/archivar', 'version' => (int) $animal['version'], 'que' => 'el animal', 'errores' => $errores]) ?>
        <?php endif; ?>
    </section>
</div>
