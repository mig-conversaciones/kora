<?php
/**
 * Cierres de periodo mensual (RF-028). Reabrir exige motivo y reautenticación. Variables: $vigencia, $meses,
 * $mesConError, $valores, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$nombres = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$hoy = new DateTimeImmutable('now', new DateTimeZone('America/Bogota'));
$errores ??= [];
?>
<div id="panel-modulo">
    <?= $this->parcial('parciales/selector_vigencia', ['ruta' => '/finanzas/periodos', 'vigencia' => $vigencia]) ?>
    <section class="tarjeta seccion" aria-labelledby="titulo-periodos">
        <h2 id="titulo-periodos" class="titulo-tarjeta">Meses de <?= (int) $vigencia ?></h2>
        <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-periodos">
            <table class="tabla">
                <thead><tr><th scope="col">Mes</th><th scope="col">Estado</th><th scope="col">Detalle</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
                <tbody>
                    <?php foreach ($meses as $m): ?>
                        <?php
                        $futuro = (int) $vigencia > (int) $hoy->format('Y') || ((int) $vigencia === (int) $hoy->format('Y') && $m['mes'] > (int) $hoy->format('n'));
                        $base = '/finanzas/periodos/' . (int) $vigencia . '/' . (int) $m['mes'];
                        ?>
                        <tr>
                            <th scope="row"><?= $this->e($nombres[$m['mes']]) ?></th>
                            <td>
                                <?php if ($m['estado'] === 'cerrado'): ?><span class="insignia insignia-neutra">Cerrado</span>
                                <?php elseif ($m['estado'] === 'reabierto'): ?><span class="insignia insignia-advertencia">Reabierto</span>
                                <?php else: ?><span class="insignia insignia-exito">Abierto</span><?php endif; ?>
                            </td>
                            <td class="texto-secundario">
                                <?php if ($m['estado'] === 'cerrado' && $m['cerrado_en'] !== null): ?>Cerrado el <?= $this->e($this->fecha($m['cerrado_en'])) ?> (UTC)<?php endif; ?>
                                <?php if ($m['estado'] === 'reabierto'): ?>Reabierto el <?= $this->e($this->fecha($m['reabierto_en'])) ?>: <?= $this->e($m['motivo_reapertura']) ?><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($m['estado'] === 'cerrado'): ?>
                                    <form class="grupo-botones" hx-post="<?= $this->e($this->url($base . '/reabrir')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML"
                                          data-confirmar="El mes volverá a admitir cambios en gastos, salidas, recepciones y recaudos. La reapertura queda auditada." data-confirmar-titulo="Reabrir <?= $this->e($nombres[$m['mes']]) ?>" data-confirmar-accion="Reabrir" novalidate>
                                        <label class="sr-only" for="motivo-<?= (int) $m['mes'] ?>">Motivo de la reapertura</label>
                                        <input id="motivo-<?= (int) $m['mes'] ?>" class="campo campo-pequeno" name="motivo" placeholder="Motivo (obligatorio)" maxlength="500" required<?= $mesConError === $m['mes'] && isset($errores['motivo']) ? ' aria-invalid="true" aria-describedby="error-motivo-' . (int) $m['mes'] . '"' : '' ?>>
                                        <button type="submit" class="boton boton-pequeno">Reabrir</button>
                                        <?php if ($mesConError === $m['mes'] && isset($errores['motivo'])): ?><p id="error-motivo-<?= (int) $m['mes'] ?>" class="campo-error"><?= $this->e($errores['motivo']) ?></p><?php endif; ?>
                                    </form>
                                <?php elseif (!$futuro): ?>
                                    <button type="button" class="boton boton-pequeno" hx-post="<?= $this->e($this->url($base . '/cerrar')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML"
                                            data-confirmar="No se podrán crear, editar, archivar ni importar registros con fecha en este mes." data-confirmar-titulo="Cerrar <?= $this->e($nombres[$m['mes']]) ?>" data-confirmar-accion="Cerrar periodo">Cerrar</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="campo-ayuda">Reabrir un periodo pide confirmar tu identidad (código de la aplicación o contraseña) si no lo hiciste en los últimos 5 minutos.</p>
    </section>
</div>
