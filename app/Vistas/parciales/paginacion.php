<?php
/**
 * Paginación anterior/siguiente sin COUNT (RNF-PERF-010). Conserva los filtros de la consulta. Variables: $ruta,
 * $consulta (filtros actuales), $pagina, $hayMas, $objetivo (selector del contenedor que se intercambia).
 *
 * @var App\Nucleo\Vista $this
 */
$consulta ??= [];
$enlace = function (int $numero) use ($ruta, $consulta): string {
    $parametros = array_filter($consulta + ['pagina' => $numero], static fn ($v): bool => $v !== '' && $v !== null);
    $parametros['pagina'] = $numero;
    return $this->url($ruta) . '?' . http_build_query($parametros);
};
?>
<?php if ($pagina > 1 || $hayMas): ?>
    <nav class="paginacion" aria-label="Paginación">
        <?php if ($pagina > 1): ?>
            <a class="boton boton-pequeno" href="<?= $this->e($enlace($pagina - 1)) ?>" hx-get="<?= $this->e($enlace($pagina - 1)) ?>" hx-target="<?= $this->e($objetivo) ?>" hx-swap="outerHTML" hx-push-url="true">Anterior</a>
        <?php endif; ?>
        <span class="texto-secundario">Página <?= (int) $pagina ?></span>
        <?php if ($hayMas): ?>
            <a class="boton boton-pequeno" href="<?= $this->e($enlace($pagina + 1)) ?>" hx-get="<?= $this->e($enlace($pagina + 1)) ?>" hx-target="<?= $this->e($objetivo) ?>" hx-swap="outerHTML" hx-push-url="true">Siguiente</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
