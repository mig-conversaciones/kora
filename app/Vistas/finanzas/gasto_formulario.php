<?php
/**
 * Registro o edición de un gasto (RF-025) con su soporte (RF-027). Variables: $gasto (null al crear), $unidades,
 * $categorias, $soportes, $periodoCerrado, $valores, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$v = $valores;
$opcionesUnidades = [];
foreach ($unidades as $u) {
    $opcionesUnidades[(string) $u['id']] = $u['nombre'];
}
$opcionesCategorias = [];
foreach ($categorias as $c) {
    $opcionesCategorias[(string) $c['id']] = $c['nombre'];
}
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
$accion = $gasto === null ? '/finanzas/gastos' : '/finanzas/gastos/' . (int) $gasto['id'];
$monto = static fn (mixed $x): string => $x === null || $x === '' ? '' : (str_contains((string) $x, '.') ? rtrim(rtrim((string) $x, '0'), '.') : (string) $x);
?>
<div id="panel-modulo">
    <section class="tarjeta seccion">
        <h2 class="titulo-tarjeta"><?= $gasto === null ? 'Registrar gasto' : 'Gasto del ' . $this->e($this->fecha($gasto['fecha'])) ?></h2>
        <?php if ($periodoCerrado): ?>
            <div class="alerta alerta-advertencia">
                <?= $this->parcial('parciales/icono', ['nombre' => 'candado', 'clase' => 'alerta-icono']) ?>
                <div class="alerta-cuerpo"><p class="alerta-titulo">Periodo cerrado</p><p>El mes de este gasto está cerrado: no admite cambios hasta que la Coordinación administrativa lo reabra.</p></div>
            </div>
        <?php endif; ?>
        <form class="formulario" hx-post="<?= $this->e($this->url($accion)) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-encoding="multipart/form-data" novalidate>
            <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
            <?php if ($gasto !== null): ?><input type="hidden" name="version" value="<?= (int) $gasto['version'] ?>"><?php endif; ?>
            <fieldset class="grupo-campos"<?= $periodoCerrado ? ' disabled' : '' ?>>
                <legend class="sr-only">Datos del gasto</legend>
                <div class="rejilla-campos">
                    <?= $campo(['nombre' => 'unidad_id', 'etiqueta' => 'Unidad', 'tipo' => 'select', 'opciones' => $opcionesUnidades, 'vacio' => count($opcionesUnidades) === 1 ? null : 'Elige…', 'valor' => $v['unidad_id'] ?? (count($opcionesUnidades) === 1 ? array_key_first($opcionesUnidades) : '')]) ?>
                    <?= $campo(['nombre' => 'fecha', 'etiqueta' => 'Fecha', 'tipo' => 'date', 'valor' => $v['fecha'] ?? '', 'atributos' => 'required']) ?>
                    <?= $campo(['nombre' => 'categoria_id', 'etiqueta' => 'Categoría', 'tipo' => 'select', 'opciones' => $opcionesCategorias, 'vacio' => 'Elige…', 'valor' => $v['categoria_id'] ?? '']) ?>
                    <?= $campo(['nombre' => 'valor', 'etiqueta' => 'Valor (COP)', 'valor' => $monto($v['valor'] ?? ''), 'atributos' => 'inputmode="decimal" required']) ?>
                    <?= $campo(['nombre' => 'valor_componente_academico', 'etiqueta' => 'Componente académico (COP)', 'valor' => $monto($v['valor_componente_academico'] ?? ''), 'ayuda' => 'Costo de las vacas de práctica incluido en el valor (IND_17). No puede superar el valor.', 'atributos' => 'inputmode="decimal"']) ?>
                    <?= $campo(['nombre' => 'referencia_soporte', 'etiqueta' => 'Referencia del soporte', 'valor' => $v['referencia_soporte'] ?? '', 'ayuda' => 'Número de factura, orden o comprobante.', 'atributos' => 'maxlength="120"']) ?>
                </div>
                <?= $campo(['nombre' => 'descripcion', 'etiqueta' => 'Descripción', 'tipo' => 'textarea', 'valor' => $v['descripcion'] ?? '', 'atributos' => 'maxlength="2000"']) ?>
                <?= $campo(['nombre' => 'soporte', 'etiqueta' => 'Adjuntar soporte', 'tipo' => 'file', 'ayuda' => 'PDF, PNG o JPG de hasta 5 MB. Se guarda en almacenamiento privado.', 'atributos' => 'accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg"']) ?>
            </fieldset>
            <?php if ($soportes !== []): ?>
                <div>
                    <p class="subtitulo-seccion">Soportes adjuntos</p>
                    <ul class="lista-adjuntos">
                        <?php foreach ($soportes as $s): ?>
                            <li><a href="<?= $this->e($this->url('/finanzas/gastos/' . (int) $gasto['id'] . '/soportes/' . (int) $s['id'])) ?>" download><?= $this->e($s['nombre_original']) ?></a> <span class="texto-secundario">(<?= $this->e($this->decimal((int) $s['tamano'] / 1024, 0)) ?> KB)</span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <div class="grupo-botones">
                <?php if (!$periodoCerrado): ?><button type="submit" class="boton boton-primario"><?= $gasto === null ? 'Registrar gasto' : 'Guardar cambios' ?></button><?php endif; ?>
                <a class="boton boton-texto" href="<?= $this->e($this->url('/finanzas/gastos')) ?>">Volver al listado</a>
            </div>
        </form>
        <?php if ($gasto !== null && !$periodoCerrado): ?>
            <?= $this->parcial('finanzas/archivar', ['ruta' => '/finanzas/gastos/' . (int) $gasto['id'] . '/archivar', 'version' => (int) $gasto['version'], 'que' => 'el gasto', 'errores' => $errores]) ?>
        <?php endif; ?>
    </section>
</div>
