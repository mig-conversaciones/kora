## 4.3 Patrones HTMX y Alpine; sistema de mensajería UI

### 4.3.1 Reglas de la arquitectura frontend

1. **Estados separados.** Alpine maneja el estado visual (menú, pestañas locales, foco). HTMX y el servidor manejan el estado de los datos. Alpine nunca guarda datos de negocio.
2. **Transiciones de tipo SPA.** Se usan la API View Transitions (`globalViewTransitions`, desactivada con `prefers-reduced-motion`) y `hx-swap="morph"` con idiomorph en los filtros, para conservar el foco y el estado de Alpine (RUI-019).
3. **UI optimista restringida** a las preferencias reversibles (RUI-020).
4. **Accesibilidad nativa:** `aria-current` en la navegación, `inert` en el fondo del menú móvil, `:focus-visible`, `prefers-reduced-motion` y `<dialog>` nativo.
5. **Pruebas y experiencia de desarrollo.** En las pruebas de interfaz, las respuestas se simulan interceptando `htmx:configRequest`, y la suite E2E de Playwright cuenta las solicitudes en los flujos de consulta periódica.
6. **Compatibilidad con la CSP (ADR-004).** No se usan `hx-on`, filtros `[...]` en `hx-trigger`, `hx-vals="js:"` ni expresiones de Alpine con lógica. Los comportamientos se declaran con atributos `data-*` que interpreta `retroalimentacion-ui.js`.

> **Decisión D-38.** Por defecto, htmx guarda instantáneas de las páginas en `localStorage` para su historial, lo que dejaría datos financieros y personales en el navegador (RS-024). Se configura `historyCacheSize: 0` y `refreshOnHistoryMiss: true`, de modo que el botón «Atrás» vuelve a pedir la página al servidor.

### 4.3.2 Configuración de htmx y plantilla base

La configuración de htmx viaja en la etiqueta `<meta name="htmx-config">`, que htmx lee al iniciar, con el nonce de la respuesta:

```json
{
  "allowEval": false,
  "allowScriptTags": false,
  "includeIndicatorStyles": false,
  "historyCacheSize": 0,
  "refreshOnHistoryMiss": true,
  "selfRequestsOnly": true,
  "globalViewTransitions": true,
  "timeout": 20000,
  "inlineScriptNonce": "NONCE_DE_LA_RESPUESTA",
  "inlineStyleNonce": "NONCE_DE_LA_RESPUESTA",
  "responseHandling": [
    { "code": "204", "swap": false },
    { "code": "[23]..", "swap": true },
    { "code": "422", "swap": true, "error": false },
    { "code": "409", "swap": true, "error": false },
    { "code": "503", "swap": true, "error": true, "target": "#contenido-principal" },
    { "code": "[45]..", "swap": true, "error": true, "target": "#contenedor-errores" },
    { "code": "...", "swap": false }
  ]
}
```

