<?php
/**
 * Panel de colas (RF-Q-014). Variables: $estadisticas, $fallidos, $pendientes, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$e = $estadisticas;
$latido = static fn (int $s): string => $s === PHP_INT_MAX ? 'nunca' : "hace {$s} s";
$accion = fn (string $ulid, string $nombre): string => $this->url('/sistema/colas/' . $ulid . '/' . $nombre);
?>
<div id="panel-colas">
    <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores ?? []]) ?>
    <div class="rejilla-kpi seccion-sin-tarjeta">
        <article class="tarjeta tarjeta-kpi"><h2 class="kpi-etiqueta">Pendientes</h2><p class="kpi-valor"><?= (int) $e['por_estado']['pendiente'] ?></p><p class="kpi-nota">El más antiguo: <?= (int) $e['pendiente_mas_antiguo_segundos'] ?> s</p></article>
        <article class="tarjeta tarjeta-kpi"><h2 class="kpi-etiqueta">En proceso</h2><p class="kpi-valor"><?= (int) $e['por_estado']['reservado'] ?></p><p class="kpi-nota">Duración media (24 h): <?= $e['duracion_media_ms'] === null ? 'sin datos' : (int) $e['duracion_media_ms'] . ' ms' ?></p></article>
        <article class="tarjeta tarjeta-kpi"><h2 class="kpi-etiqueta">Completados</h2><p class="kpi-valor"><?= (int) $e['por_estado']['completado'] ?></p><p class="kpi-nota">Se conservan 14 días</p></article>
        <article class="tarjeta tarjeta-kpi"><h2 class="kpi-etiqueta">Fallidos</h2><p class="kpi-valor"><?= (int) $e['por_estado']['fallido'] ?></p><p class="kpi-nota"><?= (int) $e['fallidos_ultima_hora'] ?> en la última hora</p></article>
    </div>
    <p class="texto-secundario">Latido del trabajador: <strong><?= $this->e($latido($e['latido_trabajador_segundos'])) ?></strong> · Latido del programador: <strong><?= $this->e($latido($e['latido_programador_segundos'])) ?></strong></p>

    <section class="tarjeta seccion" aria-labelledby="titulo-fallidos">
        <h2 id="titulo-fallidos" class="titulo-tarjeta">Trabajos fallidos</h2>
        <?php if ($fallidos === []): ?>
            <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'No hay trabajos fallidos', 'descripcion' => 'Cuando un trabajo agote sus intentos o falle de forma definitiva aparecerá aquí.', 'accion' => null]) ?>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-fallidos">
                <table class="tabla">
                    <thead><tr><th scope="col">Trabajo</th><th scope="col">Tipo</th><th scope="col">Error</th><th scope="col">Fallido (UTC)</th><th scope="col">Acciones</th></tr></thead>
                    <tbody>
                        <?php foreach ($fallidos as $f): ?>
                            <tr>
                                <td><code><?= $this->e($f['ulid']) ?></code></td>
                                <td><?= $this->e($f['tipo']) ?> <span class="insignia insignia-neutra"><?= $this->e($f['clase_error']) ?></span></td>
                                <td><?= $this->e($f['mensaje_error']) ?></td>
                                <td class="numero"><?= $this->e(substr((string) $f['fallido_en'], 0, 16)) ?></td>
                                <td>
                                    <form class="grupo-botones" hx-post="<?= $this->e($accion((string) $f['ulid'], 'reintentar')) ?>" hx-target="#panel-colas" hx-swap="outerHTML">
                                        <label class="sr-only" for="motivo-<?= $this->e($f['ulid']) ?>">Motivo</label>
                                        <input id="motivo-<?= $this->e($f['ulid']) ?>" class="campo campo-pequeno" name="motivo" placeholder="Motivo" maxlength="500" required>
                                        <button type="submit" class="boton boton-pequeno">Reintentar</button>
                                        <button type="submit" class="boton boton-pequeno boton-texto" hx-post="<?= $this->e($accion((string) $f['ulid'], 'descartar')) ?>" hx-target="#panel-colas" hx-swap="outerHTML" hx-include="closest form">Descartar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="tarjeta seccion" aria-labelledby="titulo-pendientes">
        <h2 id="titulo-pendientes" class="titulo-tarjeta">Pendientes (los 25 próximos)</h2>
        <?php if ($pendientes === []): ?>
            <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'La cola está al día', 'descripcion' => 'No hay trabajos esperando.', 'accion' => null]) ?>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-pendientes">
                <table class="tabla">
                    <thead><tr><th scope="col">Trabajo</th><th scope="col">Tipo</th><th scope="col" class="numero">Prioridad</th><th scope="col">Disponible (UTC)</th><th scope="col">Acciones</th></tr></thead>
                    <tbody>
                        <?php foreach ($pendientes as $p): ?>
                            <tr>
                                <td><code><?= $this->e($p['ulid']) ?></code></td>
                                <td><?= $this->e($p['tipo']) ?></td>
                                <td class="numero"><?= (int) $p['prioridad'] ?></td>
                                <td class="numero"><?= $this->e(substr((string) $p['disponible_en'], 0, 16)) ?></td>
                                <td>
                                    <form class="grupo-botones" hx-post="<?= $this->e($accion((string) $p['ulid'], 'cancelar')) ?>" hx-target="#panel-colas" hx-swap="outerHTML"
                                          data-confirmar="El trabajo no se ejecutará." data-confirmar-titulo="Cancelar trabajo" data-confirmar-accion="Cancelar trabajo" data-peligro>
                                        <label class="sr-only" for="motivo-p-<?= $this->e($p['ulid']) ?>">Motivo</label>
                                        <input id="motivo-p-<?= $this->e($p['ulid']) ?>" class="campo campo-pequeno" name="motivo" placeholder="Motivo" maxlength="500" required>
                                        <button type="submit" class="boton boton-pequeno boton-peligro">Cancelar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
