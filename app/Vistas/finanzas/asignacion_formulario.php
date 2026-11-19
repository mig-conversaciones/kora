<?php
/**
 * Alta o edición de una asignación (RF-024). Variables: $asignacion (null al crear), $unidades, $valores, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$v = $valores;
$opciones = [];
foreach ($unidades as $u) {
    $opciones[(string) $u['id']] = $u['nombre'];
}
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
$accion = $asignacion === null ? '/finanzas/asignaciones' : '/finanzas/asignaciones/' . (int) $asignacion['id'];
$monto = static fn (mixed $x): string => $x === null || $x === '' ? '' : (str_contains((string) $x, '.') ? rtrim(rtrim((string) $x, '0'), '.') : (string) $x);
?>
<div id="panel-modulo">
    <section class="tarjeta seccion">
        <h2 class="titulo-tarjeta"><?= $asignacion === null ? 'Nueva asignación' : 'Asignación ' . (int) $asignacion['vigencia'] ?></h2>
        <form class="formulario" hx-post="<?= $this->e($this->url($accion)) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
            <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
            <?php if ($asignacion !== null): ?><input type="hidden" name="version" value="<?= (int) $asignacion['version'] ?>"><?php endif; ?>
            <div class="rejilla-campos">
                <?= $campo(['nombre' => 'unidad_id', 'etiqueta' => 'Unidad', 'tipo' => 'select', 'opciones' => $opciones, 'vacio' => 'Elige…', 'valor' => $v['unidad_id'] ?? '']) ?>
                <?= $campo(['nombre' => 'vigencia', 'etiqueta' => 'Vigencia', 'tipo' => 'number', 'valor' => $v['vigencia'] ?? '', 'atributos' => 'min="2020" max="2100" required']) ?>
                <?= $campo(['nombre' => 'valor_total', 'etiqueta' => 'Valor total (COP)', 'valor' => $monto($v['valor_total'] ?? ''), 'atributos' => 'inputmode="decimal" required']) ?>
                <?php foreach (App\Servicios\ServicioAsignaciones::LINEAS as $linea => $texto): ?>
                    <?= $campo(['nombre' => $linea, 'etiqueta' => $texto . ' (COP)', 'valor' => $monto($v[$linea] ?? ''), 'atributos' => 'inputmode="decimal"']) ?>
                <?php endforeach; ?>
                <?= $campo(['nombre' => 'numero_semovientes', 'etiqueta' => 'Número de semovientes', 'tipo' => 'number', 'valor' => $v['numero_semovientes'] ?? '', 'atributos' => 'min="0"']) ?>
            </div>
            <p class="campo-ayuda">La suma del desglose no puede superar el valor total. Solo hay una asignación activa por unidad y vigencia.</p>
            <div class="grupo-botones">
                <button type="submit" class="boton boton-primario"><?= $asignacion === null ? 'Crear asignación' : 'Guardar cambios' ?></button>
                <a class="boton boton-texto" href="<?= $this->e($this->url('/finanzas/asignaciones')) ?>">Cancelar</a>
            </div>
        </form>
        <?php if ($asignacion !== null): ?>
            <?= $this->parcial('finanzas/archivar', ['ruta' => '/finanzas/asignaciones/' . (int) $asignacion['id'] . '/archivar', 'version' => (int) $asignacion['version'], 'que' => 'la asignación', 'errores' => $errores]) ?>
        <?php endif; ?>
    </section>
</div>