```php archivo=app/Vistas/diseno/base.php
<?php
/**
 * Plantilla base (RI-002, Opción 2): sidebar comprimible, barra superior y contenido.
 * Variables: $titulo, $contenido, $nonce, $csrf, $configHtmx, $menu, $usuario, $preferencias, $entorno,
 * $sesionExpiraEn, $mantenimientoActivo. $this es App\Nucleo\Vista (e() escapa, asset() resuelve la huella).
 */
?>
<!doctype html>
<html lang="es-CO" data-tema="<?= $this->e($preferencias['tema'] ?? 'sistema') ?>" data-densidad="<?= $this->e($preferencias['densidad'] ?? 'comoda') ?>" data-menu="<?= $this->e($preferencias['menu'] ?? 'expandido') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($titulo) ?> · Producción CAB</title>
    <meta name="csrf-token" content="<?= $this->e($csrf) ?>">
    <meta name="sesion-expira-en" content="<?= (int) $sesionExpiraEn ?>">
    <meta name="htmx-config" content="<?= $this->e(json_encode($configHtmx, JSON_THROW_ON_ERROR)) ?>">
    <meta name="theme-color" content="#102f2c">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="stylesheet" href="<?= $this->asset('css/app.css') ?>">
</head>
<body hx-ext="morph">
    <a class="saltar-contenido" href="#contenido-principal">Ir al contenido</a>
    <?php if ($entorno !== 'produccion'): ?>
        <div class="franja franja-entorno" role="note">Entorno de capacitación — datos sintéticos</div>
    <?php endif; ?>
    <?php if ($mantenimientoActivo): ?>
        <div class="franja franja-mantenimiento" role="status">Modo mantenimiento activo: solo personal autorizado</div>
    <?php endif; ?>

    <div class="contenedor" x-data="barraLateral" x-bind:class="clasesContenedor">
        <aside id="barra-lateral" class="barra-lateral" aria-label="Navegación principal">
            <?= $this->parcial('parciales/menu', ['menu' => $menu]) ?>
            <button type="button" class="boton-colapsar" x-on:click="alternar" x-bind:aria-expanded="expandida" aria-controls="barra-lateral">
                <span class="sr-only">Contraer o expandir el menú</span>
            </button>
        </aside>
        <div class="area-trabajo" x-bind:inert="fondoInerte">
            <header class="barra-superior">
                <button type="button" class="boton-menu-movil" x-on:click="alternarMovil" aria-controls="barra-lateral" x-bind:aria-expanded="abiertaMovil">
                    <span class="sr-only">Abrir el menú</span>
                </button>
                <?= $this->parcial('parciales/migas', ['titulo' => $titulo]) ?>
                <?= $this->parcial('parciales/selector_unidad', ['usuario' => $usuario]) ?>
                <?= $this->parcial('parciales/notificaciones', ['usuario' => $usuario]) ?>
                <?= $this->parcial('parciales/perfil', ['usuario' => $usuario]) ?>
            </header>
            <div id="contenedor-errores" class="contenedor-errores" role="alert" aria-live="assertive"></div>
            <main id="contenido-principal" class="contenido" tabindex="-1">
                <?= $contenido ?>
            </main>
            <a class="ayuda-contextual" href="/ayuda">Ayuda</a>
        </div>
    </div>

    <div id="region-toasts" class="region-toasts" aria-live="polite" aria-relevant="additions"></div>
    <div id="region-toasts-urgentes" class="region-toasts region-toasts-urgentes" aria-live="assertive"></div>
    <div id="anuncio-estado" class="sr-only" role="status" aria-live="polite"></div>

    <dialog id="dialogo-confirmacion" class="dialogo" aria-labelledby="dialogo-titulo" aria-describedby="dialogo-mensaje">
        <h2 id="dialogo-titulo" data-dialogo-titulo></h2>
        <p id="dialogo-mensaje" data-dialogo-mensaje></p>
        <div class="dialogo-acciones">
            <button type="button" class="boton" data-dialogo-cancelar>Cancelar</button>
            <button type="button" class="boton boton-primario" data-dialogo-aceptar>Confirmar</button>
        </div>
    </dialog>

    <template id="plantilla-toast">
        <div class="toast" role="status">
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
```

### 4.3.3 Estados explícitos de la UI (Loading, Empty, Error, Skeleton)

Esta tabla adapta los patrones de la plantilla a la CSP estricta (D-04 a D-08): los atributos `hx-on` se sustituyen por atributos `data-*` declarativos.

| Estado | Patrón obligatorio en Producción CAB | Requisito |
|---|---|---|
| **Loading** | `hx-indicator="#cargando-x"` en el disparador. En el CSS: `.htmx-request { pointer-events: none; opacity: .7; }` aplicado al contenedor de destino. `retroalimentacion-ui.js` marca `aria-busy="true"` durante la solicitud. | RUI-013 |
| **Empty** | El servidor devuelve el fragmento `parciales/estado_vacio` (200). Si una acción responde 204 o vacío, `data-estado-vacio="#plantilla-vacio-x"` hace que el JS inserte la plantilla. | RUI-014 |
| **Error** | `responseHandling` intercambia los 4xx y 5xx en `#contenedor-errores` (los 422 y 409 en el formulario). Los fallos de red y de tiempo de espera se muestran como error controlado con el código de soporte. Los mensajes fuera del destino van con `hx-swap-oob="true"`. | RUI-015 |
| **Skeleton** | `data-esqueleto="#esqueleto-tabla"` en el disparador. El JS inserta el `<template>` si la espera supera 300 ms, lo retira en `htmx:afterRequest` y lo sustituye por un mensaje de progreso con opción de recargar si pasan 8 s. | RUI-016 |

