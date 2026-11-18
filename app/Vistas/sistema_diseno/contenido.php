<?php
/**
 * Vitrina del sistema de diseño (hito M1; RUI-001 a RUI-009; docs/ui/sistema-diseno.md). Todos los estados se
 * declaran con atributos data-* que interpreta retroalimentacion-ui.js: no hay JavaScript ni estilos en línea.
 *
 * @var App\Nucleo\Vista $this
 */
$demo = fn (string $caso): string => $this->url('/sistema-diseno/demostracion/' . $caso);
$grupos = [
    'Marca e interfaz' => [
        ['primario', 'Azul pizarra', '#182B43', 'Marca, menú lateral y encabezados'],
        ['acento', 'Turquesa KORA', '#20BFAE', 'Acento puntual sobre azul oscuro; no para texto sobre claro'],
        ['accion', 'Turquesa oscuro', '#087F74', 'Botones, enlaces y controles activos'],
        ['acento-suave', 'Turquesa pálido', '#E1F6F3', 'Selección suave y fondos de énfasis'],
        ['fondo', 'Canvas', '#F6F8FA', 'Fondo general'],
        ['superficie-alt', 'Superficie alterna', '#EAF0F3', 'Secciones secundarias'],
        ['texto', 'Azul tinta', '#162538', 'Texto principal'],
        ['texto-secundario', 'Gris pizarra', '#526477', 'Texto secundario y metadatos'],
        ['linea', 'Gris frío', '#D6E0E6', 'Divisores decorativos'],
        ['borde-control', 'Gris pizarra medio', '#7D8FA0', 'Límite de controles operables'],
    ],
    'Estados (siempre con texto o icono)' => [
        ['exito', 'Correcto', '#0A766D', 'Proceso completado'],
        ['advertencia', 'Atención', '#9A5C0A', 'Inventario bajo'],
        ['error', 'Error', '#B33D48', 'Operación fallida'],
        ['info', 'Información', '#2166A3', 'Aviso del sistema'],
    ],
    'Series de datos de los tableros' => [
        ['serie-venta', 'Venta directa', '#15803D', 'Guía de tableros; con leyenda'],
        ['serie-autoconsumo', 'Autoconsumo', '#D97706', 'Guía de tableros; con leyenda'],
        ['serie-presupuesto', 'Presupuesto', '#182B43', 'Azul pizarra de la marca'],
        ['serie-asignacion', 'Asignación', '#7D8FA0', 'Barra de asignación de IND_001'],
    ],
];
?>
<header class="encabezado-pagina">
    <div>
        <p class="sobretitulo">Hito M1 · Arquitectura y sistema de diseño</p>
        <h1 class="titulo-pagina">Sistema de diseño de KORA</h1>
        <p class="texto-secundario">Skeuomorfismo funcional sutil: gradientes del 2 al 4 %, campos con relieve interior y botones con estado presionado. Los <em>tokens</em> separan estructura, identidad y superficie, así que el estilo se cambia sustituyendo un solo bloque.</p>
    </div>
    <div class="selector-tema" x-data="selectorTema" role="group" aria-label="Tema de color">
        <button type="button" class="boton boton-pequeno" x-on:click="usarClaro" x-bind:aria-pressed="esClaro">Claro</button>
        <button type="button" class="boton boton-pequeno" x-on:click="usarOscuro" x-bind:aria-pressed="esOscuro">Oscuro</button>
        <button type="button" class="boton boton-pequeno" x-on:click="usarSistema" x-bind:aria-pressed="esSistema">Sistema</button>
    </div>
</header>

<section class="tarjeta seccion" aria-labelledby="titulo-marca">
    <h2 id="titulo-marca" class="titulo-tarjeta">Marca</h2>
    <p class="texto-secundario">Kit de identidad KORA (<code>referencias/KORA_kit_identidad</code>), preparado para la web con <code>npm run marca</code>. El logo para fondos oscuros va sobre el azul pizarra; el claro, sobre blanco o canvas.</p>
    <div class="rejilla-marca">
        <figure class="pieza-marca pieza-marca-clara"><img src="<?= $this->url('/marca/kora-logo-claro.svg') ?>" alt="Logo horizontal de KORA para fondos claros" width="257" height="66"><figcaption>Horizontal claro</figcaption></figure>
        <figure class="pieza-marca pieza-marca-oscura"><img src="<?= $this->url('/marca/kora-logo-oscuro.svg') ?>" alt="Logo horizontal de KORA para fondos oscuros" width="257" height="66"><figcaption>Horizontal oscuro</figcaption></figure>
        <figure class="pieza-marca pieza-marca-clara"><img src="<?= $this->url('/marca/kora-logo-vertical.svg') ?>" alt="Logo vertical de KORA" width="110" height="102"><figcaption>Vertical</figcaption></figure>
        <figure class="pieza-marca pieza-marca-clara"><img src="<?= $this->url('/marca/kora-logo-monocromatico.svg') ?>" alt="Logo monocromático de KORA" width="257" height="66"><figcaption>Monocromático</figcaption></figure>
        <figure class="pieza-marca pieza-marca-clara"><img src="<?= $this->url('/favicon.svg') ?>" alt="Ícono de KORA" width="64" height="64"><figcaption>Favicon e ícono</figcaption></figure>
    </div>
