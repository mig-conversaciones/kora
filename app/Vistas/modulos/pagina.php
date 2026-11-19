<?php
/**
 * Página común de los módulos de gestión: encabezado, pestañas de la sección y el fragmento intercambiable.
 * Variables: $contenidoFragmento, $sobretitulo, $titulo, $descripcion, $pestanas (lista de [texto, ruta, permiso?]),
 * $acciones (HTML de botones ya escapado por la plantilla que lo arma), $usuario.
 *
 * @var App\Nucleo\Vista $this
 */
$pestanas ??= [];
$rutaActual = isset($solicitud) ? $solicitud->ruta : '';
$visibles = array_values(array_filter($pestanas, static fn (array $p): bool => !isset($p['permiso']) || ($usuario !== null && $usuario->tienePermiso((string) $p['permiso']))));
?>
<header class="encabezado-pagina">
    <div>
        <p class="sobretitulo"><?= $this->e($sobretitulo ?? '') ?></p>
        <h1 class="titulo-pagina"><?= $this->e($titulo) ?></h1>
        <?php if (!empty($descripcion)): ?><p class="texto-secundario"><?= $this->e($descripcion) ?></p><?php endif; ?>
    </div>
    <?php if (!empty($acciones)): ?><div class="grupo-botones"><?= $acciones ?></div><?php endif; ?>
</header>
<?php if (count($visibles) > 1): ?>
    <nav class="pestanas" aria-label="<?= $this->e($sobretitulo ?? 'Secciones') ?>">
        <?php foreach ($visibles as $p): ?>
            <?php $activa = $rutaActual === $p['ruta'] || str_starts_with($rutaActual, $p['ruta'] . '/'); ?>
            <a class="pestana" href="<?= $this->e($this->url($p['ruta'])) ?>"<?= $activa ? ' aria-current="page"' : '' ?>><?= $this->e($p['texto']) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
<?= $contenidoFragmento ?>
