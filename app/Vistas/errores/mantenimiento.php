<?php
/**
 * Aviso público de mantenimiento (RF-MAN-018). No depende de la base de datos ni de la sesión; usa solo recursos
 * estáticos exceptuados. No muestra información interna (motivo, versión, rutas). Variables: $titulo, $mensaje,
 * $estimado (ISO 8601 o null), $soloFragmento (respuesta HTMX) y $rutaBase (ruta base, ver App\Nucleo\Url).
 */
$escapar = static fn (?string $texto): string => htmlspecialchars((string) $texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$estimadoTexto = null;
if (!empty($estimado)) {
    try {
        $fecha = (new DateTimeImmutable((string) $estimado))->setTimezone(new DateTimeZone('America/Bogota'));
        $estimadoTexto = $fecha > new DateTimeImmutable() ? $fecha->format('d/m/Y \a \l\a\s H:i') : null;
    } catch (Exception) {
        $estimadoTexto = null;
    }
}
?>
<?php if (empty($soloFragmento)): ?>
<!doctype html>
<html lang="es-CO">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= $escapar($titulo) ?> · KORA</title>
    <link rel="icon" href="<?= $escapar($rutaBase) ?>/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $escapar($rutaBase) ?>/assets/mantenimiento/aviso.css">
</head>
<body>
<?php endif; ?>
<main class="aviso-mantenimiento">
    <section class="aviso-tarjeta" role="status" aria-live="polite" aria-labelledby="aviso-titulo">
        <img src="<?= $escapar($rutaBase) ?>/favicon.svg" alt="" width="56" height="56">
        <h1 id="aviso-titulo"><?= $escapar($titulo) ?></h1>
        <p><?= nl2br($escapar($mensaje)) ?></p>
        <?php if ($estimadoTexto !== null): ?>
            <p class="aviso-estimado">Regreso estimado: <strong><?= $escapar($estimadoTexto) ?></strong> (hora de Colombia).</p>
        <?php else: ?>
            <p class="aviso-estimado">Estamos terminando; gracias por tu paciencia.</p>
        <?php endif; ?>
        <p class="aviso-ayuda">Si necesitas atención urgente, comunícate con la coordinación del centro.</p>
    </section>
</main>
<?php if (empty($soloFragmento)): ?>
</body>
</html>
<?php endif; ?>
