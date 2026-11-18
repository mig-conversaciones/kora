<?php
/**
 * Sesiones activas del usuario (RAC-020): dispositivo, IP tratada y última actividad. Variables: $sesiones.
 *
 * @var App\Nucleo\Vista $this
 */
$navegador = static function (?string $agente): string {
    $agente = (string) $agente;
    $nombre = match (true) {
        str_contains($agente, 'Edg/') => 'Edge',
        str_contains($agente, 'Firefox/') => 'Firefox',
        str_contains($agente, 'Chrome/') => 'Chrome',
        str_contains($agente, 'Safari/') => 'Safari',
        default => 'Navegador desconocido',
    };
    $sistema = match (true) {
        str_contains($agente, 'Windows') => 'Windows',
        str_contains($agente, 'Android') => 'Android',
        str_contains($agente, 'iPhone') || str_contains($agente, 'iPad') => 'iOS',
        str_contains($agente, 'Mac OS') => 'macOS',
        str_contains($agente, 'Linux') => 'Linux',
        default => '',
    };
    return trim($nombre . ($sistema !== '' ? ' en ' . $sistema : ''));
};
$hayOtras = count(array_filter($sesiones, static fn (array $s): bool => !$s['actual'])) > 0;
?>
<div class="contenedor-tabla" tabindex="0" role="region" aria-label="Sesiones activas">
    <table class="tabla">
        <thead><tr><th scope="col">Dispositivo</th><th scope="col">Red (IP tratada)</th><th scope="col">Última actividad (UTC)</th><th scope="col">Estado</th></tr></thead>
        <tbody>
            <?php foreach ($sesiones as $s): ?>
                <tr>
                    <td><?= $this->e($navegador($s['agente'])) ?></td>
                    <td><?= $this->e($s['ip_tratada'] ?? '—') ?></td>
                    <td class="numero"><?= $this->e(substr((string) $s['ultima_actividad_en'], 0, 16)) ?></td>
                    <td><?= $s['actual'] ? '<span class="insignia insignia-info">Esta sesión</span>' : '<span class="insignia insignia-neutra">Abierta</span>' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if ($hayOtras): ?>
    <button type="button" class="boton boton-peligro" hx-post="<?= $this->e($this->url('/cuenta/sesiones/cerrar-otras')) ?>" hx-target="#lista-sesiones"
            data-confirmar="Se cerrarán todas tus sesiones excepto esta." data-confirmar-titulo="Cerrar las demás sesiones" data-confirmar-accion="Cerrar sesiones" data-peligro>
        Cerrar las demás sesiones
    </button>
<?php endif; ?>
