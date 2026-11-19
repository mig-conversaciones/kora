<?php
/**
 * Alta o edición de un producto (RF-017) con su configuración de derivado lácteo (RF-018). Quien solo gestiona
 * factores ve los datos generales en modo lectura. Variables: $producto (null al crear), $unidades, $valores,
 * $errores, $puedeGenerales, $puedeFactores.
 *
 * @var App\Nucleo\Vista $this
 */
$v = $valores;
$nuevo = $producto === null;
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
$opcionesUnidades = [];
foreach ($unidades as $u) {
    $opcionesUnidades[(string) $u['id']] = $u['nombre'];
}
$derivado = isset($v['id']) ? (int) ($v['es_derivado_lacteo'] ?? 0) === 1 : !empty($v['es_derivado_lacteo']);
$accion = $nuevo ? '/catalogos/productos' : '/catalogos/productos/' . (int) $producto['id'];
?>
<div id="panel-modulo">
    <section class="tarjeta seccion">
        <h2 class="titulo-tarjeta"><?= $nuevo ? 'Nuevo producto' : 'Producto ' . $this->e($producto['codigo_sgac']) ?></h2>
        <form class="formulario" hx-post="<?= $this->e($this->url($accion)) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
            <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
            <?php if (!$nuevo): ?><input type="hidden" name="version" value="<?= (int) $producto['version'] ?>"><?php endif; ?>
            <fieldset class="grupo-campos"<?= $puedeGenerales ? '' : ' disabled' ?>>
                <legend>Datos del catálogo</legend>
                <div class="rejilla-campos">
                    <?php if ($nuevo): ?>
                        <?= $campo(['nombre' => 'codigo_sgac', 'etiqueta' => 'Código SGAC', 'valor' => $v['codigo_sgac'] ?? '', 'ayuda' => 'Código oficial del catálogo; no cambia después.', 'atributos' => 'inputmode="numeric" maxlength="20" required']) ?>
                    <?php endif; ?>
                    <?= $campo(['nombre' => 'nombre', 'etiqueta' => 'Nombre', 'valor' => $v['nombre'] ?? '', 'atributos' => 'maxlength="200" required']) ?>
                    <?= $campo(['nombre' => 'medida', 'etiqueta' => 'Medida', 'tipo' => 'select', 'opciones' => App\Servicios\ServicioProductos::MEDIDAS, 'vacio' => 'Elige…', 'valor' => $v['medida'] ?? '', 'ayuda' => $nuevo ? null : 'No puede cambiar si el producto tiene recepciones.']) ?>
                    <?= $campo(['nombre' => 'categoria_rotacion', 'etiqueta' => 'Rotación (IND_12)', 'tipo' => 'select', 'opciones' => App\Servicios\ServicioProductos::ROTACION, 'valor' => $v['categoria_rotacion'] ?? 'fresco']) ?>
                    <?= $campo(['nombre' => 'clase', 'etiqueta' => 'Clase', 'valor' => $v['clase'] ?? '', 'atributos' => 'maxlength="150"']) ?>
                    <?= $campo(['nombre' => 'codigo_familia', 'etiqueta' => 'Código de familia', 'valor' => $v['codigo_familia'] ?? '', 'atributos' => 'maxlength="10"']) ?>
                    <?= $campo(['nombre' => 'nombre_familia', 'etiqueta' => 'Nombre de familia', 'valor' => $v['nombre_familia'] ?? '', 'atributos' => 'maxlength="150"']) ?>
                    <?= $campo(['nombre' => 'unidad_sugerida_id', 'etiqueta' => 'Unidad sugerida', 'tipo' => 'select', 'opciones' => $opcionesUnidades, 'vacio' => 'Sin unidad sugerida', 'valor' => $v['unidad_sugerida_id'] ?? '']) ?>
                    <?= $campo(['nombre' => 'precio_minimo', 'etiqueta' => 'Precio mínimo (COP)', 'valor' => $v['precio_minimo'] ?? '', 'atributos' => 'inputmode="decimal"']) ?>
                    <?= $campo(['nombre' => 'precio_maximo', 'etiqueta' => 'Precio máximo (COP)', 'valor' => $v['precio_maximo'] ?? '', 'atributos' => 'inputmode="decimal"']) ?>
                    <?= $campo(['nombre' => 'precio_venta', 'etiqueta' => 'Precio de venta (COP)', 'valor' => $v['precio_venta'] ?? '', 'atributos' => 'inputmode="decimal"']) ?>
                </div>
            </fieldset>
            <?php if ($puedeFactores): ?>
                <fieldset class="grupo-campos">
                    <legend>Derivado lácteo (RF-018)</legend>
                    <?= $campo(['nombre' => 'es_derivado_lacteo', 'etiqueta' => 'Es un derivado lácteo (aparece en las transformaciones)', 'tipo' => 'checkbox', 'valor' => $derivado]) ?>
                    <div class="rejilla-campos">
                        <?= $campo(['nombre' => 'factor_leche', 'etiqueta' => 'Factor de leche (L por unidad)', 'valor' => $v['factor_leche'] ?? '', 'ayuda' => 'Litros de leche por kg o L de producto. Inicial: el límite inferior del rango de la guía.', 'atributos' => 'inputmode="decimal"']) ?>
                        <?= $campo(['nombre' => 'meta_rendimiento_min', 'etiqueta' => 'Meta de rendimiento mínima', 'valor' => $v['meta_rendimiento_min'] ?? '', 'atributos' => 'inputmode="decimal"']) ?>
                        <?= $campo(['nombre' => 'meta_rendimiento_max', 'etiqueta' => 'Meta de rendimiento máxima', 'valor' => $v['meta_rendimiento_max'] ?? '', 'atributos' => 'inputmode="decimal"']) ?>
                    </div>
                    <p class="campo-ayuda">El cambio de factor queda auditado y no altera los indicadores ya exportados.</p>
                </fieldset>
            <?php endif; ?>
            <div class="grupo-botones">
                <button type="submit" class="boton boton-primario"><?= $nuevo ? 'Crear producto' : 'Guardar cambios' ?></button>
                <a class="boton boton-texto" href="<?= $this->e($this->url('/catalogos/productos')) ?>">Cancelar</a>
            </div>
        </form>
    </section>
</div>
