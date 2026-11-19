<?php
/**
 * Matriz de roles y permisos (RF-011). Variables: $roles, $recursos (recurso => permisos), $asignados
 * ("rol:permiso" => true), $puedeEditar.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<div id="panel-modulo">
    <section class="tarjeta seccion" aria-labelledby="titulo-matriz">
        <h2 id="titulo-matriz" class="titulo-tarjeta">Matriz de autorización</h2>
        <p class="texto-secundario"><?= $puedeEditar ? 'Marca o desmarca un permiso para concederlo o retirarlo. Los permisos del superadministrador están protegidos.' : 'Consulta de solo lectura.' ?></p>
        <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-matriz">
            <table class="tabla tabla-matriz">
                <thead>
                    <tr>
                        <th scope="col">Permiso</th>
                        <?php foreach ($roles as $r): ?><th scope="col" class="celda-centrada"><?= $this->e($r['nombre']) ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recursos as $recurso => $permisos): ?>
                        <tr class="fila-grupo"><th scope="rowgroup" colspan="<?= count($roles) + 1 ?>"><?= $this->e($recurso) ?></th></tr>
                        <?php foreach ($permisos as $p): ?>
                            <tr>
                                <th scope="row"><code><?= $this->e($p['codigo']) ?></code><br><span class="texto-secundario"><?= $this->e($p['descripcion']) ?></span></th>
                                <?php foreach ($roles as $r): ?>
                                    <?php $tiene = isset($asignados[$r['id'] . ':' . $p['id']]); ?>
                                    <td class="celda-centrada">
                                        <?php if ($puedeEditar && $r['codigo'] !== 'superadministrador'): ?>
                                            <button type="button" class="boton boton-pequeno boton-icono" aria-pressed="<?= $tiene ? 'true' : 'false' ?>"
                                                    aria-label="<?= $this->e(($tiene ? 'Retirar ' : 'Conceder ') . $p['codigo'] . ' a ' . $r['nombre']) ?>"
                                                    hx-post="<?= $this->e($this->url('/sistema/roles/' . (int) $r['id'] . '/' . (int) $p['id'] . '/' . ($tiene ? 'retirar' : 'conceder'))) ?>" hx-target="#panel-modulo" hx-swap="outerHTML"
                                                    data-confirmar="<?= $this->e(($tiene ? 'Se retirará ' : 'Se concederá ') . $p['codigo'] . ' a todas las cuentas con el rol ' . $r['nombre'] . '. Se aplica en su siguiente solicitud.') ?>" data-confirmar-titulo="Cambiar permiso" data-confirmar-accion="<?= $tiene ? 'Retirar' : 'Conceder' ?>"<?= $tiene ? ' data-peligro' : '' ?>>
                                                <?= $tiene ? $this->parcial('parciales/icono', ['nombre' => 'ok']) : '' ?>
                                            </button>
                                        <?php elseif ($tiene): ?>
                                            <?= $this->parcial('parciales/icono', ['nombre' => 'ok', 'clase' => 'texto-exito']) ?><span class="sr-only">Sí</span>
                                        <?php else: ?>
                                            <span class="texto-secundario" aria-hidden="true">·</span><span class="sr-only">No</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
