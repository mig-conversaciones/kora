<?php
/**
 * Procesos de autoconsumo con su ejecución (RF-036, RF-037). Variables: $filas, $vigencia, $nombresUnidades,
 * $puedeEditar.
 *
 * @var App\Nucleo\Vista $this
 */
$nuevo = $this->url('/finanzas/procesos/nuevo') . '?vigencia=' . (int) $vigencia;
?>
<div id="panel-modulo">
    <div class="barra-acciones">
        <?= $this->parcial('parciales/selector_vigencia', ['ruta' => '/finanzas/procesos', 'vigencia' => $vigencia]) ?>
        <?php if ($puedeEditar): ?>
            <a class="boton boton-primario" href="<?= $this->e($nuevo) ?>" hx-get="<?= $this->e($nuevo) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Nuevo proceso</a>
        <?php endif; ?>
    </div>
    <section class="tarjeta seccion" aria-labelledby="titulo-procesos">
        <h2 id="titulo-procesos" class="titulo-tarjeta">Procesos <?= (int) $vigencia ?></h2>
        <?php if ($filas === []): ?>
            <?= $this->parcial('parciales/estado_vacio', [
                'titulo' => 'No hay procesos en ' . (int) $vigencia,
                'descripcion' => 'Los cupos de autoconsumo (AUTO_001…) se registran aquí o se importan desde el archivo de cupos (I5).',
                'accion' => $puedeEditar ? ['texto' => 'Nuevo proceso', 'url' => $nuevo] : null,
            ]) ?>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-procesos">
                <table class="tabla">
                    <thead><tr><th scope="col">Código</th><th scope="col">Unidad de origen</th><th scope="col">Destino</th><th scope="col">Producto</th><th scope="col" class="numero">Cupo</th><th scope="col" class="numero">Entregado</th><th scope="col" class="numero">Avance físico</th><th scope="col" class="numero">Valor del cupo</th><th scope="col" class="numero">Avance monetario</th><?php if ($puedeEditar): ?><th scope="col"><span class="sr-only">Acciones</span></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($filas as $p): ?>
                            <tr>
                                <td><code><?= $this->e($p['codigo']) ?></code></td>
                                <td><?= $this->e($nombresUnidades[(string) $p['unidad_id']] ?? $p['unidad_id']) ?></td>
                                <td><?= $this->e($p['destino_texto'] ?? $p['destino_nombre'] ?? '—') ?></td>
                                <td><?= $this->e($p['producto_texto']) ?></td>
                                <td class="numero"><?= $this->e($this->decimal($p['cupo_cantidad'], 0)) ?> <?= $this->e($p['medida']) ?></td>
                                <td class="numero"><?= $this->e($this->decimal($p['entregado_cantidad'], 0)) ?> <?= $this->e($p['medida']) ?></td>
                                <td class="numero"><?= $this->e($this->decimal($p['avance_fisico'], 1)) ?> %</td>
                                <td class="numero"><?= $this->e($this->moneda($p['cupo_valor'])) ?></td>
                                <td class="numero"><?= $this->e($this->decimal($p['avance_monetario'], 1)) ?> %</td>
                                <?php if ($puedeEditar): ?>
                                    <?php $editar = $this->url('/finanzas/procesos/' . (int) $p['id'] . '/editar'); ?>
                                    <td><a class="boton boton-pequeno boton-texto" href="<?= $this->e($editar) ?>" hx-get="<?= $this->e($editar) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Editar<span class="sr-only"> <?= $this->e($p['codigo']) ?></span></a></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="campo-ayuda">Solo cuentan las entregas de autoconsumo vinculadas al proceso, de su misma unidad y su misma medida. Las entregas se registran desde la I4.</p>
        <?php endif; ?>
    </section>
</div>