> **Importante.** Regla de oro: nunca se devuelve un 500 con HTML genérico. Toda respuesta 4xx o 5xx trae un fragmento específico que HTMX inyecta en `#contenedor-errores`, con el `request_id` como código de soporte.

**Listado con filtros, búsqueda, carga, esqueleto y estado vacío** (Opción 8):

```html
<section class="tarjeta" aria-labelledby="titulo-gastos">
  <h2 id="titulo-gastos">Gastos operativos ejecutados</h2>
  <form id="filtros-gastos" class="barra-filtros"
        hx-get="/presupuesto/gastos" hx-target="#tabla-gastos" hx-swap="morph:innerHTML"
        hx-push-url="true" hx-indicator="#cargando-gastos"
        hx-trigger="change, input changed delay:400ms from:#buscar-gasto"
        data-esqueleto="#esqueleto-tabla" data-estado-vacio="#plantilla-sin-gastos">
    <label for="buscar-gasto">Buscar</label>
    <input id="buscar-gasto" name="q" type="search" autocomplete="off">
    <label for="categoria">Categoría</label>
    <select id="categoria" name="categoria">
      <option value="">Todas</option>
      <option value="MANTENIMIENTO">Mantenimiento</option>
      <option value="ALIMENTACION">Alimentación y vacunas</option>
      <option value="MATERIALES_FORMACION">Materiales de formación</option>
    </select>
    <span id="cargando-gastos" class="indicador-carga" role="status"><span class="sr-only">Cargando…</span></span>
  </form>
  <div id="tabla-gastos" class="contenedor-tabla" tabindex="0">
    <!-- fragmento gastos/tabla: filas, paginación con X-Has-More y estado vacío del servidor -->
  </div>
  <template id="plantilla-sin-gastos">
    <div class="estado-vacio">
      <h3>No hay gastos para estos filtros</h3>
      <p>Cambia los filtros o registra un gasto ejecutado de tu unidad.</p>
    </div>
  </template>
</section>
```

**Acción con confirmación** (archivar), sin `confirm()` del navegador (RUI-009):

```html
<button type="button" class="boton boton-peligro"
        hx-post="/presupuesto/gastos/481/archivar" hx-target="closest tr" hx-swap="outerHTML"
        data-confirmar="El gasto dejará de contar en IND_17 y en la ejecución de su línea. Podrás restaurarlo después."
        data-confirmar-titulo="Archivar gasto" data-confirmar-accion="Archivar" data-peligro>
  Archivar
</button>
```

```css
/* Estados de carga y esqueleto (RUI-013, RUI-016). */
.htmx-request.contenedor-tabla, .htmx-request .contenedor-tabla { pointer-events: none; opacity: .7; }
.indicador-carga { display: none; }
.htmx-request .indicador-carga, .htmx-request.indicador-carga { display: inline-flex; }
.esqueleto-linea { block-size: 1rem; border-radius: var(--radio-pequeno); background: var(--superficie-esqueleto);
  animation: pulso 1.6s ease-in-out infinite; }
@keyframes pulso { 50% { opacity: .55; } }
@media (prefers-reduced-motion: reduce) {
  .esqueleto-linea { animation: none; }
  ::view-transition-group(*), ::view-transition-old(*), ::view-transition-new(*) { animation: none !important; }
}
```

### 4.3.4 Sistema de mensajería UI: `retroalimentacion-ui.js`

Este módulo equivale al `ui-feedback.js` de la plantilla. Concentra toda la retroalimentación al usuario; está **prohibido** usar `alert()`, `confirm()`, `prompt()` o `console.log()` como retroalimentación (RUI-009).

**Contrato con el servidor:**

