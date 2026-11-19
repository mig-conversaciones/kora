<?php
/**
 * Importaciones: carga del libro, plantillas e historial de lotes (RF-083, RF-093). Variables: $lotes, $plantillas,
 * $fuentes, $tokenFallido, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$estados = [
    'cargado' => ['Cargado', 'neutra'], 'validando' => ['Validando…', 'info'], 'validado' => ['Listo para aplicar', 'exito'], 'con_errores' => ['Con errores', 'advertencia'],
    'aplicando' => ['Aplicando…', 'info'], 'aplicado' => ['Aplicado', 'exito'], 'revirtiendo' => ['Revirtiendo…', 'info'], 'revertido' => ['Revertido', 'neutra'], 'fallido' => ['Fallido', 'error'],
];
$errores ??= [];
?>
<div id="panel-modulo">
    <section class="tarjeta seccion" aria-labelledby="titulo-carga">
        <h2 id="titulo-carga" class="titulo-tarjeta">Cargar un libro</h2>
        <p class="texto-secundario">Acepta los libros originales (BBDD_DATOS ADMON, BBDD_Produccion de Centro, INFO_Hato) o las plantillas de abajo. Solo .xlsx de hasta 10 MB.</p>
        <?php if ($tokenFallido && isset($errores['fuentes'])): ?><p class="texto-advertencia"><?= $this->e($errores['fuentes']) ?></p><?php endif; ?>
        <form class="formulario" hx-post="<?= $this->e($this->url('/importaciones/libro')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-encoding="multipart/form-data" novalidate>
            <?= $this->parcial('parciales/resumen_errores', ['errores' => array_intersect_key($errores, ['archivo' => true])]) ?>
            <?= $this->parcial('parciales/campo', ['nombre' => 'archivo', 'etiqueta' => 'Libro de Excel', 'tipo' => 'file', 'errores' => $errores, 'ayuda' => 'Se revisa su contenido, se detectan las hojas que se pueden importar y eliges cuáles.', 'atributos' => 'accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required']) ?>
            <button type="submit" class="boton boton-primario">Revisar el libro</button>
        </form>
        <?php if ($plantillas !== []): ?>
            <h3>Plantillas</h3>
            <ul class="lista-enlaces">
                <?php foreach ($plantillas as $clave => $nombre): ?>
                    <li><a href="<?= $this->e($this->url('/importaciones/plantillas/' . $clave)) ?>"><?= $this->e($nombre) ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section class="tarjeta seccion" aria-labelledby="titulo-lotes">
        <h2 id="titulo-lotes" class="titulo-tarjeta">Lotes de importación</h2>
        <?php if ($lotes === []): ?>
            <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'Aún no hay importaciones', 'descripcion' => 'Carga un libro para empezar. Orden recomendado: catálogo, presupuesto y cupos; después producción, ventas, recaudos y hato.', 'accion' => null]) ?>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-lotes">
                <table class="tabla">
                    <thead><tr><th scope="col">Fuente</th><th scope="col">Archivo</th><th scope="col">Estado</th><th scope="col" class="numero">Filas</th><th scope="col" class="numero">Con error</th><th scope="col">Cargado por</th><th scope="col">Fecha</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
                    <tbody>
                        <?php foreach ($lotes as $l): ?>
                            <?php [$texto, $tono] = $estados[$l['estado']] ?? [$l['estado'], 'neutra']; ?>
                            <?php $enCurso = in_array($l['estado'], ['validando', 'aplicando', 'revirtiendo'], true); ?>
                            <?php $ver = $this->url('/importaciones/' . $l['ulid']); ?>
                            <tr>
                                <td><?= $this->e(isset($fuentes[$l['fuente']]) ? $fuentes[$l['fuente']]->nombre() : $l['fuente']) ?></td>
                                <td><?= $this->e(mb_strimwidth((string) $l['nombre_archivo'], 0, 40, '…')) ?></td>
                                <td><?php if ($enCurso): ?><span hx-get="<?= $this->e($this->url('/importaciones/' . $l['ulid'] . '/estado')) ?>" hx-trigger="every 3s" hx-swap="outerHTML"><span class="insignia insignia-info"><?= $this->e($texto) ?></span></span><?php else: ?><span class="insignia insignia-<?= $this->e($tono) ?>"><?= $this->e($texto) ?></span><?php endif; ?></td>
                                <td class="numero"><?= (int) $l['filas_total'] ?></td>
                                <td class="numero"><?= (int) $l['filas_error'] ?></td>
                                <td><?= $this->e($l['usuario_nombre']) ?></td>
                                <td class="numero"><?= $this->e($this->fecha($l['creado_en'])) ?></td>
                                <td><a class="boton boton-pequeno boton-texto" href="<?= $this->e($ver) ?>" hx-get="<?= $this->e($ver) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Ver</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
