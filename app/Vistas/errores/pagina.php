<?php
/**
 * Página completa de error (404, 405, 500, 503…). Variables: $estado, $titulo, $contenidoFragmento.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<section class="pagina-error" aria-labelledby="titulo-error">
    <p class="pagina-error-codigo" aria-hidden="true"><?= (int) $estado ?></p>
    <h1 id="titulo-error" class="titulo-pagina"><?= $this->e($titulo) ?></h1>
    <?= $contenidoFragmento ?>
    <p><a class="boton" href="<?= $this->url('/') ?>">Volver al inicio</a></p>
</section>
