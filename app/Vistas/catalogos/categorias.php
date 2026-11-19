<?php
/**
 * Categorías de gasto (RF-020). Variables: $categorias, $puedeEditar, $valores, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$lineas = App\Servicios\ServicioCatalogos::LINEAS;
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
?>
<div id="panel-modulo">
    <div class="rejilla-dos">
        <section class="tarjeta seccion" aria-labelledby="titulo-categorias">
            <h2 id="titulo-categorias" class="titulo-tarjeta">Categorías</h2>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-categorias">
                <table class="tabla">
                    <thead><tr><th scope="col">Código</th><th scope="col">Nombre</th><th scope="col">Línea presupuestal</th><th scope="col">IND_17</th><th scope="col">Estado</th><?php if ($puedeEditar): ?><th scope="col"><span class="sr-only">Acciones</span></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($categorias as $c): ?>
                            <tr>
                                <td><code><?= $this->e($c['codigo']) ?></code></td>
                                <td><?= $this->e($c['nombre']) ?></td>
                                <td><?= $this->e($lineas[$c['linea_presupuestal']] ?? $c['linea_presupuestal']) ?></td>
                                <td><?= (int) $c['incluye_ind17'] === 1 ? 'Sí' : 'No' ?></td>
                                <td><?= (int) $c['activa'] === 1 ? '<span class="insignia insignia-exito">Activa</span>' : '<span class="insignia insignia-neutra">Inactiva</span>' ?></td>
                                <?php if ($puedeEditar): ?>
                                    <td>
                                        <?php $estado = (int) $c['activa'] === 1 ? 'inactivar' : 'activar'; ?>
                                        <button type="button" class="boton boton-pequeno boton-texto" hx-post="<?= $this->e($this->url('/catalogos/categorias/' . (int) $c['id'] . '/' . $estado)) ?>" hx-target="#panel-modulo" hx-swap="outerHTML"><?= $estado === 'activar' ? 'Activar' : 'Inactivar' ?><span class="sr-only"> <?= $this->e($c['nombre']) ?></span></button>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="campo-ayuda">Un gasto de Materiales de formación no modifica IND_17 (S-14). Inactivar una categoría la retira de los gastos nuevos sin afectar los existentes.</p>
        </section>
        <?php if ($puedeEditar): ?>
            <section class="tarjeta seccion" aria-labelledby="titulo-nueva-categoria">
                <h2 id="titulo-nueva-categoria" class="titulo-tarjeta">Nueva categoría</h2>
                <form class="formulario" hx-post="<?= $this->e($this->url('/catalogos/categorias')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                    <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
                    <?= $campo(['nombre' => 'codigo', 'etiqueta' => 'Código', 'valor' => $valores['codigo'] ?? '', 'ayuda' => 'Mayúsculas, números y guion bajo; por ejemplo COMBUSTIBLE.', 'atributos' => 'maxlength="30" required']) ?>
                    <?= $campo(['nombre' => 'nombre', 'etiqueta' => 'Nombre', 'valor' => $valores['nombre'] ?? '', 'atributos' => 'maxlength="80" required']) ?>
                    <?= $campo(['nombre' => 'linea_presupuestal', 'etiqueta' => 'Línea presupuestal', 'tipo' => 'select', 'opciones' => $lineas, 'vacio' => 'Elige…', 'valor' => $valores['linea_presupuestal'] ?? '']) ?>
                    <?= $campo(['nombre' => 'incluye_ind17', 'etiqueta' => 'Incluye en IND_17 (solo Mantenimiento y Alimentación)', 'tipo' => 'checkbox', 'valor' => !empty($valores['incluye_ind17'])]) ?>
                    <button type="submit" class="boton boton-primario">Crear categoría</button>
                </form>
            </section>
        <?php endif; ?>
    </div>
</div>
