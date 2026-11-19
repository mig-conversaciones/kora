<?php
/**
 * Selector de vigencia (año) para los listados financieros. Variables: $ruta, $vigencia, $extra (HTML de otros
 * filtros, armado por la plantilla que lo incluye).
 *
 * @var App\Nucleo\Vista $this
 */
$actual = (int) (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y');
$anios = range($actual + 1, max(2024, $actual - 4));
?>
<form class="barra-filtros" hx-get="<?= $this->e($this->url($ruta)) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true" hx-trigger="change">
    <div class="campo-grupo">
        <label for="campo-vigencia">Vigencia</label>
        <select id="campo-vigencia" class="campo" name="vigencia">
            <?php foreach ($anios as $anio): ?>
                <option value="<?= (int) $anio ?>"<?= (int) $anio === (int) $vigencia ? ' selected' : '' ?>><?= (int) $anio ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?= $extra ?? '' ?>
    <noscript><button type="submit" class="boton">Filtrar</button></noscript>
</form>
