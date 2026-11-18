<?php
/**
 * Formulario de demostración de la validación (RUI-012): aria-invalid, aria-describedby y resumen de errores con
 * data-resumen-errores. Variables: $valores, $errores, $exito (opcional).
 *
 * @var App\Nucleo\Vista $this
 */
$errores ??= [];
$valores ??= [];
$campo = function (string $nombre) use ($errores): string {
    return isset($errores[$nombre]) ? ' aria-invalid="true" aria-describedby="error-' . $nombre . '"' : '';
};
?>
<form id="formulario-demostracion" class="formulario" hx-get="<?= $this->url('/sistema-diseno/demostracion/validacion') ?>" hx-target="this" hx-swap="outerHTML" novalidate>
    <?php if ($errores !== []): ?>
        <div class="alerta alerta-error" data-resumen-errores>
            <?= $this->parcial('parciales/icono', ['nombre' => 'error', 'clase' => 'alerta-icono']) ?>
            <div class="alerta-cuerpo">
                <p class="alerta-titulo">Revisa <?= count($errores) === 1 ? 'un campo' : count($errores) . ' campos' ?></p>
                <ul class="lista-errores">
                    <?php foreach ($errores as $nombre => $texto): ?>
                        <li><a href="#campo-<?= $this->e($nombre) ?>"><?= $this->e($texto) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php elseif (!empty($exito)): ?>
        <div class="alerta alerta-exito"><?= $this->parcial('parciales/icono', ['nombre' => 'ok', 'clase' => 'alerta-icono']) ?><div class="alerta-cuerpo"><p>Valores válidos.</p></div></div>
    <?php endif; ?>
    <div class="campo-grupo">
        <label for="campo-valor">Valor del gasto (COP)</label>
        <input id="campo-valor" class="campo" name="valor" inputmode="decimal" autocomplete="off" value="<?= $this->e($valores['valor'] ?? '') ?>"<?= $campo('valor') ?>>
        <?php if (isset($errores['valor'])): ?><p id="error-valor" class="campo-error"><?= $this->e($errores['valor']) ?></p><?php endif; ?>
    </div>
    <div class="campo-grupo">
        <label for="campo-componente_academico">Componente académico (COP)</label>
        <input id="campo-componente_academico" class="campo" name="componente_academico" inputmode="decimal" autocomplete="off" value="<?= $this->e($valores['componente_academico'] ?? '') ?>"<?= $campo('componente_academico') ?>>
        <p class="campo-ayuda">Parte del gasto atribuible a las vacas de práctica (IND_17).</p>
        <?php if (isset($errores['componente_academico'])): ?><p id="error-componente_academico" class="campo-error"><?= $this->e($errores['componente_academico']) ?></p><?php endif; ?>
    </div>
    <button type="submit" class="boton boton-primario">Validar</button>
</form>
