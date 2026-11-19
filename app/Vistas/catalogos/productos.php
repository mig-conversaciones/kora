<?php
/**
 * Catálogo de productos (RF-017, RF-018). Variables: $filas, $hayMas, $pagina, $buscar, $derivados, $incidencias,
 * $puedeEditar, $puedeCrear.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<div id="panel-modulo">
    <?php if ($incidencias !== []): ?>
        <div class="alerta alerta-advertencia">
            <?= $this->parcial('parciales/icono', ['nombre' => 'alerta', 'clase' => 'alerta-icono']) ?>
            <div class="alerta-cuerpo">
                <p class="alerta-titulo">Configuración de derivados lácteos pendiente</p>
                <ul class="lista-errores"><?php foreach ($incidencias as $i): ?><li><?= $this->e($i) ?></li><?php endforeach; ?></ul>
            </div>
        </div>
    <?php endif; ?>
    <form class="barra-filtros" role="search" hx-get="<?= $this->e($this->url('/catalogos/productos')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true" hx-trigger="submit, input changed delay:400ms from:#campo-buscar">
        <div class="campo-grupo">
            <label for="campo-buscar">Buscar por nombre o código SGAC</label>
            <input id="campo-buscar" class="campo" type="search" name="buscar" value="<?= $this->e($buscar) ?>" maxlength="100">
        </div>
        <label class="casilla"><input type="checkbox" name="derivados" value="1"<?= $derivados ? ' checked' : '' ?>> Solo derivados lácteos</label>
        <button type="submit" class="boton">Filtrar</button>
        <?php if ($puedeCrear): ?>
            <a class="boton boton-primario" href="<?= $this->e($this->url('/catalogos/productos/nuevo')) ?>" hx-get="<?= $this->e($this->url('/catalogos/productos/nuevo')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Nuevo producto</a>
        <?php endif; ?>
    </form>
    <section class="tarjeta seccion" aria-labelledby="titulo-productos">
        <h2 id="titulo-productos" class="titulo-tarjeta">Productos</h2>
        <?php if ($filas === []): ?>
            <?= $this->parcial('parciales/estado_vacio', [
                'titulo' => $buscar !== '' || $derivados ? 'Ningún producto coincide con el filtro' : 'El catálogo está vacío',
                'descripcion' => $buscar !== '' || $derivados ? 'Prueba con otro nombre o código.' : 'El catálogo fuente (190 productos) se importa en la I5; mientras tanto puedes crear productos a mano con su código SGAC oficial.',
                'accion' => $puedeCrear ? ['texto' => 'Nuevo producto', 'url' => $this->url('/catalogos/productos/nuevo')] : null,
            ]) ?>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-productos">
                <table class="tabla">
                    <thead><tr><th scope="col">Código SGAC</th><th scope="col">Nombre</th><th scope="col">Medida</th><th scope="col" class="numero">Precio de venta</th><th scope="col">Unidad sugerida</th><th scope="col">Derivado lácteo</th><?php if ($puedeEditar): ?><th scope="col"><span class="sr-only">Acciones</span></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($filas as $p): ?>
                            <tr>
                                <td><code><?= $this->e($p['codigo_sgac']) ?></code></td>
                                <td><?= $this->e($p['nombre']) ?></td>
                                <td><?= $this->e($p['medida']) ?></td>
                                <td class="numero"><?= $p['precio_venta'] === null ? '—' : $this->e($this->moneda($p['precio_venta'])) ?></td>
                                <td><?= $this->e($p['unidad_nombre'] ?? '—') ?></td>
                                <td>
                                    <?php if ((int) $p['es_derivado_lacteo'] === 1): ?>
                                        <?php if ($p['factor_leche'] === null || (float) $p['factor_leche'] === 0.0): ?>
                                            <span class="insignia insignia-advertencia">Sin factor</span>
                                        <?php else: ?>
                                            <span class="insignia insignia-info"><?= $this->e($this->decimal($p['factor_leche'], 2)) ?> L/<?= $this->e($p['medida']) ?></span>
                                            <span class="texto-secundario">meta <?= $this->e($this->decimal($p['meta_rendimiento_min'], 2)) ?>–<?= $this->e($this->decimal($p['meta_rendimiento_max'], 2)) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <?php if ($puedeEditar): ?>
                                    <td><a class="boton boton-pequeno boton-texto" href="<?= $this->e($this->url('/catalogos/productos/' . (int) $p['id'] . '/editar')) ?>" hx-get="<?= $this->e($this->url('/catalogos/productos/' . (int) $p['id'] . '/editar')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Editar<span class="sr-only"> <?= $this->e($p['nombre']) ?></span></a></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $this->parcial('parciales/paginacion', ['ruta' => '/catalogos/productos', 'consulta' => ['buscar' => $buscar, 'derivados' => $derivados ? '1' : ''], 'pagina' => $pagina, 'hayMas' => $hayMas, 'objetivo' => '#panel-modulo']) ?>
        <?php endif; ?>
    </section>
</div>
