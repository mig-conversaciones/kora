<?php
/**
 * Índice de la ayuda (RDOC-009). Variables: $propias (guías de los roles del usuario), $otras.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<div id="panel-modulo">
    <section class="tarjeta seccion" aria-labelledby="titulo-propias">
        <h2 id="titulo-propias" class="titulo-tarjeta">Para tu rol</h2>
        <ul class="lista-enlaces">
            <?php foreach ($propias as $g): ?>
                <li><a href="<?= $this->e($this->url('/ayuda/' . $g['clave'])) ?>"><?= $this->e($g['titulo']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php if ($otras !== []): ?>
        <section class="tarjeta seccion" aria-labelledby="titulo-otras">
            <h2 id="titulo-otras" class="titulo-tarjeta">Otros roles</h2>
            <p class="texto-secundario">Sirven para entender qué hace cada perfil; tus permisos no cambian.</p>
            <ul class="lista-enlaces">
                <?php foreach ($otras as $g): ?>
                    <li><a href="<?= $this->e($this->url('/ayuda/' . $g['clave'])) ?>"><?= $this->e($g['titulo']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</div>
