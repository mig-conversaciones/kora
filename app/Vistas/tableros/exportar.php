<?php
/**
 * Botones de exportación (RF-080, RF-081): envían los mismos filtros de la pantalla y muestran el seguimiento del
 * trabajo en la zona de abajo. Variables: $tipo, $formatos (lista), $parametros (nombre => valor ya validado).
 *
 * @var App\Nucleo\Vista $this
 */
$nombres = ['xlsx' => 'Excel (XLSX)', 'csv' => 'CSV', 'pdf' => 'PDF'];
$zona = 'zona-exportacion-' . $tipo;
?>
<section class="seccion tarjeta" aria-labelledby="titulo-<?= $this->e($zona) ?>">
    <h2 id="titulo-<?= $this->e($zona) ?>" class="titulo-tarjeta">Exportar con estos filtros</h2>
    <p class="texto-secundario">El archivo respeta tus filtros y tu alcance, se prepara en segundo plano y queda disponible solo para ti durante 24 horas.</p>
    <div class="grupo-botones">
        <?php foreach ($formatos as $formato): ?>
            <form method="post" action="<?= $this->e($this->url('/exportaciones')) ?>" hx-post="<?= $this->e($this->url('/exportaciones')) ?>" hx-target="#<?= $this->e($zona) ?>" hx-swap="innerHTML">
                <input type="hidden" name="_csrf" value="<?= $this->e($this->csrf()) ?>">
                <input type="hidden" name="tipo" value="<?= $this->e($tipo) ?>">
                <input type="hidden" name="formato" value="<?= $this->e($formato) ?>">
                <?php foreach ($parametros as $nombre => $valor): ?>
                    <input type="hidden" name="<?= $this->e($nombre) ?>" value="<?= $this->e($valor) ?>">
                <?php endforeach; ?>
                <button type="submit" class="boton"><?= $this->parcial('parciales/icono', ['nombre' => 'carga']) ?> <?= $this->e($nombres[$formato] ?? $formato) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
    <div id="<?= $this->e($zona) ?>" aria-live="polite"></div>
</section>
