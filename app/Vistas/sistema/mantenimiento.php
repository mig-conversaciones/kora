<?php
/**
 * Panel Sistema → Mantenimiento (RF-MAN-001 a RF-MAN-003; FP-10). Activar y desactivar piden confirmación en el
 * modal, motivo y reautenticación reciente (la ruta la exige). Variables: $estado, $activo, $drenaje,
 * $comprobaciones, $valores, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$errores ??= [];
$valores ??= [];
$version = (int) ($estado['version'] ?? 0);
$fechaBogota = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('America/Bogota'))->format('d/m/Y H:i');
    } catch (Exception) {
        return '—';
    }
};
?>
<div id="panel-mantenimiento">
    <section class="tarjeta seccion" aria-labelledby="titulo-estado-mantenimiento">
        <h2 id="titulo-estado-mantenimiento" class="titulo-tarjeta">Estado actual</h2>
        <?php if ($activo): ?>
            <p><span class="insignia insignia-error">Activo</span> desde <?= $this->e($fechaBogota($estado['activado_en'] ?? null)) ?> (hora de Colombia)</p>
            <dl class="lista-datos">
                <dt>Título público</dt><dd><?= $this->e($estado['titulo'] ?? '') ?></dd>
                <dt>Mensaje</dt><dd><?= $this->e($estado['mensaje'] ?? '') ?></dd>
                <dt>Regreso estimado</dt><dd><?= $this->e($fechaBogota($estado['estimado_hasta'] ?? null)) ?></dd>
                <dt>Trabajos de negocio en curso</dt><dd><?= (int) $drenaje['en_curso'] ?> <?= $drenaje['listo_para_migrar'] ? '<span class="insignia insignia-exito">Listo para migrar</span>' : '' ?></dd>
            </dl>
        <?php else: ?>
            <p><span class="insignia insignia-exito">Inactivo</span> El servicio atiende normalmente.</p>
        <?php endif; ?>
        <h3 class="subtitulo-seccion">Comprobaciones de salida</h3>
        <ul class="lista-comprobaciones">
            <?php foreach ($comprobaciones as $c): ?>
                <li><?= $this->parcial('parciales/icono', ['nombre' => $c['correcta'] ? 'ok' : 'error', 'clase' => $c['correcta'] ? 'texto-exito' : 'texto-error']) ?> <?= $this->e($c['nombre']) ?>: <?= $this->e($c['detalle']) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <?php if (!$activo): ?>
        <section class="tarjeta seccion" aria-labelledby="titulo-activar">
            <h2 id="titulo-activar" class="titulo-tarjeta">Activar el mantenimiento</h2>
            <form class="formulario" hx-post="<?= $this->e($this->url('/sistema/mantenimiento/activar')) ?>" hx-target="#panel-mantenimiento" hx-swap="outerHTML"
                  data-confirmar="Los usuarios sin acceso excepcional verán el aviso y no podrán operar. Los trabajos de negocio se pausan."
                  data-confirmar-titulo="Activar el modo mantenimiento" data-confirmar-accion="Activar" data-peligro novalidate>
                <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
                <input type="hidden" name="version" value="<?= $version ?>">
                <div class="campo-grupo">
                    <label for="campo-motivo">Motivo interno (obligatorio)</label>
                    <input id="campo-motivo" class="campo" name="motivo" maxlength="500" required value="<?= $this->e($valores['motivo'] ?? '') ?>">
                    <p class="campo-ayuda">Queda en la auditoría; no se muestra al público.</p>
                </div>
                <div class="campo-grupo">
                    <label for="campo-titulo">Título del aviso</label>
                    <input id="campo-titulo" class="campo" name="titulo" maxlength="120" value="<?= $this->e($valores['titulo'] ?? 'Estamos mejorando KORA') ?>">
                </div>
                <div class="campo-grupo">
                    <label for="campo-mensaje">Mensaje público</label>
                    <textarea id="campo-mensaje" class="campo campo-area" name="mensaje" maxlength="500" rows="3"><?= $this->e($valores['mensaje'] ?? 'El sistema está en mantenimiento programado. Tus datos están seguros; vuelve a intentarlo más tarde.') ?></textarea>
                </div>
                <div class="campo-grupo">
                    <label for="campo-estimado">Regreso estimado (hora de Colombia, opcional)</label>
                    <input id="campo-estimado" class="campo" type="datetime-local" name="estimado_hasta" value="<?= $this->e($valores['estimado_hasta'] ?? '') ?>">
                    <p class="campo-ayuda">La estimación no desactiva el modo automáticamente.</p>
                </div>
                <button type="submit" class="boton boton-peligro">Activar mantenimiento</button>
            </form>
        </section>
    <?php else: ?>
        <section class="tarjeta seccion" aria-labelledby="titulo-desactivar">
            <h2 id="titulo-desactivar" class="titulo-tarjeta">Desactivar el mantenimiento</h2>
            <form class="formulario" hx-post="<?= $this->e($this->url('/sistema/mantenimiento/desactivar')) ?>" hx-target="#panel-mantenimiento" hx-swap="outerHTML"
                  data-confirmar="Se verificarán el esquema, la base de datos y el trabajador antes de reabrir el servicio."
                  data-confirmar-titulo="Desactivar el modo mantenimiento" data-confirmar-accion="Desactivar" novalidate>
                <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
                <input type="hidden" name="version" value="<?= $version ?>">
                <div class="campo-grupo">
                    <label for="campo-motivo-salida">Motivo interno (obligatorio)</label>
                    <input id="campo-motivo-salida" class="campo" name="motivo" maxlength="500" required>
                </div>
                <button type="submit" class="boton boton-primario">Desactivar y reanudar</button>
            </form>
        </section>
    <?php endif; ?>
</div>
