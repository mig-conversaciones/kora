<?php
/**
 * Campo de formulario accesible (RUI-012): etiqueta asociada, ayuda, error por campo con aria-describedby y
 * aria-invalid. Variables:
 *   $nombre, $etiqueta, $tipo (text, number, date, select, textarea, checkbox, file, casillas), $valor,
 *   $errores, $opciones (valor => texto, para select y casillas), $vacio (texto de la opción vacía del select),
 *   $ayuda, $atributos (atributos HTML adicionales, siempre constantes de la plantilla, nunca entrada del usuario).
 *
 * @var App\Nucleo\Vista $this
 */
$tipo ??= 'text';
$errores ??= [];
$valor ??= '';
$ayuda ??= null;
$atributos ??= '';
$id = 'campo-' . str_replace(['[', ']'], ['-', ''], $nombre);
$error = $errores[rtrim($nombre, '[]')] ?? null;
$describe = trim(($ayuda !== null ? "ayuda-{$id}" : '') . ' ' . ($error !== null ? "error-{$id}" : ''));
$aria = ($describe !== '' ? ' aria-describedby="' . $this->e($describe) . '"' : '') . ($error !== null ? ' aria-invalid="true"' : '');
?>
<?php if ($tipo === 'checkbox'): ?>
    <div class="campo-grupo">
        <label class="casilla" for="<?= $this->e($id) ?>">
            <input id="<?= $this->e($id) ?>" type="checkbox" name="<?= $this->e($nombre) ?>" value="1"<?= !empty($valor) ? ' checked' : '' ?><?= $aria ?> <?= $atributos ?>>
            <?= $this->e($etiqueta) ?>
        </label>
<?php elseif ($tipo === 'casillas'): ?>
    <fieldset class="campo-grupo grupo-casillas"<?= $aria ?>>
        <legend><?= $this->e($etiqueta) ?></legend>
        <?php foreach ($opciones as $clave => $texto): ?>
            <label class="casilla"><input type="checkbox" name="<?= $this->e($nombre) ?>" value="<?= $this->e($clave) ?>"<?= in_array((string) $clave, array_map('strval', (array) $valor), true) ? ' checked' : '' ?> <?= $atributos ?>> <?= $this->e($texto) ?></label>
        <?php endforeach; ?>
<?php else: ?>
    <div class="campo-grupo">
        <label for="<?= $this->e($id) ?>"><?= $this->e($etiqueta) ?></label>
        <?php if ($tipo === 'select'): ?>
            <select id="<?= $this->e($id) ?>" class="campo" name="<?= $this->e($nombre) ?>"<?= $aria ?> <?= $atributos ?>>
                <?php if (isset($vacio)): ?><option value=""><?= $this->e($vacio) ?></option><?php endif; ?>
                <?php foreach ($opciones as $clave => $texto): ?>
                    <option value="<?= $this->e($clave) ?>"<?= (string) $clave === (string) $valor ? ' selected' : '' ?>><?= $this->e($texto) ?></option>
                <?php endforeach; ?>
            </select>
        <?php elseif ($tipo === 'textarea'): ?>
            <textarea id="<?= $this->e($id) ?>" class="campo campo-area" name="<?= $this->e($nombre) ?>"<?= $aria ?> <?= $atributos ?>><?= $this->e($valor) ?></textarea>
        <?php elseif ($tipo === 'file'): ?>
            <input id="<?= $this->e($id) ?>" class="campo campo-archivo" type="file" name="<?= $this->e($nombre) ?>"<?= $aria ?> <?= $atributos ?>>
        <?php else: ?>
            <input id="<?= $this->e($id) ?>" class="campo" type="<?= $this->e($tipo) ?>" name="<?= $this->e($nombre) ?>" value="<?= $this->e($valor) ?>"<?= $aria ?> <?= $atributos ?>>
        <?php endif; ?>
<?php endif; ?>
        <?php if ($ayuda !== null): ?><p id="ayuda-<?= $this->e($id) ?>" class="campo-ayuda"><?= $this->e($ayuda) ?></p><?php endif; ?>
        <?php if ($error !== null): ?><p id="error-<?= $this->e($id) ?>" class="campo-error"><?= $this->e($error) ?></p><?php endif; ?>
<?php if ($tipo === 'casillas'): ?>
    </fieldset>
<?php else: ?>
    </div>
<?php endif; ?>
