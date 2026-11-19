<?php
/**
 * Transformaciones lácteas con el resultado del derivado (RF-047, RF-051). Variables: $filas, $hayMas, $pagina,
 * $nombresUnidades, $puedeEditar, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$nuevo = $this->url('/produccion/transformaciones/nueva');
?>
<div id="panel-modulo">
    <div class="barra-acciones">
        <span></span>
        <?php if ($puedeEditar): ?>
            <a class="boton boton-primario" href="<?= $this->e($nuevo) ?>" hx-get="<?= $this->e($nuevo) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Registrar transformación</a>
        <?php endif; ?>
    </div>
    <section class="tarjeta seccion" aria-labelledby="titulo-transformaciones">
        <h2 id="titulo-transformaciones" class="titulo-tarjeta">Transformaciones</h2>
        <?php if ($filas === []): ?>
            <?= $this->parcial('parciales/estado_vacio', [
                'titulo' => 'Aún no hay transformaciones',
                'descripcion' => 'Una transformación asigna litros de una entrega de leche por autoconsumo a la recepción de un derivado (queso, yogurt…).',
                'accion' => $puedeEditar ? ['texto' => 'Registrar transformación', 'url' => '/produccion/transformaciones/nueva'] : null,
            ]) ?>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-transformaciones">
                <table class="tabla">
                    <thead><tr><th scope="col">Fecha</th><th scope="col">Origen de la leche</th><th scope="col" class="numero">Litros asignados</th><th scope="col">Derivado</th><th scope="col" class="numero">Cantidad</th><th scope="col">Recibe</th><th scope="col" class="numero">Rendimiento</th><th scope="col">Meta</th></tr></thead>
                    <tbody>
                        <?php foreach ($filas as $t): ?>
                            <tr>
                                <td class="numero"><?= $this->e($this->fecha($t['fecha'])) ?></td>
                                <td><?= $this->e($nombresUnidades[(string) $t['unidad_id']] ?? $t['unidad_id']) ?> <span class="texto-secundario">(entrega del <?= $this->e($this->fecha($t['fecha_entrega'] ?? '')) ?>)</span></td>
                                <td class="numero"><?= $this->e($this->cantidad($t['litros_asignados'])) ?> L</td>
                                <td><?= $this->e($t['derivado'] ?? '') ?> <code><?= $this->e($t['numero_interno'] ?? '') ?></code></td>
                                <td class="numero"><?= $this->e($this->cantidad($t['cantidad_derivado'] ?? '0')) ?> <?= $this->e($t['medida_derivado'] ?? '') ?></td>
                                <td><?= $this->e($nombresUnidades[(string) ($t['unidad_derivado'] ?? '')] ?? '') ?></td>
                                <td class="numero"><?= $t['rendimiento'] === null ? '—' : $this->e($this->decimal($t['rendimiento'], 2)) . ' L/' . $this->e($t['medida_derivado'] ?? '') ?></td>
                                <td>
                                    <?php if ($t['en_meta'] === null): ?><span class="insignia insignia-neutra">Sin meta</span>
                                    <?php elseif ($t['en_meta']): ?><span class="insignia insignia-exito">En meta</span>
                                    <?php else: ?><span class="insignia insignia-advertencia">Sobre la meta</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="campo-ayuda">Rendimiento = litros asignados / cantidad del derivado. En meta si es igual o menor que el máximo del rango del catálogo.</p>
            <?= $this->parcial('parciales/paginacion', ['ruta' => '/produccion/transformaciones', 'consulta' => [], 'pagina' => $pagina, 'hayMas' => $hayMas, 'objetivo' => '#panel-modulo']) ?>
        <?php endif; ?>
    </section>
</div>
