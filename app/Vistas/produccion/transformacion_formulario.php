<?php
/**
 * Registro de una transformación con selección del derivado y su recepción en el mismo flujo (RF-047, RF-048).
 * Si no hay derivados configurados o entregas de leche disponibles, muestra la causa y la acción que la corrige en
 * lugar de un desplegable vacío (RF-050, Obs. 6).
 * Variables: $entregas, $derivados, $recepcionesLibres, $unidadesReceptoras, $responsables, $puedeCatalogo,
 * $puedeSalida, $valores, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$v = $valores;
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
$numero = static fn (mixed $x): string => $x === null || $x === '' ? '' : (str_contains((string) $x, '.') ? rtrim(rtrim((string) $x, '0'), '.') : (string) $x);
$opcionesEntregas = [];
foreach ($entregas as $e) {
    $opcionesEntregas[(string) $e['id']] = $this->fecha($e['fecha']) . ' · ' . ($e['numero_interno'] ?? 'sin lote') . ' · disponibles ' . $this->cantidad($e['disponible']) . ' de ' . $this->cantidad($e['cantidad']) . ' L';
}
$opcionesDerivados = [];
foreach ($derivados as $d) {
    $opcionesDerivados[(string) $d['id']] = $d['nombre'] . ' (' . $d['medida'] . ')';
}
$opcionesRecepciones = [];
foreach ($recepcionesLibres as $r) {
    $opcionesRecepciones[(string) $r['id']] = $r['numero_interno'] . ' · ' . $r['producto_nombre'] . ' · ' . $this->cantidad($r['cantidad']) . ' ' . $r['medida'] . ' · ' . $this->fecha($r['fecha_recepcion']);
}
$opcionesUnidades = [];
foreach ($unidadesReceptoras as $u) {
    if ($u['codigo'] !== 'SIN') {
        $opcionesUnidades[(string) $u['id']] = $u['nombre'];
    }
}
?>
<div id="panel-modulo">
    <section class="tarjeta seccion">
        <h2 class="titulo-tarjeta">Nueva transformación láctea</h2>
        <?php if ($derivados === []): ?>
            <?= $this->parcial('parciales/estado_vacio', [
                'titulo' => 'No hay derivados lácteos configurados',
                'descripcion' => 'Para transformar leche, el catálogo necesita los derivados (queso cuajada, yogurt, manjar blanco, cortado) marcados como derivados y con su factor de leche.',
                'accion' => $puedeCatalogo ? ['texto' => 'Configurar derivados en el catálogo', 'url' => '/catalogos/productos?derivados=1'] : null,
            ]) ?>
        <?php elseif ($entregas === []): ?>
            <?= $this->parcial('parciales/estado_vacio', [
                'titulo' => 'No hay entregas de leche disponibles',
                'descripcion' => 'La entrada de una transformación es una entrega de leche por autoconsumo, en litros, con litros sin asignar. Registra la entrega desde el lote de leche.',
                'accion' => $puedeSalida ? ['texto' => 'Registrar entrega de leche', 'url' => '/produccion/salidas/nueva'] : null,
            ]) ?>
        <?php else: ?>
            <form class="formulario" hx-post="<?= $this->e($this->url('/produccion/transformaciones')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
                <fieldset class="grupo-campos">
                    <legend>Leche de origen</legend>
                    <div class="rejilla-campos">
                        <?= $campo(['nombre' => 'salida_leche_id', 'etiqueta' => 'Entrega de leche', 'tipo' => 'select', 'opciones' => $opcionesEntregas, 'vacio' => 'Elige…', 'valor' => (string) ($v['salida_leche_id'] ?? ''), 'ayuda' => 'Autoconsumo de leche en litros, con su saldo sin asignar.']) ?>
                        <?= $campo(['nombre' => 'litros_asignados', 'etiqueta' => 'Litros asignados', 'valor' => $numero($v['litros_asignados'] ?? ''), 'atributos' => 'inputmode="decimal" required']) ?>
                        <?= $campo(['nombre' => 'fecha', 'etiqueta' => 'Fecha de la transformación', 'tipo' => 'date', 'valor' => (string) ($v['fecha'] ?? ''), 'ayuda' => 'Es también la fecha de recepción del derivado.', 'atributos' => 'required']) ?>
                    </div>
                </fieldset>
                <fieldset class="grupo-campos">
                    <legend>Producto derivado</legend>
                    <?= $campo(['nombre' => 'modo_recepcion', 'etiqueta' => 'Recepción del derivado', 'tipo' => 'select', 'opciones' => ['nueva' => 'Registrar la recepción ahora', 'existente' => 'Vincular una recepción ya registrada'], 'valor' => (string) ($v['modo_recepcion'] ?? 'nueva')]) ?>
                    <div class="rejilla-campos">
                        <?= $campo(['nombre' => 'derivado_id', 'etiqueta' => 'Producto derivado', 'tipo' => 'select', 'opciones' => $opcionesDerivados, 'vacio' => 'Elige…', 'valor' => (string) ($v['derivado_id'] ?? '')]) ?>
                        <?= $campo(['nombre' => 'cantidad_derivado', 'etiqueta' => 'Cantidad obtenida', 'valor' => $numero($v['cantidad_derivado'] ?? ''), 'ayuda' => 'En la medida del derivado.', 'atributos' => 'inputmode="decimal"']) ?>
                        <?= $campo(['nombre' => 'unidad_receptora_id', 'etiqueta' => 'Unidad que recibe', 'tipo' => 'select', 'opciones' => $opcionesUnidades, 'vacio' => 'Elige…', 'valor' => (string) ($v['unidad_receptora_id'] ?? ''), 'ayuda' => 'Por ejemplo, Agroindustria o el taller de lácteos.']) ?>
                        <?= $campo(['nombre' => 'numero_interno', 'etiqueta' => 'Número interno del derivado', 'valor' => (string) ($v['numero_interno'] ?? ''), 'ayuda' => 'Formato IND-REC-210126-001.', 'atributos' => 'maxlength="40"']) ?>
                        <?= $campo(['nombre' => 'precio_sugerido', 'etiqueta' => 'Precio sugerido (COP)', 'valor' => $numero($v['precio_sugerido'] ?? ''), 'atributos' => 'inputmode="decimal"']) ?>
                        <?= $campo(['nombre' => 'responsable_id', 'etiqueta' => 'Responsable que recibe', 'tipo' => 'select', 'opciones' => $responsables, 'vacio' => 'Elige…', 'valor' => (string) ($v['responsable_id'] ?? '')]) ?>
                    </div>
                    <?php if ($opcionesRecepciones !== []): ?>
                        <?= $campo(['nombre' => 'recepcion_derivado_id', 'etiqueta' => 'Recepción existente del derivado', 'tipo' => 'select', 'opciones' => $opcionesRecepciones, 'vacio' => 'Elige… (solo si vinculas una existente)', 'valor' => (string) ($v['recepcion_derivado_id'] ?? '')]) ?>
                    <?php endif; ?>
                </fieldset>
                <p class="campo-ayuda">La transformación y la recepción del derivado se guardan juntas: si alguna regla falla, no queda ninguna de las dos.</p>
                <div class="grupo-botones">
                    <button type="submit" class="boton boton-primario">Registrar transformación</button>
                    <a class="boton boton-texto" href="<?= $this->e($this->url('/produccion/transformaciones')) ?>">Cancelar</a>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>
