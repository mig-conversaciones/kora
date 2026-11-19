<?php
/**
 * Campo del número interno con el consecutivo sugerido (RF-039). Se reemplaza al cambiar la unidad o la fecha.
 * Variables: $numero, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<div id="numero-sugerido">
    <?= $this->parcial('parciales/campo', ['nombre' => 'numero_interno', 'etiqueta' => 'Número interno', 'valor' => $numero, 'errores' => $errores, 'ayuda' => 'Formato GAN-REC-210126-001; se sugiere el siguiente consecutivo de la unidad y la fecha.', 'atributos' => 'maxlength="40" required']) ?>
</div>
