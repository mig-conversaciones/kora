<?php
/**
 * Plantilla de las pantallas de acceso (ingreso, 2FA, activación, recuperación, reautenticación): tarjeta centrada
 * con la marca, sin menú ni datos de la aplicación. Misma CSP con nonce que la plantilla base (ADR-004).
 * Variables: $titulo, $contenido, $nonce, $csrf, $configHtmx, $entorno, $preferencias, $mantenimientoActivo.
 *
 * @var App\Nucleo\Vista $this
 */
?>
<!doctype html>
<html lang="es-CO" data-tema="<?= $this->e($preferencias['tema'] ?? 'sistema') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= $this->e($titulo) ?> · <?= $this->e($this->nombreApp()) ?></title>
    <meta name="csrf-token" content="<?= $this->e($csrf) ?>">
    <meta name="ruta-base" content="<?= $this->e($this->url('/') === '/' ? '' : rtrim($this->url('/'), '/')) ?>">
    <meta name="sesion-expira-en" content="0">
    <meta name="htmx-config" content="<?= $this->e(json_encode($configHtmx, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) ?>">
    <meta name="theme-color" content="#182b43">
    <link rel="icon" href="<?= $this->url('/favicon.svg') ?>" type="image/svg+xml">
    <link rel="icon" href="<?= $this->url('/favicon-32.png') ?>" sizes="32x32" type="image/png">
    <link rel="manifest" href="<?= $this->url('/site.webmanifest') ?>">
    <link rel="stylesheet" href="<?= $this->asset('css/app.css') ?>">
</head>
<body class="cuerpo-acceso">
    <a class="saltar-contenido" href="#contenido-principal">Ir al contenido</a>
    <?php if ($entorno !== 'produccion'): ?>
        <div class="franja franja-entorno" role="note">Entorno <?= $this->e($entorno) ?> — sin datos institucionales reales</div>
    <?php endif; ?>
    <?php if ($mantenimientoActivo): ?>
        <div class="franja franja-mantenimiento" role="status">Modo mantenimiento activo: solo personal autorizado</div>
    <?php endif; ?>
    <main id="contenido-principal" class="acceso" tabindex="-1">
        <div class="acceso-marca">
            <img class="acceso-logo acceso-logo-claro" src="<?= $this->url('/marca/kora-logo-claro.svg') ?>" alt="KORA · Sistema de gestión productiva" width="257" height="66">
            <img class="acceso-logo acceso-logo-oscuro" src="<?= $this->url('/marca/kora-logo-oscuro.svg') ?>" alt="" width="257" height="66">
        </div>
        <section class="tarjeta acceso-tarjeta" aria-labelledby="titulo-acceso">
            <?= $contenido ?>
        </section>
        <p class="acceso-pie">SENA · Centro Agropecuario de Buga</p>
    </main>
    <div id="contenedor-errores" class="contenedor-errores" role="alert" aria-live="assertive"></div>
    <div id="region-toasts" class="region-toasts" aria-live="polite" aria-relevant="additions"></div>
    <div id="region-toasts-urgentes" class="region-toasts region-toasts-urgentes" aria-live="assertive"></div>
    <div id="anuncio-estado" class="sr-only" role="status" aria-live="polite"></div>
    <template id="plantilla-toast">
        <div class="toast" role="status">
            <span class="toast-indicador" aria-hidden="true"></span>
            <div class="toast-texto"><strong data-toast-titulo></strong><p data-toast-mensaje></p></div>
            <button type="button" class="toast-cerrar" data-toast-cerrar aria-label="Cerrar mensaje">×</button>
        </div>
    </template>
    <script nonce="<?= $this->e($nonce) ?>" src="<?= $this->asset('vendor/htmx.min.js') ?>" defer></script>
    <script nonce="<?= $this->e($nonce) ?>" src="<?= $this->asset('js/retroalimentacion-ui.js') ?>" defer></script>
    <script nonce="<?= $this->e($nonce) ?>" src="<?= $this->asset('js/componentes-alpine.js') ?>" defer></script>
</body>
</html>