| Cabecera o evento | Uso |
|---|---|
| `HX-Trigger: {"mostrarToast":{"tipo":"success","mensaje":"...","titulo":"..."}}` | Toast con un tipo de la taxonomía (success, info, warning, error, loading, confirmation). JSON escapado en ASCII. |
| `X-Toast-Type` (más `X-Toast-Message`, JSON en ASCII) | Compatibilidad con la plantilla: solo se usa si no llega `HX-Trigger`. |
| `X-Request-ID` | El cliente lo genera en cada solicitud y el servidor lo devuelve; aparece como «código de soporte» en los errores. |
| `X-Idempotency-Key` | Se genera una por formulario y se reutiliza en los reintentos. Se renueva tras una respuesta exitosa (RNF-010). |
| `X-Mantenimiento: activo` en un 503 | Detiene la consulta periódica y los reintentos (RF-MAN-008). |
| `X-Sesion-Expira-En: <segundos>` | Reinicia el aviso de expiración de la sesión (RAC-019). |
| `data-confirmar`, `data-confirmar-titulo`, `data-confirmar-accion`, `data-peligro` | Modal de confirmación accesible mediante `htmx:confirm`. |
| `data-esqueleto`, `data-estado-vacio`, `data-consulta-periodica` | Estados declarativos sin JavaScript en línea. |

**Redacción de los mensajes** (claridad, brevedad, contexto y consistencia): cada mensaje dice qué pasó, qué implica y qué hacer. Ejemplo: «No se pudo guardar el gasto: el componente académico supera el valor total. Corrige el valor e intenta de nuevo». Nunca se muestran respuestas crudas del backend.

