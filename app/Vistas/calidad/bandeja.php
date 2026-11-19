<?php
/**
 * Bandeja de calidad (RF-058, RF-059). Variables: $filas, $hayMas, $conteos, $pagina, $filtros, $tipos, $unidades,
 * $nombresUnidades, $puedeCorregir, $incidenciaConError, $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$opcionesUnidades = [];
foreach ($unidades as $u) {
    $opcionesUnidades[(string) $u['id']] = $u['nombre'];
}
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d);
$campos = $campo(['nombre' => 'tipo', 'etiqueta' => 'Tipo', 'tipo' => 'select', 'opciones' => $tipos, 'valor' => (string) $filtros['tipo'], 'vacio' => 'Todos los tipos'])
    . $campo(['nombre' => 'unidad', 'etiqueta' => 'Unidad', 'tipo' => 'select', 'opciones' => $opcionesUnidades, 'valor' => (string) $filtros['unidad'], 'vacio' => 'Todas mis unidades'])
    . $campo(['nombre' => 'estado', 'etiqueta' => 'Estado', 'tipo' => 'select', 'opciones' => ['abierta' => 'Abiertas', 'resuelta' => 'Resueltas', 'aceptada' => 'Aceptadas sin corrección'], 'valor' => $filtros['estado']]);
$errores ??= [];
?>
<div id="panel-modulo">
    <div class="barra-acciones">
        <?= $this->parcial('parciales/barra_filtros', ['ruta' => '/calidad', 'campos' => $campos]) ?>
        <?php if ($puedeCorregir): ?>
            <form hx-post="<?= $this->e($this->url('/calidad/recalcular')) ?>" hx-target="#estado-recalculo" hx-swap="innerHTML">
                <button type="submit" class="boton">Recalcular la bandeja</button>
            </form>
        <?php endif; ?>
    </div>
    <div id="estado-recalculo" aria-live="polite"></div>
    <ul class="resumen-lotes" aria-label="Incidencias abiertas por tipo">
        <?php foreach ($tipos as $clave => $nombre): ?>
            <?php if (($conteos[$clave] ?? 0) > 0): ?><li><strong><?= (int) $conteos[$clave] ?></strong> <?= $this->e($nombre) ?></li><?php endif; ?>
        <?php endforeach; ?>
    </ul>
    <section class="tarjeta seccion" aria-labelledby="titulo-calidad">
        <h2 id="titulo-calidad" class="titulo-tarjeta">Incidencias <?= $filtros['estado'] === 'abierta' ? 'abiertas' : ($filtros['estado'] === 'resuelta' ? 'resueltas' : 'aceptadas') ?></h2>
        <?php if ($filas === []): ?>
            <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'No hay incidencias con estos filtros', 'descripcion' => 'La bandeja se recalcula tras cada importación. Si corregiste un registro, recalcúlala para verla al día.', 'accion' => null]) ?>
        <?php else: ?>
            <ul class="lista-incidencias">
                <?php foreach ($filas as $i): ?>
                    <?php $conError = $incidenciaConError === (int) $i['id']; ?>
                    <li class="incidencia">
                        <div>
                            <span class="insignia insignia-advertencia"><?= $this->e($tipos[$i['tipo']] ?? $i['tipo']) ?></span>
                            <?php if ($i['unidad_id'] !== null): ?><span class="texto-secundario"><?= $this->e($nombresUnidades[(string) $i['unidad_id']] ?? '') ?></span><?php endif; ?>
                            <p><?= $this->e($i['descripcion']) ?></p>
                            <?php if ($i['resolucion'] !== null): ?><p class="texto-secundario">Resolución: <?= $this->e($i['resolucion']) ?></p><?php endif; ?>
                        </div>
                        <?php if ($puedeCorregir && $i['estado'] === 'abierta'): ?>
                            <details<?= $conError ? ' open' : '' ?>>
                                <summary>Corregir</summary>
                                <?php if ($conError): ?><?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?><?php endif; ?>
                                <?php if ($i['tipo'] === 'salida_sin_recepcion'): ?>
                                    <form class="formulario" hx-post="<?= $this->e($this->url('/calidad/' . (int) $i['id'] . '/vincular-recepcion')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                                        <?= $this->parcial('parciales/campo', ['nombre' => 'recepcion_id', 'etiqueta' => 'Id del lote (recepción)', 'tipo' => 'number', 'errores' => $conError ? $errores : [], 'ayuda' => 'Se valida la unidad, la medida, la fecha y el saldo del lote.', 'atributos' => 'min="1" required']) ?>
                                        <button type="submit" class="boton boton-pequeno">Vincular al lote</button>
                                    </form>
                                <?php elseif ($i['tipo'] === 'autoconsumo_sin_proceso'): ?>
                                    <form class="formulario" hx-post="<?= $this->e($this->url('/calidad/' . (int) $i['id'] . '/asignar-proceso')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                                        <?= $this->parcial('parciales/campo', ['nombre' => 'proceso_id', 'etiqueta' => 'Id del proceso de autoconsumo', 'tipo' => 'number', 'errores' => $conError ? $errores : [], 'ayuda' => 'Debe coincidir en unidad, medida, vigencia y producto.', 'atributos' => 'min="1" required']) ?>
                                        <button type="submit" class="boton boton-pequeno">Asignar proceso</button>
                                    </form>
                                <?php elseif ($i['tipo'] === 'concepto_sin_homologar'): ?>
                                    <p><a href="<?= $this->e($this->url('/catalogos/homologaciones')) ?>">Homologar el concepto en el catálogo</a></p>
                                <?php elseif ($i['tipo'] === 'derivado_sin_factor'): ?>
                                    <p><a href="<?= $this->e($this->url('/catalogos/productos?derivados=1')) ?>">Configurar el factor del derivado</a></p>
                                <?php endif; ?>
                                <form class="formulario" hx-post="<?= $this->e($this->url('/calidad/' . (int) $i['id'] . '/aceptar')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                                    <?= $this->parcial('parciales/campo', ['nombre' => 'motivo', 'etiqueta' => 'Aceptar sin corrección: motivo', 'tipo' => 'textarea', 'errores' => $conError ? $errores : [], 'atributos' => 'maxlength="500"']) ?>
                                    <button type="submit" class="boton boton-pequeno boton-texto">Aceptar sin corrección</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?= $this->parcial('parciales/paginacion', ['ruta' => '/calidad', 'consulta' => $filtros, 'pagina' => $pagina, 'hayMas' => $hayMas, 'objetivo' => '#panel-modulo']) ?>
        <?php endif; ?>
    </section>
</div>
