<?php
/**
 * Consulta de la auditoría (RAU-001). Variables: $filtros, $filas, $siguiente, $sellado.
 *
 * @var App\Nucleo\Vista $this
 */
$resultados = App\Servicios\ServicioConsultaAuditoria::RESULTADOS;
$insignia = ['exito' => 'insignia-exito', 'denegado' => 'insignia-error', 'error' => 'insignia-error', 'duplicado' => 'insignia-neutra', 'encolado' => 'insignia-info'];
$json = static function (?string $texto): string {
    if ($texto === null || $texto === '') {
        return '';
    }
    $datos = json_decode($texto, true);
    return is_array($datos) ? (string) json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : $texto;
};
$siguienteUrl = $siguiente === null ? null : $this->url('/sistema/auditoria') . '?' . http_build_query(array_filter($filtros, static fn ($v): bool => $v !== '') + ['antes' => $siguiente]);
?>
<div id="panel-modulo">
    <p class="texto-secundario">Último sello de la cadena: secuencia <strong><?= (int) $sellado['ultima_secuencia'] ?></strong> · Eventos pendientes de sellar: <strong><?= (int) $sellado['pendientes'] ?></strong> (se sellan cada minuto).</p>
    <form class="barra-filtros" role="search" hx-get="<?= $this->e($this->url('/sistema/auditoria')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">
        <?= $this->parcial('parciales/campo', ['nombre' => 'desde', 'etiqueta' => 'Desde (UTC)', 'tipo' => 'date', 'valor' => $filtros['desde']]) ?>
        <?= $this->parcial('parciales/campo', ['nombre' => 'hasta', 'etiqueta' => 'Hasta (UTC)', 'tipo' => 'date', 'valor' => $filtros['hasta']]) ?>
        <?= $this->parcial('parciales/campo', ['nombre' => 'accion', 'etiqueta' => 'Acción (prefijo)', 'valor' => $filtros['accion'], 'atributos' => 'placeholder="gastos., sesion." maxlength="80"']) ?>
        <?= $this->parcial('parciales/campo', ['nombre' => 'resultado', 'etiqueta' => 'Resultado', 'tipo' => 'select', 'opciones' => $resultados, 'vacio' => 'Todos', 'valor' => $filtros['resultado']]) ?>
        <?= $this->parcial('parciales/campo', ['nombre' => 'actor', 'etiqueta' => 'Id del actor', 'tipo' => 'number', 'valor' => $filtros['actor'], 'atributos' => 'min="1"']) ?>
        <button type="submit" class="boton">Filtrar</button>
    </form>
    <section class="tarjeta seccion" aria-labelledby="titulo-auditoria">
        <h2 id="titulo-auditoria" class="titulo-tarjeta">Eventos</h2>
        <?php if ($filas === []): ?>
            <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'Sin eventos en el rango', 'descripcion' => 'Amplía el rango de fechas o quita filtros.', 'accion' => null]) ?>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-auditoria">
                <table class="tabla">
                    <thead><tr><th scope="col">Fecha (UTC)</th><th scope="col">Actor</th><th scope="col">Acción</th><th scope="col">Recurso</th><th scope="col">Resultado</th><th scope="col">Origen</th><th scope="col">Red</th><th scope="col">Detalle</th></tr></thead>
                    <tbody>
                        <?php foreach ($filas as $f): ?>
                            <tr>
                                <td class="numero"><?= $this->e(substr((string) $f['ocurrido_en'], 0, 19)) ?></td>
                                <td><?= $f['actor_id'] === null ? '<span class="texto-secundario">Sistema</span>' : $this->e(($f['actor_nombre'] ?? 'Cuenta ' . $f['actor_id']) . ' · ' . ($f['actor_rol'] ?? '')) ?></td>
                                <td><code><?= $this->e($f['accion']) ?></code></td>
                                <td><?= $this->e(trim(($f['recurso'] ?? '') . ' ' . ($f['recurso_id'] ?? ''))) ?: '—' ?></td>
                                <td><span class="insignia <?= $insignia[$f['resultado']] ?? 'insignia-neutra' ?>"><?= $this->e($resultados[$f['resultado']] ?? $f['resultado']) ?></span></td>
                                <td><?= $this->e($f['origen']) ?></td>
                                <td><?= $this->e($f['ip_tratada'] ?? '—') ?></td>
                                <td>
                                    <?php $detalle = array_filter(['Motivo' => $f['motivo'], 'Antes' => $json($f['valores_antes']), 'Después' => $json($f['valores_despues']), 'Metadatos' => $json($f['metadatos'])], static fn ($v): bool => $v !== null && $v !== ''); ?>
                                    <?php if ($detalle !== []): ?>
                                        <details><summary>Ver</summary>
                                            <?php foreach ($detalle as $titulo => $texto): ?><p class="subtitulo-seccion"><?= $this->e($titulo) ?></p><pre class="bloque-json"><?= $this->e($texto) ?></pre><?php endforeach; ?>
                                            <p class="campo-ayuda"><?= $f['secuencia_sello'] === null ? 'Pendiente de sellar' : 'Sello n.º ' . (int) $f['secuencia_sello'] ?></p>
                                        </details>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($siguienteUrl !== null): ?>
                <nav class="paginacion" aria-label="Paginación"><a class="boton boton-pequeno" href="<?= $this->e($siguienteUrl) ?>" hx-get="<?= $this->e($siguienteUrl) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Eventos más antiguos</a></nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