</section>

<section class="tarjeta seccion" aria-labelledby="titulo-color">
    <h2 id="titulo-color" class="titulo-tarjeta">Paleta del sistema</h2>
    <p class="texto-secundario">Contraste AA verificado con <code>npm run contraste</code> (RUI-003). El turquesa brillante se usa de forma puntual; los botones con texto blanco usan el turquesa oscuro. Los códigos hex son los del modo claro; las muestras siguen el tema activo.</p>
    <?php foreach ($grupos as $grupo => $colores): ?>
        <h3 class="subtitulo-seccion"><?= $this->e($grupo) ?></h3>
        <ul class="rejilla-muestras">
            <?php foreach ($colores as [$token, $nombre, $hex, $uso]): ?>
                <li class="muestra">
                    <span class="muestra-color muestra-<?= $this->e($token) ?>" aria-hidden="true"></span>
                    <span class="muestra-nombre"><?= $this->e($nombre) ?></span>
                    <code class="muestra-token">--<?= str_starts_with($token, 'serie-') ? '' : 'color-' ?><?= $this->e($token) ?></code>
                    <span class="muestra-uso"><?= $this->e($uso) ?> · <?= $this->e($hex) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</section>

<div class="rejilla-dos">
    <section class="tarjeta seccion" aria-labelledby="titulo-tipografia">
        <h2 id="titulo-tipografia" class="titulo-tarjeta">Tipografía</h2>
        <p class="texto-secundario">Inter autoalojada (latín con tildes y ñ), cifras tabulares en indicadores y tablas (RUI-004).</p>
        <div class="escala-tipografica">
            <p class="tamano-kpi cifras">$ 185.000.000</p>
            <p class="tamano-2xl">Título de página · 1,75 rem</p>
            <p class="tamano-xl">Título de sección · 1,375 rem</p>
            <p class="tamano-lg">Subtítulo · 1,125 rem</p>
            <p>Texto base · 1 rem. Ganadería registró 412,5 L de leche cruda.</p>
            <p class="tamano-sm">Texto pequeño · 0,875 rem</p>
            <p class="tamano-xs texto-secundario">Nota · 0,75 rem</p>
        </div>
    </section>

    <section class="tarjeta seccion" aria-labelledby="titulo-botones">
        <h2 id="titulo-botones" class="titulo-tarjeta">Botones</h2>
        <p class="texto-secundario">Altura mínima de 44 px, foco visible y estado presionado reconocible.</p>
        <div class="grupo-botones">
            <button type="button" class="boton boton-primario">Guardar</button>
            <button type="button" class="boton">Cancelar</button>
            <button type="button" class="boton boton-peligro">Archivar</button>
            <button type="button" class="boton boton-texto">Ver detalle</button>
            <button type="button" class="boton" disabled>Deshabilitado</button>
            <button type="button" class="boton boton-icono" aria-label="Ajustes"><?= $this->parcial('parciales/icono', ['nombre' => 'ajustes']) ?></button>
        </div>
        <h3 class="subtitulo-seccion">Insignias y semáforos</h3>
        <div class="grupo-botones">
            <span class="insignia insignia-exito">En meta</span>
            <span class="insignia insignia-advertencia">En riesgo</span>
            <span class="insignia insignia-error">Crítico</span>
            <span class="insignia insignia-info">En curso</span>
            <span class="insignia insignia-neutra">Sin dato</span>
        </div>
    </section>
</div>

