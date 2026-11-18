<?php
/**
 * Fragmento de la demostración de carga. Variables: $filas.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<table class="tabla">
    <caption class="sr-only">Lotes cargados desde el servidor</caption>
    <thead><tr><th scope="col">Lote</th><th scope="col">Producto</th><th scope="col" class="numero">Cantidad</th><th scope="col">Estado</th></tr></thead>
    <tbody>
        <?php foreach ($filas as $fila): ?>
            <tr>
                <td><?= $this->e($fila['lote']) ?></td>
                <td><?= $this->e($fila['producto']) ?></td>
                <td class="numero"><?= $this->e(number_format((float) $fila['cantidad'], 1, ',', '.')) ?> <?= $this->e($fila['medida']) ?></td>
                <td><?= $this->e($fila['estado']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
