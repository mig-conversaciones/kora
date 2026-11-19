<?php
/**
 * Filtros globales del tablero (RF-062): se envían solos al cambiar un campo, se reflejan en la URL (hx-push-url) y
 * se validan en el servidor. El rango invertido se marca en línea. «Restablecer» vuelve a la vista por defecto.
 * Variables: $ruta, $filtros (FiltrosTablero), $errores, $unidades (o null en el técnico), $productos (o null),
 * $ocultos (parámetros que se conservan, valor => texto ya validado).
 *
 * @var App\Nucleo\Vista $this
 */
$actual = (int) (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y');
$anios = [];
foreach (range($actual + 1, max(2024, $actual - 4)) as $anio) {
    $anios[(string) $anio] = (string) $anio;
}
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
$ocultos ??= [];
$unidades ??= null;
$productos ??= null;
?>
<form class="barra-filtros" action="<?= $this->e($this->url($ruta)) ?>" method="get" hx-get="<?= $this->e($this->url($ruta)) ?>" hx-target="#panel-tablero" hx-swap="outerHTML" hx-push-url="true" hx-trigger="change" aria-label="Filtros del tablero">
    <?php foreach ($ocultos as $nombre => $valor): ?>
        <input type="hidden" name="<?= $this->e($nombre) ?>" value="<?= $this->e($valor) ?>">
    <?php endforeach; ?>
    <?= $campo(['nombre' => 'vigencia', 'etiqueta' => 'Vigencia', 'tipo' => 'select', 'opciones' => $anios, 'valor' => (string) $filtros->vigencia]) ?>
    <?= $campo(['nombre' => 'desde', 'etiqueta' => 'Desde', 'tipo' => 'date', 'valor' => (string) $filtros->desde]) ?>
    <?= $campo(['nombre' => 'hasta', 'etiqueta' => 'Hasta', 'tipo' => 'date', 'valor' => (string) $filtros->hasta]) ?>
    <?php if ($unidades !== null): ?>
        <?php
        $opciones = [];
        foreach ($unidades as $u) {
            $opciones[(string) $u['id']] = $u['nombre'];
        }
        ?>
        <?= $campo(['nombre' => 'unidad', 'etiqueta' => 'Unidad', 'tipo' => 'select', 'opciones' => $opciones, 'valor' => (string) $filtros->unidadId, 'vacio' => 'Todas (de tu alcance)']) ?>
    <?php endif; ?>
    <?php if ($productos !== null): ?>
        <?= $campo(['nombre' => 'producto', 'etiqueta' => 'Producto', 'tipo' => 'select', 'opciones' => $productos, 'valor' => $filtros->producto(), 'vacio' => 'Todos']) ?>
        <?= $campo(['nombre' => 'canal', 'etiqueta' => 'Canal', 'tipo' => 'select', 'opciones' => App\Tableros\FiltrosTablero::CANALES, 'valor' => $filtros->canal]) ?>
    <?php endif; ?>
    <div class="campo-grupo campo-grupo-accion">
        <a class="boton boton-texto" href="<?= $this->e($this->url($ruta) . (isset($ocultos['volver']) ? '?volver=' . rawurlencode((string) $ocultos['volver']) : '')) ?>">Restablecer filtros</a>
    </div>
    <noscript><button type="submit" class="boton">Filtrar</button></noscript>
</form>