<section class="seccion-sin-tarjeta" aria-labelledby="titulo-kpi">
    <h2 id="titulo-kpi" class="titulo-tarjeta">Tarjetas de indicador</h2>
    <div class="rejilla-kpi">
        <article class="tarjeta tarjeta-kpi kpi-venta">
            <h3 class="kpi-etiqueta">Venta directa</h3>
            <p class="kpi-valor">$ 3.265.000</p>
            <p class="kpi-nota">61 recaudos de Ganadería · datos de demostración</p>
        </article>
        <article class="tarjeta tarjeta-kpi kpi-autoconsumo">
            <h3 class="kpi-etiqueta">Autoconsumo</h3>
            <p class="kpi-valor">17 entregas</p>
            <p class="kpi-nota">Pendientes de vincular a su proceso</p>
        </article>
        <article class="tarjeta tarjeta-kpi kpi-presupuesto">
            <h3 class="kpi-etiqueta">Presupuesto asignado</h3>
            <p class="kpi-valor">$ 185.000.000</p>
            <p class="kpi-nota">Vigencia 2026 · presupuesto, no gasto ejecutado</p>
        </article>
    </div>
</section>

<section class="tarjeta seccion" aria-labelledby="titulo-retro">
    <h2 id="titulo-retro" class="titulo-tarjeta">Retroalimentación al usuario</h2>
    <p class="texto-secundario">Cada botón hace una solicitud real al servidor. El mensaje llega por <code>HX-Trigger</code>; los errores muestran el código de soporte (<code>X-Request-ID</code>).</p>

    <h3 class="subtitulo-seccion">Mensajes emergentes</h3>
    <div class="grupo-botones">
        <button type="button" class="boton" hx-get="<?= $this->e($demo('toast-exito')) ?>" hx-swap="none">Éxito</button>
        <button type="button" class="boton" hx-get="<?= $this->e($demo('toast-info')) ?>" hx-swap="none">Información</button>
        <button type="button" class="boton" hx-get="<?= $this->e($demo('toast-advertencia')) ?>" hx-swap="none">Advertencia</button>
        <button type="button" class="boton" hx-get="<?= $this->e($demo('toast-error')) ?>" hx-swap="none">Error (persistente)</button>
    </div>

    <h3 class="subtitulo-seccion">Confirmación y errores controlados</h3>
    <div class="grupo-botones">
        <button type="button" class="boton boton-peligro" hx-get="<?= $this->e($demo('archivar')) ?>" hx-swap="none"
                data-confirmar="El registro de demostración dejará de contar en los indicadores. Podrás restaurarlo después."
                data-confirmar-titulo="Archivar registro" data-confirmar-accion="Archivar" data-peligro>
            <?= $this->parcial('parciales/icono', ['nombre' => 'archivar']) ?> Archivar con confirmación
        </button>
        <button type="button" class="boton" hx-get="<?= $this->e($demo('conflicto')) ?>" hx-target="#contenedor-errores">Conflicto de edición (409)</button>
        <button type="button" class="boton" hx-get="<?= $this->e($demo('error')) ?>">Error del servidor (500)</button>
        <button type="button" class="boton" hx-get="<?= $this->e($demo('no-existe')) ?>">Recurso inexistente (404)</button>
    </div>

    <div class="rejilla-dos">
        <div>
            <h3 class="subtitulo-seccion">Carga con esqueleto</h3>
            <button type="button" class="boton" hx-get="<?= $this->e($demo('lento')) ?>" hx-target="#demo-tabla" data-esqueleto="#esqueleto-tabla">Cargar lotes (1,2 s)</button>
            <div id="demo-tabla" class="contenedor-tabla demo-zona" tabindex="0">
                <p class="texto-secundario">Pulsa el botón: el esqueleto aparece si la espera supera 300 ms.</p>
            </div>
        </div>
        <div>
            <h3 class="subtitulo-seccion">Estado vacío</h3>
            <button type="button" class="boton" hx-get="<?= $this->e($demo('vacio')) ?>" hx-target="#demo-vacio" data-estado-vacio="#plantilla-vacio-demo">Buscar gastos de diciembre</button>
            <div id="demo-vacio" class="demo-zona">
                <p class="texto-secundario">La respuesta 204 se reemplaza por la plantilla explicativa.</p>
            </div>
            <template id="plantilla-vacio-demo">
                <div class="estado-vacio">
                    <h3>No hay gastos para estos filtros</h3>
                    <p>Cambia los filtros o registra un gasto ejecutado de tu unidad.</p>
                </div>
            </template>
        </div>
    </div>
</section>

