<?php
/**
 * Reporte PDF de un tablero (RF-081), renderizado por dompdf en el trabajador. No se sirve al navegador, así que su
 * hoja de estilos interna no choca con la CSP. Incluye la fecha de generación, los filtros, el usuario, el corte y las
 * notas metodológicas, con las mismas cifras y formatos de la pantalla (App\Tableros\Formato).
 * Variables: $titulo, $usuario, $generado, $filtros, $notas, $kpi, $tablas, $nombreApp.
 *
 * @var App\Nucleo\Vista $this
 */
$celda = function (mixed $valor): string {
    if (is_float($valor)) {
        return $this->e(number_format($valor, abs($valor - round($valor)) < 1e-9 ? 0 : 2, ',', '.'));
    }
    if (is_int($valor)) {
        return $this->e(number_format($valor, 0, ',', '.'));
    }
    return $this->e($valor ?? '—');
};
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?= $this->e($nombreApp . ' · ' . $titulo) ?></title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1c2733; }
    h1 { font-size: 15pt; margin: 0 0 2pt; color: #182b43; }
    h2 { font-size: 11pt; margin: 14pt 0 4pt; color: #182b43; }
    .meta { color: #4d5d6c; margin: 0 0 8pt; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 6pt; }
    th, td { border-bottom: 0.5pt solid #c9d3dc; padding: 3pt 4pt; text-align: left; vertical-align: top; }
    th { background: #eef2f5; font-weight: bold; }
    td.n { text-align: right; }
    .kpi td:first-child { width: 40%; font-weight: bold; }
    .notas li { margin-bottom: 2pt; }
    .pie { margin-top: 10pt; color: #4d5d6c; font-size: 8pt; }
</style>
</head>
<body>
<h1><?= $this->e($titulo) ?></h1>
<p class="meta"><?= $this->e($nombreApp) ?> · Generado el <?= $this->e($generado) ?> (hora de Colombia) por <?= $this->e($usuario) ?></p>

<h2>Filtros</h2>
<table>
    <?php foreach ($filtros as [$nombre, $valor]): ?>
        <tr><th><?= $this->e($nombre) ?></th><td><?= $this->e($valor) ?></td></tr>
    <?php endforeach; ?>
</table>

<h2>Indicadores</h2>
<table class="kpi">
    <?php foreach ($kpi as [$nombre, $valor]): ?>
        <tr><td><?= $this->e($nombre) ?></td><td><?= $this->e($valor) ?></td></tr>
    <?php endforeach; ?>
</table>

<?php foreach ($tablas as $tabla): ?>
    <h2><?= $this->e($tabla['titulo']) ?></h2>
    <?php if ($tabla['filas'] === []): ?>
        <p class="meta">Sin datos con estos filtros.</p>
    <?php else: ?>
        <table>
            <thead><tr><?php foreach ($tabla['columnas'] as $columna): ?><th><?= $this->e($columna) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($tabla['filas'] as $fila): ?>
                <tr><?php foreach ($fila as $valor): ?><td<?= is_int($valor) || is_float($valor) ? ' class="n"' : '' ?>><?= $celda($valor) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endforeach; ?>

<h2>Notas metodológicas</h2>
<ul class="notas">
    <?php foreach ($notas as $nota): ?>
        <li><?= $this->e($nota) ?></li>
    <?php endforeach; ?>
</ul>
<p class="pie">Cifras calculadas con el alcance del usuario al momento de generar el reporte. Documento de uso interno del centro.</p>
</body>
</html>