```javascript archivo=public/assets/js/retroalimentacion-ui.js
/**
 * retroalimentacion-ui.js — Mensajería y estados de la interfaz de Producción CAB (equivale a ui-feedback.js).
 * Toasts, confirmaciones, alertas, estados vacíos, carga, esqueletos y errores controlados.
 * Compatible con CSP estricta: htmx.config.allowEval = false; sin hx-on ni código en atributos (ADR-004).
 */
(() => {
  'use strict';

  const DURACION_MS = { success: 5000, info: 5000, warning: 8000, loading: 0, error: 0, confirmation: 0 };
  const TITULOS = { success: 'Listo', info: 'Información', warning: 'Atención', error: 'No se pudo completar', loading: 'Procesando', confirmation: 'Confirmación' };
  const MAX_TOASTS = 4;
  const RETARDO_ESQUELETO_MS = 300;
  const LIMITE_ESQUELETO_MS = 8000;
  const estado = { mantenimiento: false, temporizadores: new WeakMap(), historial: [], avisoSesion: 0 };

  const meta = (nombre) => document.querySelector(`meta[name="${nombre}"]`)?.getAttribute('content') ?? '';
  const generarId = () => {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  };
  const movimientoReducido = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const decodificarCabecera = (valor) => {
    try { return JSON.parse(valor); } catch { return ''; }
  };

  function anunciar(texto) {
    const region = document.getElementById('anuncio-estado');
    if (region) region.textContent = texto;
  }

  // ---------------------------------------------------------------------------------------------
  // Toasts: auto-cierre con pausa al pasar el puntero o enfocar; los errores persisten (RUI-010, D-09).
  // ---------------------------------------------------------------------------------------------
  function mostrarToast({ tipo = 'info', mensaje = '', titulo = '' } = {}) {
    const region = document.getElementById(tipo === 'error' ? 'region-toasts-urgentes' : 'region-toasts');
    const plantilla = document.getElementById('plantilla-toast');
    if (!region || !(plantilla instanceof HTMLTemplateElement) || !mensaje) return;
    const toast = plantilla.content.firstElementChild.cloneNode(true);
    toast.dataset.tipo = tipo;
    toast.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
    toast.querySelector('[data-toast-titulo]').textContent = titulo || TITULOS[tipo] || '';
    toast.querySelector('[data-toast-mensaje]').textContent = mensaje;
    toast.querySelector('[data-toast-cerrar]').addEventListener('click', () => cerrarToast(toast));
    region.append(toast);
    limitarPila(region);
    programarCierre(toast, DURACION_MS[tipo] ?? 5000);
    estado.historial = [{ tipo, mensaje, titulo, fecha: new Date() }, ...estado.historial].slice(0, 20);
  }

  function programarCierre(toast, duracion) {
    if (!duracion) return;
    let restante = duracion;
    let inicio = Date.now();
    let pausado = false;
    let temporizador = setTimeout(() => cerrarToast(toast), restante);
    const pausar = () => {
      if (pausado) return;
      pausado = true;
      clearTimeout(temporizador);
      restante -= Date.now() - inicio;
    };
    const reanudar = () => {
      if (!pausado || toast.matches(':hover') || toast.contains(document.activeElement)) return;
      pausado = false;
      inicio = Date.now();
      temporizador = setTimeout(() => cerrarToast(toast), Math.max(1500, restante));
    };
    toast.addEventListener('mouseenter', pausar);
    toast.addEventListener('focusin', pausar);
    toast.addEventListener('mouseleave', reanudar);
    toast.addEventListener('focusout', reanudar);
  }

  function limitarPila(region) {
    while (region.children.length > MAX_TOASTS) {
      const masAntiguo = [...region.children].find((t) => t.dataset.tipo !== 'error') ?? region.firstElementChild;
      masAntiguo.remove();
    }
  }

  function cerrarToast(toast) {
    if (!toast.isConnected) return;
    if (movimientoReducido()) {
      toast.remove();
      return;
    }
    toast.classList.add('toast-saliendo');
    toast.addEventListener('animationend', () => toast.remove(), { once: true });
    setTimeout(() => toast.remove(), 400);
  }

  // ---------------------------------------------------------------------------------------------
  // Confirmación: <dialog> nativo con foco atrapado, Esc y retorno del foco (RUI-011).
  // ---------------------------------------------------------------------------------------------
  function confirmar({ titulo = 'Confirmar acción', mensaje = '', textoAccion = 'Confirmar', peligro = false } = {}) {
    const dialogo = document.getElementById('dialogo-confirmacion');
    if (!(dialogo instanceof HTMLDialogElement)) return Promise.resolve(false);
    const disparador = document.activeElement;
    const aceptar = dialogo.querySelector('[data-dialogo-aceptar]');
    const cancelar = dialogo.querySelector('[data-dialogo-cancelar]');
    dialogo.querySelector('[data-dialogo-titulo]').textContent = titulo;
    dialogo.querySelector('[data-dialogo-mensaje]').textContent = mensaje;
    aceptar.textContent = textoAccion;
    aceptar.classList.toggle('boton-peligro', peligro);
    aceptar.classList.toggle('boton-primario', !peligro);
    return new Promise((resolver) => {
      const terminar = (valor) => {
        aceptar.removeEventListener('click', alAceptar);
        cancelar.removeEventListener('click', alCancelar);
        dialogo.removeEventListener('cancel', alCancelar);
        if (dialogo.open) dialogo.close();
        if (disparador instanceof HTMLElement && disparador.isConnected) disparador.focus();
        resolver(valor);
      };
      const alAceptar = () => terminar(true);
      const alCancelar = (evento) => {
        if (evento) evento.preventDefault();
        terminar(false);
      };
      aceptar.addEventListener('click', alAceptar);
      cancelar.addEventListener('click', alCancelar);
      dialogo.addEventListener('cancel', alCancelar);
      dialogo.showModal();
      // En acciones destructivas el foco inicial va a Cancelar para evitar confirmaciones accidentales.
      (peligro ? cancelar : aceptar).focus();
    });
  }

  document.addEventListener('keydown', (evento) => {
    if (evento.key !== 'Tab') return;
    const dialogo = evento.target instanceof Element ? evento.target.closest('dialog[open]') : null;
    if (!dialogo) return;
    const enfocables = [...dialogo.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')]
      .filter((elemento) => !elemento.hasAttribute('disabled'));
    if (enfocables.length === 0) return;
    const primero = enfocables[0];
    const ultimo = enfocables[enfocables.length - 1];
    if (evento.shiftKey && document.activeElement === primero) {
      evento.preventDefault();
      ultimo.focus();
    } else if (!evento.shiftKey && document.activeElement === ultimo) {
      evento.preventDefault();
      primero.focus();
    }
  });

  document.addEventListener('htmx:confirm', (evento) => {
    const elemento = evento.detail.elt;
    const mensaje = elemento.getAttribute('data-confirmar');
    if (!mensaje) return;
    evento.preventDefault();
    confirmar({
      titulo: elemento.getAttribute('data-confirmar-titulo') || 'Confirmar acción',
      mensaje,
      textoAccion: elemento.getAttribute('data-confirmar-accion') || 'Confirmar',
      peligro: elemento.hasAttribute('data-peligro'),
    }).then((confirmado) => {
      if (confirmado) evento.detail.issueRequest(true);
    });
  });

  // ---------------------------------------------------------------------------------------------
  // Errores controlados: red, tiempo de espera y respuestas sin fragmento (RUI-015).
  // ---------------------------------------------------------------------------------------------
  const MENSAJES_ERROR = {
    0: 'Sin conexión con el servidor. Revisa tu conexión e intenta de nuevo; lo que escribiste no se perdió.',
    '-1': 'El servidor tardó demasiado en responder. Intenta de nuevo en unos segundos.',
    401: 'Tu sesión terminó. Ingresa de nuevo para continuar.',
    403: 'No tienes permiso para realizar esta acción.',
    404: 'No encontramos lo solicitado o no está disponible para tu perfil.',
    409: 'Otra persona modificó este registro. Recarga para ver la versión actual.',
    413: 'El archivo o los datos superan el tamaño permitido.',
    429: 'Demasiados intentos seguidos. Espera un momento antes de volver a intentarlo.',
  };

  function mostrarErrorControlado(estadoHttp, idSolicitud) {
    const contenedor = document.getElementById('contenedor-errores');
    if (!contenedor) return;
    const mensaje = MENSAJES_ERROR[String(estadoHttp)] ?? 'Ocurrió un error inesperado. Ya quedó registrado; intenta de nuevo en unos minutos.';
    const alerta = document.createElement('div');
    alerta.className = 'alerta alerta-error';
    const parrafo = document.createElement('p');
    parrafo.textContent = mensaje;
    alerta.append(parrafo);
    if (idSolicitud) {
      const codigo = document.createElement('p');
      codigo.className = 'alerta-codigo';
      codigo.textContent = `Código de soporte: ${idSolicitud}`;
      alerta.append(codigo);
    }
    const cerrar = document.createElement('button');
    cerrar.type = 'button';
    cerrar.className = 'boton boton-texto';
    cerrar.textContent = 'Cerrar';
    cerrar.addEventListener('click', () => alerta.remove());
    alerta.append(cerrar);
    contenedor.replaceChildren(alerta);
  }

  document.addEventListener('htmx:responseError', (evento) => {
    const xhr = evento.detail.xhr;
    // Si el servidor envió un fragmento HTML, responseHandling ya lo intercambió en su destino (D-05).
    if ((xhr.getResponseHeader('Content-Type') || '').includes('text/html') && xhr.responseText.trim() !== '') return;
    mostrarErrorControlado(xhr.status, xhr.getResponseHeader('X-Request-ID'));
  });
  document.addEventListener('htmx:sendError', () => mostrarErrorControlado(0, null));
  document.addEventListener('htmx:timeout', () => mostrarErrorControlado(-1, null));

  // ---------------------------------------------------------------------------------------------
  // Solicitudes: cabeceras de correlación, CSRF e idempotencia (RAU-009, RS-005, RNF-010).
  // ---------------------------------------------------------------------------------------------
  document.addEventListener('htmx:configRequest', (evento) => {
    const cabeceras = evento.detail.headers;
    cabeceras['X-Request-ID'] = generarId();
    if ((evento.detail.verb || 'get').toLowerCase() === 'get') return;
    cabeceras['X-CSRF-Token'] = meta('csrf-token');
    const origen = evento.detail.elt.closest('form') || evento.detail.elt;
    if (!origen.dataset.claveIdempotencia) origen.dataset.claveIdempotencia = generarId();
    cabeceras['X-Idempotency-Key'] = origen.dataset.claveIdempotencia;
  });

  // ---------------------------------------------------------------------------------------------
  // Carga, esqueleto, consulta periódica y mantenimiento (RUI-013, RUI-016, RF-Q-012, RF-MAN-008).
  // ---------------------------------------------------------------------------------------------
  document.addEventListener('htmx:beforeRequest', (evento) => {
    const elemento = evento.detail.elt;
    const periodica = elemento.hasAttribute('data-consulta-periodica');
    if (periodica && estado.mantenimiento) {
      evento.preventDefault();
      return;
    }
    if (periodica && document.hidden) {
      elemento.dataset.pendienteVisible = 'true';
      evento.preventDefault();
      return;
    }
    const destino = evento.detail.target;
    if (destino) destino.setAttribute('aria-busy', 'true');
    programarEsqueleto(elemento, destino);
  });

  document.addEventListener('htmx:afterRequest', (evento) => {
    const { elt: elemento, target: destino, xhr, successful } = evento.detail;
    if (destino) destino.removeAttribute('aria-busy');
    retirarEsqueleto(elemento, successful);
    if (successful && (evento.detail.requestConfig?.verb || 'get').toLowerCase() !== 'get') {
      delete (elemento.closest('form') || elemento).dataset.claveIdempotencia;
    }
    if (!xhr) return;
    if (xhr.status === 503 && xhr.getResponseHeader('X-Mantenimiento') === 'activo') activarModoMantenimiento();
    const expiraEn = Number(xhr.getResponseHeader('X-Sesion-Expira-En'));
    if (expiraEn > 0) vigilarSesion(expiraEn);
    if (!xhr.getResponseHeader('HX-Trigger')) {
      const tipo = xhr.getResponseHeader('X-Toast-Type');
      const mensaje = decodificarCabecera(xhr.getResponseHeader('X-Toast-Message') || '""');
      if (tipo && mensaje) mostrarToast({ tipo, mensaje });
    }
    manejarVacio(elemento, destino, xhr);
  });

  document.addEventListener('htmx:afterSwap', (evento) => {
    const resumen = evento.detail.target.querySelector('[data-resumen-errores]');
    if (resumen instanceof HTMLElement) {
      resumen.setAttribute('tabindex', '-1');
      resumen.focus();
    }
  });

  document.addEventListener('mostrarToast', (evento) => mostrarToast(evento.detail || {}));

  function manejarVacio(elemento, destino, xhr) {
    const selector = elemento.getAttribute('data-estado-vacio');
    if (!selector || !destino) return;
    const sinContenido = xhr.status === 204 || (xhr.status === 200 && xhr.responseText.trim() === '');
    const plantilla = document.querySelector(selector);
    if (sinContenido && plantilla instanceof HTMLTemplateElement) {
      destino.replaceChildren(plantilla.content.cloneNode(true));
    }
  }

  function programarEsqueleto(elemento, destino) {
    const selector = elemento.getAttribute('data-esqueleto');
    const plantilla = selector ? document.querySelector(selector) : null;
    if (!destino || !(plantilla instanceof HTMLTemplateElement)) return;
    const registro = { destino, previo: null, mostrar: 0, limite: 0 };
    registro.mostrar = setTimeout(() => {
      registro.previo = [...destino.childNodes];
      destino.replaceChildren(plantilla.content.cloneNode(true));
      anunciar('Cargando información…');
    }, RETARDO_ESQUELETO_MS);
    registro.limite = setTimeout(() => mostrarProgresoLento(elemento, destino), LIMITE_ESQUELETO_MS);
    estado.temporizadores.set(elemento, registro);
  }

  function retirarEsqueleto(elemento, exitosa) {
    const registro = estado.temporizadores.get(elemento);
    if (!registro) return;
    clearTimeout(registro.mostrar);
    clearTimeout(registro.limite);
    // Ante error se restaura el contenido anterior: el error se muestra aparte y no se pierde la vista.
    if (!exitosa && registro.previo) registro.destino.replaceChildren(...registro.previo);
    estado.temporizadores.delete(elemento);
  }

  function mostrarProgresoLento(elemento, destino) {
    const aviso = document.createElement('div');
    aviso.className = 'estado-progreso';
    aviso.setAttribute('role', 'status');
    const texto = document.createElement('p');
    texto.textContent = 'Esto está tardando más de lo normal. Seguimos trabajando en tu solicitud.';
    const boton = document.createElement('button');
    boton.type = 'button';
    boton.className = 'boton';
    boton.textContent = 'Recargar esta sección';
    boton.addEventListener('click', () => {
      const url = elemento.getAttribute('hx-get');
      if (url && window.htmx) window.htmx.ajax('GET', url, { target: destino, swap: 'innerHTML' });
    });
    aviso.append(texto, boton);
    destino.replaceChildren(aviso);
    anunciar('La carga está tardando más de lo normal.');
  }

  function activarModoMantenimiento() {
    estado.mantenimiento = true;
    clearTimeout(estado.avisoSesion);
    document.querySelectorAll('[data-consulta-periodica]').forEach((elemento) => elemento.removeAttribute('hx-trigger'));
    anunciar('El sistema entró en mantenimiento. Las actualizaciones automáticas se detuvieron.');
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden || estado.mantenimiento || !window.htmx) return;
    document.querySelectorAll('[data-consulta-periodica][data-pendiente-visible]').forEach((elemento) => {
      delete elemento.dataset.pendienteVisible;
      window.htmx.trigger(elemento, 'reanudar');
    });
  });

  // ---------------------------------------------------------------------------------------------
  // Aviso de expiración de la sesión con opción de extenderla (RAC-019, WCAG 2.2.1).
  // ---------------------------------------------------------------------------------------------
  function vigilarSesion(segundos) {
    clearTimeout(estado.avisoSesion);
    if (!segundos || estado.mantenimiento) return;
    estado.avisoSesion = setTimeout(async () => {
      const continuar = await confirmar({
        titulo: 'Tu sesión está por terminar',
        mensaje: 'Por seguridad, la sesión se cerrará en 2 minutos por inactividad. ¿Quieres seguir trabajando?',
        textoAccion: 'Seguir trabajando',
      });
      if (continuar && window.htmx) window.htmx.ajax('POST', '/sesion/extender', { swap: 'none' });
    }, Math.max(0, (segundos - 120) * 1000));
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (window.htmx && movimientoReducido()) window.htmx.config.globalViewTransitions = false;
    vigilarSesion(Number(meta('sesion-expira-en')));
  });

  window.retroalimentacionUI = Object.freeze({
    mostrarToast,
    confirmar,
    mostrarErrorControlado,
    anunciar,
    historial: () => [...estado.historial],
  });
})();
```

