<?php
/**
 * Asignaciones presupuestales de la vigencia (RF-024). Variables: $filas, $vigencia, $nombresUnidades, $puedeEditar.
 *
 * @var App\Nucleo\Vista $this
 */
$lineas = App\Servicios\ServicioAsignaciones::LINEAS;
$nueva = $this->url('/finanzas/asignaciones/nueva') . '?vigencia=' . (int) $vigencia;
?>
<div id="panel-modulo">
    <div class="barra-acciones">
        <?= $this->parcial('parciales/selector_vigencia', ['ruta' => '/finanzas/asignaciones', 'vigencia' => $vigencia]) ?>
        <?php if ($puedeEditar): ?>
            <a class="boton boton-primario" href="<?= $this->e($nueva) ?>" hx-get="<?= $this->e($nueva) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Nueva asignación</a>
        <?php endif; ?>
    </div>
    <section class="tarjeta seccion" aria-labelledby="titulo-asignaciones">
        <h2 id="titulo-asignaciones" class="titulo-tarjeta">Vigencia <?= (int) $vigencia ?></h2>
        <?php if ($filas === []): ?>
            <?= $this->parcial('parciales/estado_vacio', [
                'titulo' => 'No hay asignaciones en ' . (int) $vigencia,
                'descripcion' => $puedeEditar ? 'Registra la asignación de cada unidad con su desglose por línea.' : 'La Coordinación administrativa aún no registró asignaciones para tus unidades en esta vigencia.',
                'accion' => $puedeEditar ? ['texto' => 'Nueva asignación', 'url' => $nueva] : null,
            ]) ?>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-asignaciones">
                <table class="tabla">
                    <thead><tr><th scope="col">Unidad</th><th scope="col" class="numero">Valor total</th><?php foreach ($lineas as $texto): ?><th scope="col" class="numero"><?= $this->e($texto) ?></th><?php endforeach; ?><th scope="col" class="numero">Semovientes</th><?php if ($puedeEditar): ?><th scope="col"><span class="sr-only">Acciones</span></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($filas as $a): ?>
                            <tr>
                                <td><?= $this->e($nombresUnidades[(string) $a['unidad_id']] ?? $a['unidad_id']) ?></td>
                                <td class="numero"><strong><?= $this->e($this->moneda($a['valor_total'])) ?></strong></td>
                                <?php foreach (array_keys($lineas) as $linea): ?><td class="numero"><?= $this->e($this->moneda($a[$linea])) ?></td><?php endforeach; ?>
                                <td class="numero"><?= $a['numero_semovientes'] === null ? '—' : (int) $a['numero_semovientes'] ?></td>
                                <?php if ($puedeEditar): ?>
                                    <?php $editar = $this->url('/finanzas/asignaciones/' . (int) $a['id'] . '/editar'); ?>
                                    <td><a class="boton boton-pequeno boton-texto" href="<?= $this->e($editar) ?>" hx-get="<?= $this->e($editar) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Editar</a></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
