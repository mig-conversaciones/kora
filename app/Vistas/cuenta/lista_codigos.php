<?php
/**
 * Lista de códigos de respaldo (RAC-011). Variables: $codigos.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<ol class="lista-codigos" aria-label="Códigos de respaldo">
    <?php foreach ($codigos as $codigo): ?>
        <li><code><?= $this->e($codigo) ?></code></li>
    <?php endforeach; ?>
</ol>