```javascript archivo=public/assets/js/componentes-alpine.js
/**
 * Componentes Alpine (versión CSP): se registran con Alpine.data y las plantillas solo referencian
 * propiedades y métodos, sin expresiones con lógica (ADR-004). Alpine maneja estado visual, nunca datos.
 */
document.addEventListener('alpine:init', () => {
  const guardarPreferencia = (clave, valor) => fetch('/preferencias', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      'X-Idempotency-Key': crypto.randomUUID(),
    },
    body: new URLSearchParams({ [clave]: valor }),
  }).then((respuesta) => {
    if (!respuesta.ok) throw new Error(String(respuesta.status));
  });

  window.Alpine.data('barraLateral', () => ({
    colapsada: false,
    abiertaMovil: false,
    init() {
      this.colapsada = document.documentElement.dataset.menu === 'colapsado';
    },
    get expandida() {
      return !this.colapsada;
    },
    get fondoInerte() {
      return this.abiertaMovil;
    },
    get clasesContenedor() {
      return { 'menu-colapsado': this.colapsada, 'menu-movil-abierto': this.abiertaMovil };
    },
    // UI optimista solo para preferencias reversibles (RUI-020): se revierte si el servidor falla.
    alternar() {
      const anterior = this.colapsada;
      this.colapsada = !anterior;
      guardarPreferencia('menu', this.colapsada ? 'colapsado' : 'expandido').catch(() => {
        this.colapsada = anterior;
        window.retroalimentacionUI?.mostrarToast({ tipo: 'warning', mensaje: 'No se pudo guardar tu preferencia del menú; se restauró la anterior.' });
      });
    },
    alternarMovil() {
      this.abiertaMovil = !this.abiertaMovil;
      if (this.abiertaMovil) this.$nextTick(() => document.querySelector('#barra-lateral a, #barra-lateral button')?.focus());
    },
  }));
});
```
