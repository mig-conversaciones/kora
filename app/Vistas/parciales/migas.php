<?php
/**
 * Migas de pan. Variables: $titulo, $migas (lista de ['texto' => ..., 'url' => opcional]).
 *
 * @var App\Nucleo\Vista $this
 */
$migas = $migas === [] ? [['texto' => $titulo]] : $migas;
$ultima = count($migas) - 1;
?>
<nav class="migas" aria-label="Ubicación">
    <ol>
        <?php foreach ($migas as $i => $miga): ?>
            <li>
                <?php if ($i === $ultima): ?>
                    <span aria-current="page"><?= $this->e($miga['texto']) ?></span>
                <?php elseif (isset($miga['url'])): ?>
                    <a href="<?= $this->url($miga['url']) ?>"><?= $this->e($miga['texto']) ?></a>
                <?php else: ?>
                    <span><?= $this->e($miga['texto']) ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
