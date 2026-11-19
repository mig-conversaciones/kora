<?php
/**
 * Seguimiento de una exportación (RF-080, RF-Q-012): se consulta a sí mismo con espera creciente hasta un estado
 * terminal; al completarse ofrece la descarga, que vence a las 24 horas (RF-082).
 * Variables: $exportacion (fila propia con el estado del trabajo), $espera, $intento.
 *
 * @var App\Nucleo\Vista $this
 */
$ulid = (string) $exportacion['ulid'];
$estado = (string) ($exportacion['estado'] ?? 'pendiente');
$terminal = in_array($estado, ['completado', 'fallido', 'cancelado'], true);
$progreso = max(0, min(100, (int) ($exportacion['progreso'] ?? 0)));
$formato = strtoupper((string) $exportacion['formato']);
?>
<div id="exportacion-<?= $this->e($ulid) ?>" class="estado-trabajo estado-trabajo-<?= $this->e($estado) ?>" aria-live="polite"
    <?php if (!$terminal): ?>
     data-consulta-periodica
     hx-get="<?= $this->e($this->url('/exportaciones/' . $ulid) . '?intento=' . (int) $intento) ?>"
     hx-trigger="load delay:<?= (int) $espera ?>s, reanudar"
     hx-swap="outerHTML"
    <?php endif; ?>>
    <?php if ($estado === 'completado' && (int) ($exportacion['vigente'] ?? 0) === 1): ?>
        <p class="estado-trabajo-titulo"><?= $this->parcial('parciales/icono', ['nombre' => 'ok', 'clase' => 'texto-exito']) ?> Archivo <?= $this->e($formato) ?> listo</p>
        <p><a class="boton boton-primario" href="<?= $this->e($this->url('/exportaciones/' . $ulid . '/descargar')) ?>" download>Descargar</a></p>
        <p class="texto-secundario">Disponible solo para ti hasta el <?= $this->e(App\Tableros\Formato::fecha((string) $exportacion['expira_en'])) ?> (24 horas).</p>
    <?php elseif ($estado === 'completado'): ?>
        <p class="estado-trabajo-titulo">El enlace venció</p>
        <p class="texto-secundario">Las exportaciones se guardan 24 horas. Pide una nueva desde el tablero.</p>
    <?php elseif ($terminal): ?>
        <p class="estado-trabajo-titulo"><?= $this->parcial('parciales/icono', ['nombre' => 'error', 'clase' => 'texto-error']) ?> No se pudo generar el archivo</p>
        <p class="texto-secundario"><?= $this->e($exportacion['mensaje_error'] ?? 'Intenta de nuevo en unos minutos.') ?></p>
    <?php else: ?>
        <p class="estado-trabajo-titulo"><?= $this->parcial('parciales/icono', ['nombre' => 'reloj']) ?> Preparando el archivo <?= $this->e($formato) ?></p>
        <progress class="barra-progreso" max="100" value="<?= $progreso ?>" aria-label="Avance de la exportación"><?= $progreso ?> %</progress>
        <p class="texto-secundario"><?= $this->e($exportacion['mensaje_progreso'] ?? 'En cola. Puedes seguir trabajando.') ?></p>
    <?php endif; ?>
</div>
