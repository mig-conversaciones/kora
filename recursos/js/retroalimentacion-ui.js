/**
 * retroalimentacion-ui.js — Mensajería y estados de la interfaz de KORA (equivale a ui-feedback.js).
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
      if (continuar && window.htmx) window.htmx.ajax('POST', `${meta('ruta-base')}/sesion/extender`, { swap: 'none' });
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
