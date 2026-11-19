<?php
/**
 * Menú lateral (RI-002). aria-current="page" en la entrada activa. Las entradas sin ruta se muestran deshabilitadas
 * con la iteración que las entrega. Variables: $menu, $rutaActual.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<div class="marca">
    <a class="marca-enlace" href="<?= $this->url('/') ?>" aria-label="KORA, ir al inicio">
        <img class="marca-logo" src="<?= $this->url('/marca/kora-logo-oscuro-sin-lema.svg') ?>" alt="" width="136" height="32">
        <img class="marca-simbolo" src="<?= $this->url('/marca/kora-simbolo-oscuro.svg') ?>" alt="" width="30" height="32">
    </a>
</div>
<nav class="menu" aria-label="Secciones">
    <?php foreach ($menu as $grupo): ?>
        <p class="menu-grupo"><?= $this->e($grupo['grupo']) ?></p>
        <ul class="menu-lista">
            <?php foreach ($grupo['entradas'] as $entrada): ?>
                <li>
                    <?php if (isset($entrada['ruta'])): ?>
                        <?php $activa = $entrada['ruta'] === $rutaActual || ($entrada['ruta'] !== '/' && str_starts_with($rutaActual, $entrada['ruta'] . '/')); ?>
                        <a class="menu-enlace" href="<?= $this->url($entrada['ruta']) ?>"<?= $activa ? ' aria-current="page"' : '' ?>>
                            <?= $this->parcial('parciales/icono', ['nombre' => $entrada['icono']]) ?>
                            <span class="menu-texto"><?= $this->e($entrada['texto']) ?></span>
                        </a>
                    <?php else: ?>
                        <span class="menu-enlace menu-enlace-inactivo" aria-disabled="true" title="Disponible en la iteración <?= $this->e($entrada['iteracion'] ?? '') ?>">
                            <?= $this->parcial('parciales/icono', ['nombre' => $entrada['icono']]) ?>
                            <span class="menu-texto"><?= $this->e($entrada['texto']) ?></span>
                            <span class="insignia insignia-neutra menu-insignia"><?= $this->e($entrada['iteracion'] ?? '') ?></span>
                        </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</nav>
