<?php
/**
 * Plantilla base (RI-002, opción 2): barra lateral comprimible, barra superior y contenido (04-03, 4.3.2).
 * Variables: $titulo, $contenido, $nonce, $csrf, $configHtmx, $menu, $rutaActual, $migas, $usuario, $preferencias,
 * $entorno, $sesionExpiraEn, $mantenimientoActivo. $this es App\Nucleo\Vista (e() escapa, asset() resuelve la
 * huella, url() antepone la ruta base). Sin estilos ni scripts en línea: todo lleva nonce y defer (ADR-004).
 *
 * @var App\Nucleo\Vista $this
 */
?>
<!doctype html>
<html lang="es-CO" data-tema="<?= $this->e($preferencias['tema'] ?? 'sistema') ?>" data-densidad="<?= $this->e($preferencias['densidad'] ?? 'comoda') ?>" data-menu="<?= $this->e($preferencias['menu'] ?? 'expandido') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($titulo) ?> · <?= $this->e($this->nombreApp()) ?></title>
    <meta name="description" content="KORA, sistema de gestión productiva del Centro Agropecuario de Buga: producción, trazabilidad y tableros de mando.">
    <meta name="csrf-token" content="<?= $this->e($csrf) ?>">
    <meta name="ruta-base" content="<?= $this->e($this->url('/') === '/' ? '' : rtrim($this->url('/'), '/')) ?>">
    <meta name="sesion-expira-en" content="<?= (int) $sesionExpiraEn ?>">
    <meta name="htmx-config" content="<?= $this->e(json_encode($configHtmx, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) ?>">
    <meta name="theme-color" content="#182b43">
    <link rel="icon" href="<?= $this->url('/favicon.svg') ?>" type="image/svg+xml">
    <link rel="icon" href="<?= $this->url('/favicon-32.png') ?>" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="<?= $this->url('/apple-touch-icon.png') ?>">
    <link rel="manifest" href="<?= $this->url('/site.webmanifest') ?>">
    <link rel="preload" href="<?= $this->url('/assets/fuentes/inter-latin-400-normal.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= $this->asset('css/app.css') ?>">
</head>
<body hx-ext="morph">
    <a class="saltar-contenido" href="#contenido-principal">Ir al contenido</a>
    <?php if ($entorno !== 'produccion'): ?>
        <div class="franja franja-entorno" role="note">Entorno <?= $this->e($entorno) ?> — sin datos institucionales reales</div>
    <?php endif; ?>
    <?php if ($mantenimientoActivo): ?>
        <div class="franja franja-mantenimiento" role="status">Modo mantenimiento activo: solo personal autorizado</div>
    <?php endif; ?>

    <div class="contenedor" x-data="barraLateral" x-bind:class="clasesContenedor">
        <aside id="barra-lateral" class="barra-lateral" aria-label="Navegación principal">
            <?= $this->parcial('parciales/menu', ['menu' => $menu, 'rutaActual' => $rutaActual]) ?>
            <button type="button" class="boton-colapsar" x-on:click="alternar" x-bind:aria-expanded="expandida" aria-controls="barra-lateral">
                <?= $this->parcial('parciales/icono', ['nombre' => 'colapsar']) ?>
                <span class="sr-only">Contraer o expandir el menú</span>
            </button>
        </aside>
        <div class="fondo-menu-movil" x-on:click="cerrarMovil" aria-hidden="true"></div>
        <div class="area-trabajo" x-bind:inert="fondoInerte">
            <header class="barra-superior">
                <button type="button" class="boton-menu-movil boton boton-icono" x-on:click="alternarMovil" aria-controls="barra-lateral" x-bind:aria-expanded="abiertaMovil">
                    <?= $this->parcial('parciales/icono', ['nombre' => 'menu']) ?>
                    <span class="sr-only">Abrir el menú</span>
                </button>
                <?= $this->parcial('parciales/migas', ['titulo' => $titulo, 'migas' => $migas]) ?>
                <div class="barra-superior-acciones">
                    <?= $this->parcial('parciales/selector_unidad', ['usuario' => $usuario]) ?>
                    <?= $this->parcial('parciales/notificaciones', ['usuario' => $usuario]) ?>
                    <?= $this->parcial('parciales/perfil', ['usuario' => $usuario]) ?>
                </div>
            </header>
            <div id="contenedor-errores" class="contenedor-errores" role="alert" aria-live="assertive"></div>
            <main id="contenido-principal" class="contenido" tabindex="-1">
                <?= $contenido ?>
            </main>
            <footer class="pie">
                <span>KORA · Centro Agropecuario de Buga</span>
                <span>Proyecto Producción CAB · versión <?= $this->e(trim((string) @file_get_contents(RAIZ . '/VERSION')) ?: 'en desarrollo') ?></span>
            </footer>
        </div>
    </div>

    <div id="region-toasts" class="region-toasts" aria-live="polite" aria-relevant="additions"></div>
    <div id="region-toasts-urgentes" class="region-toasts region-toasts-urgentes" aria-live="assertive"></div>
    <div id="anuncio-estado" class="sr-only" role="status" aria-live="polite"></div>

    <dialog id="dialogo-confirmacion" class="dialogo" aria-labelledby="dialogo-titulo" aria-describedby="dialogo-mensaje">
        <h2 id="dialogo-titulo" class="dialogo-titulo" data-dialogo-titulo></h2>
        <p id="dialogo-mensaje" class="dialogo-mensaje" data-dialogo-mensaje></p>
        <div class="dialogo-acciones">
            <button type="button" class="boton" data-dialogo-cancelar>Cancelar</button>
            <button type="button" class="boton boton-primario" data-dialogo-aceptar>Confirmar</button>
        </div>
    </dialog>

    <template id="plantilla-toast">
        <div class="toast" role="status">
            <span class="toast-indicador" aria-hidden="true"></span>
            <div class="toast-texto"><strong data-toast-titulo></strong><p data-toast-mensaje></p></div>
            <button type="button" class="toast-cerrar" data-toast-cerrar aria-label="Cerrar mensaje">×</button>
        </div>
    </template>
    <?= $this->parcial('parciales/esqueletos') ?>

    <script nonce="<?= $this->e($nonce) ?>" src="<?= $this->asset('vendor/htmx.min.js') ?>" defer></script>
    <script nonce="<?= $this->e($nonce) ?>" src="<?= $this->asset('vendor/idiomorph-ext.min.js') ?>" defer></script>
    <script nonce="<?= $this->e($nonce) ?>" src="<?= $this->asset('js/retroalimentacion-ui.js') ?>" defer></script>
    <script nonce="<?= $this->e($nonce) ?>" src="<?= $this->asset('js/componentes-alpine.js') ?>" defer></script>
    <script nonce="<?= $this->e($nonce) ?>" src="<?= $this->asset('vendor/alpine-csp.min.js') ?>" defer></script>
</body>
</html>
