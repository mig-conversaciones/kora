<?php
/**
 * Edición de una unidad (RF-016). El código es fijo. Variables: $unidad, $valores, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$v = $valores + $unidad;
$tipos = App\Servicios\ServicioCatalogos::TIPOS_UNIDAD;
if ($unidad['codigo'] !== 'SIN') {
    unset($tipos['tecnica']);
}
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
?>
<div id="panel-modulo">
    <section class="tarjeta seccion">
        <h2 class="titulo-tarjeta">Unidad <code><?= $this->e($unidad['codigo']) ?></code></h2>
        <form class="formulario formulario-estrecho" hx-post="<?= $this->e($this->url('/catalogos/unidades/' . (int) $unidad['id'])) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
            <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
            <?= $campo(['nombre' => 'nombre', 'etiqueta' => 'Nombre', 'valor' => $v['nombre'] ?? '', 'atributos' => 'maxlength="100" required']) ?>
            <?= $campo(['nombre' => 'prefijo_lote', 'etiqueta' => 'Prefijo de lote', 'valor' => $v['prefijo_lote'] ?? '', 'ayuda' => 'De 2 a 5 letras mayúsculas; forma el número interno de los lotes (GAN-REC-…).', 'atributos' => 'maxlength="5" required']) ?>
            <?= $campo(['nombre' => 'tipo', 'etiqueta' => 'Tipo', 'tipo' => 'select', 'opciones' => $tipos, 'valor' => $v['tipo'] ?? '']) ?>
            <?= $campo(['nombre' => 'activa', 'etiqueta' => 'Unidad activa', 'tipo' => 'checkbox', 'valor' => isset($valores['id']) ? (int) $unidad['activa'] === 1 : !empty($valores['activa'])]) ?>
            <div class="grupo-botones">
                <button type="submit" class="boton boton-primario">Guardar cambios</button>
                <a class="boton boton-texto" href="<?= $this->e($this->url('/catalogos/unidades')) ?>">Cancelar</a>
            </div>
        </form>
    </section>
</div>
