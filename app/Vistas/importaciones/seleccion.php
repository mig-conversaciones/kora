<?php
/**
 * Fuentes reconocidas en el libro (RF-083): solo se eligen las que el rol puede importar. Variables: $token, $nombre,
 * $detectadas, $unidadesHato, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$errores ??= [];
$opcionesHato = [];
foreach ($unidadesHato as $u) {
    $opcionesHato[(string) $u['id']] = $u['nombre'];
}
$pideHato = false;
foreach ($detectadas as $d) {
    $pideHato = $pideHato || ($d['permitida'] && in_array('unidad_hato', $d['opciones'], true));
}
?>
<div id="panel-modulo">
    <section class="tarjeta seccion" aria-labelledby="titulo-seleccion">
        <h2 id="titulo-seleccion" class="titulo-tarjeta">Fuentes reconocidas en «<?= $this->e($nombre) ?>»</h2>
        <form class="formulario" hx-post="<?= $this->e($this->url('/importaciones')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
            <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
            <input type="hidden" name="token" value="<?= $this->e($token) ?>">
            <fieldset class="grupo-campos">
                <legend>Elige qué importar</legend>
                <?php foreach ($detectadas as $d): ?>
                    <div class="campo-grupo">
                        <label class="casilla">
                            <input type="checkbox" name="fuentes[]" value="<?= $this->e($d['fuente']) ?>"<?= $d['permitida'] ? '' : ' disabled' ?><?= $d['permitida'] && $d['ya_aplicado'] === null ? ' checked' : '' ?>>
                            <?= $this->e($d['nombre']) ?> <span class="texto-secundario">— hoja «<?= $this->e($d['hoja']) ?>», <?= (int) $d['filas'] ?> filas</span>
                        </label>
                        <?php if (!$d['permitida']): ?><p class="campo-ayuda">Tu rol no importa esta fuente.</p><?php endif; ?>
                        <?php if ($d['ya_aplicado'] !== null): ?><p class="campo-ayuda">Este mismo archivo ya se aplicó para esta fuente: <a href="<?= $this->e($this->url('/importaciones/' . $d['ya_aplicado'])) ?>">ver ese lote</a>.</p><?php endif; ?>
                        <?php if ($d['permitida'] && in_array('vigencia', $d['opciones'], true)): ?>
                            <?= $this->parcial('parciales/campo', ['nombre' => 'vigencia_' . $d['fuente'], 'etiqueta' => 'Vigencia', 'tipo' => 'number', 'errores' => $errores, 'valor' => (string) ($d['vigencia_sugerida'] ?? date('Y')), 'atributos' => 'min="2020" max="2100"']) ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </fieldset>
            <?php if ($pideHato): ?>
                <fieldset class="grupo-campos">
                    <legend>Hato</legend>
                    <div class="rejilla-campos">
                        <?= $this->parcial('parciales/campo', ['nombre' => 'unidad_hato', 'etiqueta' => 'Unidad del hato', 'tipo' => 'select', 'opciones' => $opcionesHato, 'errores' => $errores, 'valor' => (string) ($unidadesHato[0]['id'] ?? '')]) ?>
                        <?= $this->parcial('parciales/campo', ['nombre' => 'fecha_corte', 'etiqueta' => 'Fecha del corte', 'tipo' => 'date', 'errores' => $errores, 'ayuda' => 'Si la dejas vacía se toma la columna «Fecha actual», que debe ser la misma en todo el archivo.']) ?>
                    </div>
                </fieldset>
            <?php endif; ?>
            <p class="campo-ayuda">Cada fuente queda como un lote que se valida en segundo plano. Nada se aplica hasta que revises la vista previa y confirmes.</p>
            <div class="grupo-botones">
                <button type="submit" class="boton boton-primario">Validar las fuentes elegidas</button>
                <a class="boton boton-texto" href="<?= $this->e($this->url('/importaciones')) ?>">Cancelar</a>
            </div>
        </form>
    </section>
</div>
