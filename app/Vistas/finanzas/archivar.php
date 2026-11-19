<?php
/**
 * Archivado con motivo y confirmación (RDL-003). Variables: $ruta, $version, $que («la asignación»), $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$errores ??= [];
?>
<details class="zona-peligro"<?= isset($errores['motivo']) ? ' open' : '' ?>>
    <summary>Archivar <?= $this->e($que) ?></summary>
    <form class="formulario" hx-post="<?= $this->e($this->url($ruta)) ?>" hx-target="#panel-modulo" hx-swap="outerHTML"
          data-confirmar="Dejará de contar en los listados y los indicadores. Queda en la auditoría." data-confirmar-titulo="Archivar <?= $this->e($que) ?>" data-confirmar-accion="Archivar" data-peligro novalidate>
        <input type="hidden" name="version" value="<?= (int) $version ?>">
        <?= $this->parcial('parciales/campo', ['nombre' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'textarea', 'errores' => $errores, 'atributos' => 'maxlength="500" required']) ?>
        <button type="submit" class="boton boton-peligro">Archivar</button>
    </form>
</details>
