<?php
/**
 * Homologación de conceptos (RF-019). Variables: $homologaciones, $productos, $buscar, $puedeEditar, $valores,
 * $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$opciones = [];
foreach ($productos as $p) {
    $opciones[(string) $p['id']] = $p['nombre'] . ' (' . $p['codigo_sgac'] . ')';
}
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
?>
<div id="panel-modulo">
    <div class="rejilla-dos">
        <?php if ($puedeEditar): ?>
            <section class="tarjeta seccion" aria-labelledby="titulo-nueva-homologacion">
                <h2 id="titulo-nueva-homologacion" class="titulo-tarjeta">Agregar o reasignar</h2>
                <form class="formulario" hx-post="<?= $this->e($this->url('/catalogos/homologaciones')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                    <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
                    <?= $campo(['nombre' => 'texto', 'etiqueta' => 'Concepto en la fuente', 'valor' => $valores['texto'] ?? '', 'ayuda' => 'Se guarda sin tildes, en minúsculas y con espacios simples: «Leche de VACA» → «leche de vaca».', 'atributos' => 'maxlength="200" required']) ?>
                    <?= $campo(['nombre' => 'producto_id', 'etiqueta' => 'Producto del catálogo', 'tipo' => 'select', 'opciones' => $opciones, 'vacio' => 'Sin homologar (concepto fuente)', 'valor' => $valores['producto_id'] ?? '']) ?>
                    <button type="submit" class="boton boton-primario">Guardar equivalencia</button>
                </form>
            </section>
        <?php endif; ?>
        <section class="tarjeta seccion" aria-labelledby="titulo-homologaciones">
            <h2 id="titulo-homologaciones" class="titulo-tarjeta">Equivalencias</h2>
            <form class="barra-filtros" role="search" hx-get="<?= $this->e($this->url('/catalogos/homologaciones')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">
                <div class="campo-grupo">
                    <label for="campo-buscar-homologacion">Buscar concepto</label>
                    <input id="campo-buscar-homologacion" class="campo" type="search" name="buscar" value="<?= $this->e($buscar) ?>" maxlength="100">
                </div>
                <button type="submit" class="boton">Buscar</button>
            </form>
            <?php if ($homologaciones === []): ?>
                <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'Sin equivalencias', 'descripcion' => 'Las importaciones de la I5 proponen los conceptos de las fuentes; también puedes agregarlos aquí.', 'accion' => null]) ?>
            <?php else: ?>
                <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-homologaciones">
                    <table class="tabla">
                        <thead><tr><th scope="col">Concepto normalizado</th><th scope="col">Producto</th><th scope="col">Origen</th></tr></thead>
                        <tbody>
                            <?php foreach ($homologaciones as $h): ?>
                                <tr>
                                    <td><?= $this->e($h['texto_normalizado']) ?></td>
                                    <td><?= $h['producto_id'] === null ? '<span class="insignia insignia-advertencia">Concepto fuente</span>' : $this->e($h['producto_nombre'] . ' (' . $h['codigo_sgac'] . ')') ?></td>
                                    <td><?= $this->e($h['origen']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
