<?php
/**
 * Unidades productivas (RF-016). Variables: $unidades, $bloqueos (id => [etiqueta => cantidad]), $puedeEditar.
 *
 * @var App\Nucleo\Vista $this
 */
$tipos = App\Servicios\ServicioCatalogos::TIPOS_UNIDAD;
?>
<div id="panel-modulo">
    <section class="tarjeta seccion" aria-labelledby="titulo-unidades">
        <h2 id="titulo-unidades" class="titulo-tarjeta">Unidades del centro</h2>
        <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-unidades">
            <table class="tabla">
                <thead><tr><th scope="col">Código</th><th scope="col">Nombre</th><th scope="col">Prefijo de lote</th><th scope="col">Tipo</th><th scope="col">Estado</th><th scope="col">Registros activos</th><?php if ($puedeEditar): ?><th scope="col"><span class="sr-only">Acciones</span></th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach ($unidades as $u): ?>
                        <?php $b = $bloqueos[(int) $u['id']] ?? []; ?>
                        <tr>
                            <td><code><?= $this->e($u['codigo']) ?></code></td>
                            <td><?= $this->e($u['nombre']) ?></td>
                            <td><code><?= $this->e($u['prefijo_lote']) ?></code></td>
                            <td><?= $this->e($tipos[$u['tipo']] ?? $u['tipo']) ?></td>
                            <td><?= (int) $u['activa'] === 1 ? '<span class="insignia insignia-exito">Activa</span>' : '<span class="insignia insignia-neutra">Inactiva</span>' ?></td>
                            <td><?= $b === [] ? '<span class="texto-secundario">Ninguno</span>' : $this->e(App\Servicios\ReglasDependencias::describir($b)) ?></td>
                            <?php if ($puedeEditar): ?>
                                <td><a class="boton boton-pequeno boton-texto" href="<?= $this->e($this->url('/catalogos/unidades/' . (int) $u['id'] . '/editar')) ?>" hx-get="<?= $this->e($this->url('/catalogos/unidades/' . (int) $u['id'] . '/editar')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Editar<span class="sr-only"> <?= $this->e($u['nombre']) ?></span></a></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="campo-ayuda">La unidad SIN («Sin clasificar») existe solo para registros heredados y no se ofrece en los formularios nuevos.</p>
    </section>
</div>
