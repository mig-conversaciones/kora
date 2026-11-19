<?php
/**
 * Una guía de la ayuda en línea (RDOC-009). $html sale de App\Nucleo\Markdown, que escapa todo el texto de la guía
 * antes de darle formato. Variables: $html, $indice (títulos ##), $clave.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<div id="panel-modulo" class="guia">
    <?php if (count($indice) > 2): ?>
        <nav class="tarjeta seccion guia-indice no-imprimir" aria-labelledby="titulo-indice-guia">
            <h2 id="titulo-indice-guia" class="titulo-tarjeta">En esta guía</h2>
            <ol>
                <?php foreach ($indice as $t): ?>
                    <li><a href="#<?= $this->e($t['ancla']) ?>"><?= $this->e($t['texto']) ?></a></li>
                <?php endforeach; ?>
            </ol>
        </nav>
    <?php endif; ?>
    <article class="tarjeta seccion guia-contenido">
        <?= $html ?>
        <p class="no-imprimir"><button type="button" class="boton" data-imprimir>Imprimir o guardar en PDF</button> <a class="boton" href="<?= $this->e($this->url('/ayuda')) ?>">Todas las guías</a></p>
    </article>
</div>
