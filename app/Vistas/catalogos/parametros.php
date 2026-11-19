<?php
/**
 * Parámetros de los indicadores con historial (RF-021). Variables: $vigentes, $historial, $puedeEditar, $valores,
 * $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$definiciones = App\Servicios\ServicioParametros::DEFINICIONES;
$opciones = array_map(static fn (array $d): string => $d[0] . ' (' . $d[1] . ')', $definiciones);
$mostrar = function (?string $valor, string $clave) use ($definiciones): string {
    if ($valor === null) {
        return '—';
    }
    [, $unidad, $tipo] = $definiciones[$clave];
    return match ($tipo) {
        'precio' => $this->moneda($valor) . ' /L',
        'dias' => (int) $valor . ' días',
        default => $this->decimal($valor, 2) . ' %',
    };
};
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
$hoy = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d');
?>
<div id="panel-modulo">
    <div class="rejilla-kpi seccion-sin-tarjeta">
        <?php foreach ($vigentes as $clave => $p): ?>
            <article class="tarjeta tarjeta-kpi">
                <h2 class="kpi-etiqueta"><?= $this->e($definiciones[$clave][0]) ?></h2>
                <p class="kpi-valor"><?= $this->e($mostrar($p['valor'], $clave)) ?></p>
                <p class="kpi-nota"><?= $p['vigente_desde'] === null ? 'Sin valor vigente' : 'Vigente desde ' . $this->e($this->fecha($p['vigente_desde'])) ?></p>
            </article>
        <?php endforeach; ?>
    </div>
    <div class="rejilla-dos">
        <?php if ($puedeEditar): ?>
            <section class="tarjeta seccion" aria-labelledby="titulo-nuevo-parametro">
                <h2 id="titulo-nuevo-parametro" class="titulo-tarjeta">Registrar un valor nuevo</h2>
                <form class="formulario" hx-post="<?= $this->e($this->url('/catalogos/parametros')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
                    <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
                    <?= $campo(['nombre' => 'clave', 'etiqueta' => 'Parámetro', 'tipo' => 'select', 'opciones' => $opciones, 'vacio' => 'Elige…', 'valor' => $valores['clave'] ?? '']) ?>
                    <?= $campo(['nombre' => 'valor', 'etiqueta' => 'Valor', 'valor' => $valores['valor'] ?? '', 'ayuda' => 'Porcentajes entre 0 y 100; precio mayor que 0; rotaciones en días enteros.', 'atributos' => 'inputmode="decimal" required']) ?>
                    <?= $campo(['nombre' => 'vigente_desde', 'etiqueta' => 'Vigente desde', 'tipo' => 'date', 'valor' => $valores['vigente_desde'] ?? $hoy, 'atributos' => 'required']) ?>
                    <button type="submit" class="boton boton-primario">Registrar valor</button>
                </form>
            </section>
        <?php endif; ?>
        <section class="tarjeta seccion" aria-labelledby="titulo-historial-parametros">
            <h2 id="titulo-historial-parametros" class="titulo-tarjeta">Historial</h2>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-historial-parametros">
                <table class="tabla">
                    <thead><tr><th scope="col">Parámetro</th><th scope="col" class="numero">Valor</th><th scope="col">Vigente desde</th><th scope="col">Registrado por</th></tr></thead>
                    <tbody>
                        <?php foreach ($historial as $h): ?>
                            <tr>
                                <td><?= $this->e($definiciones[$h['clave']][0] ?? $h['clave']) ?></td>
                                <td class="numero"><?= $this->e($mostrar((string) $h['valor'], (string) $h['clave'])) ?></td>
                                <td><?= $this->e($this->fecha($h['vigente_desde'])) ?></td>
                                <td><?= $this->e($h['creado_por_nombre'] ?? 'Semilla inicial') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