<?php if (!empty($demostracionCola)): ?>
<section class="tarjeta seccion" aria-labelledby="titulo-cola">
    <h2 id="titulo-cola" class="titulo-tarjeta">Trabajos en segundo plano</h2>
    <p class="texto-secundario">La solicitud responde <code>202 Accepted</code> en cuanto el trabajo queda guardado en la cola durable; el fragmento consulta su estado con espera creciente (2, 3, 5, 8 y 13 s) y se detiene al terminar. Solo fuera de producción.</p>
    <form class="grupo-botones" hx-post="<?= $this->e($this->url('/sistema-diseno/demostracion/trabajo')) ?>" hx-target="#demo-trabajos" hx-swap="afterbegin">
        <input type="hidden" name="pasos" value="8">
        <button type="submit" class="boton boton-primario"><?= $this->parcial('parciales/icono', ['nombre' => 'reloj']) ?> Encolar proceso de demostración</button>
    </form>
    <p class="campo-ayuda">En MAMP no hay cron: ejecuta <code>php cli/cron_local.php</code> para simular el trabajador y el programador, o <code>php cli/trabajador_cron.php</code> una vez.</p>
    <div id="demo-trabajos" class="lista-trabajos"></div>
</section>
<?php endif; ?>

<div class="rejilla-dos">
    <section class="tarjeta seccion" aria-labelledby="titulo-formulario">
        <h2 id="titulo-formulario" class="titulo-tarjeta">Formulario con validación</h2>
        <p class="texto-secundario">Respuesta 422 que conserva lo escrito y lleva el foco al resumen de errores (RUI-012).</p>
        <?= $this->parcial('sistema_diseno/formulario_demostracion', ['valores' => [], 'errores' => []]) ?>
    </section>

    <section class="tarjeta seccion" aria-labelledby="titulo-alertas">
        <h2 id="titulo-alertas" class="titulo-tarjeta">Alertas en línea</h2>
        <div class="alerta alerta-info"><?= $this->parcial('parciales/icono', ['nombre' => 'info', 'clase' => 'alerta-icono']) ?><div class="alerta-cuerpo"><p class="alerta-titulo">Hato con fecha de corte</p><p>La fotografía del hato es del 9 de noviembre de 2026; los filtros de fecha no reconstruyen su historia.</p></div></div>
        <div class="alerta alerta-exito"><?= $this->parcial('parciales/icono', ['nombre' => 'ok', 'clase' => 'alerta-icono']) ?><div class="alerta-cuerpo"><p class="alerta-titulo">Conciliación completa</p><p>Los 61 recaudos de Ganadería tienen referencia de pago.</p></div></div>
        <div class="alerta alerta-advertencia"><?= $this->parcial('parciales/icono', ['nombre' => 'alerta', 'clase' => 'alerta-icono']) ?><div class="alerta-cuerpo"><p class="alerta-titulo">Trazabilidad incompleta</p><p>145 salidas no encuentran su recepción. Revísalas en la bandeja de calidad.</p></div></div>
        <div class="alerta alerta-error"><?= $this->parcial('parciales/icono', ['nombre' => 'error', 'clase' => 'alerta-icono']) ?><div class="alerta-cuerpo"><p class="alerta-titulo">No se pudo importar el archivo</p><p>La hoja «Salidas» no tiene la columna «Lote». Descarga la plantilla vigente.</p></div></div>
    </section>
</div>

<section class="tarjeta seccion" aria-labelledby="titulo-tabla">
    <h2 id="titulo-tabla" class="titulo-tarjeta">Tabla densa</h2>
    <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-tabla">
        <table class="tabla">
            <caption class="sr-only">Lotes de demostración de Ganadería</caption>
            <thead>
                <tr><th scope="col" aria-sort="descending">Lote</th><th scope="col">Producto</th><th scope="col" class="numero">Cantidad</th><th scope="col">Medida</th><th scope="col">Estado</th></tr>
            </thead>
            <tbody>
                <tr><td>GAN-2026-041</td><td>Leche cruda</td><td class="numero">412,5</td><td>L</td><td><span class="insignia insignia-info">Abierto</span></td></tr>
                <tr><td>GAN-2026-040</td><td>Queso campesino</td><td class="numero">38,0</td><td>kg</td><td><span class="insignia insignia-neutra">Cerrado</span></td></tr>
                <tr><td>GAN-2026-039</td><td>Yogur natural</td><td class="numero">96,0</td><td>L</td><td><span class="insignia insignia-advertencia">Por vencer</span></td></tr>
            </tbody>
        </table>
    </div>
</section>
