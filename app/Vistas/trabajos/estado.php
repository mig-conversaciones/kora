<?php
/**
 * Fragmento de seguimiento de un trabajo (RF-Q-012; base 04-04b). Mientras no sea terminal se reemplaza a sí
 * mismo con espera creciente (hx-trigger «load delay»); retroalimentacion-ui.js lo pausa con la pestaña oculta,
 * lo reanuda con el evento «reanudar» y le retira el disparador en mantenimiento (RF-MAN-008).
 * Variables: $trabajo, $rutaEstado, $espera, $intento, $terminal.
 *
 * @var App\Nucleo\Vista $this
 */
$terminal ??= false;
$ulid = (string) $trabajo['ulid'];
$progreso = max(0, min(100, (int) ($trabajo['progreso'] ?? 0)));
?>
<div id="trabajo-<?= $this->e($ulid) ?>" class="estado-trabajo estado-trabajo-<?= $this->e($trabajo['estado']) ?>" aria-live="polite"
    <?php if (!$terminal): ?>
     data-consulta-periodica
     hx-get="<?= $this->e($this->url($rutaEstado) . '?intento=' . (int) $intento) ?>"
     hx-trigger="load delay:<?= (int) $espera ?>s, reanudar"
     hx-swap="outerHTML"
    <?php endif; ?>>
    <?php if ($trabajo['estado'] === 'completado'): ?>
        <p class="estado-trabajo-titulo"><?= $this->parcial('parciales/icono', ['nombre' => 'ok', 'clase' => 'texto-exito']) ?> Proceso terminado</p>
        <progress class="barra-progreso" max="100" value="100" aria-label="Avance del proceso">100 %</progress>
    <?php elseif ($trabajo['estado'] === 'fallido' || $trabajo['estado'] === 'cancelado'): ?>
        <p class="estado-trabajo-titulo"><?= $this->parcial('parciales/icono', ['nombre' => 'error', 'clase' => 'texto-error']) ?> No se pudo completar</p>
        <p class="texto-secundario"><?= $this->e($trabajo['mensaje_error'] ?? 'Revisa el detalle o intenta de nuevo.') ?></p>
    <?php else: ?>
        <p class="estado-trabajo-titulo"><?= $this->parcial('parciales/icono', ['nombre' => 'reloj']) ?> <?= $trabajo['estado'] === 'pendiente' ? 'En cola' : 'Procesando' ?></p>
        <progress class="barra-progreso" max="100" value="<?= $progreso ?>" aria-label="Avance del proceso"><?= $progreso ?> %</progress>
        <p class="texto-secundario"><?= $this->e($trabajo['mensaje_progreso'] ?? 'Puedes seguir trabajando; te avisaremos al terminar.') ?></p>
    <?php endif; ?>
    <p class="estado-trabajo-codigo">Trabajo <code><?= $this->e($ulid) ?></code></p>
</div>
