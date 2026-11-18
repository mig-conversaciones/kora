/**
 * Componentes Alpine (versión CSP): se registran con Alpine.data y las plantillas solo referencian
 * propiedades y métodos, sin expresiones con lógica (ADR-004). Alpine maneja estado visual, nunca datos.
 * Base: 04-03, 4.3.4. Adaptaciones de la I0: ruta base del subdirectorio local, preferencias en el servidor solo
 * cuando hay sesión (I2) y selector de tema con respaldo en el navegador.
 */
document.addEventListener('alpine:init', () => {
  const meta = (nombre) => document.querySelector(`meta[name="${nombre}"]`)?.getAttribute('content') ?? '';

  // Preferencias reversibles (RF-008). Sin sesión (I0) no hay CSRF ni usuario: se conservan solo en el navegador.
  const guardarPreferencia = (clave, valor) => {
    const csrf = meta('csrf-token');
    if (!csrf) return Promise.resolve();
    return fetch(`${meta('ruta-base')}/preferencias`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': csrf,
        'X-Idempotency-Key': crypto.randomUUID(),
      },
      body: new URLSearchParams({ [clave]: valor }),
    }).then((respuesta) => {
      if (!respuesta.ok) throw new Error(String(respuesta.status));
    });
  };

  // Respaldo local: el almacenamiento puede estar bloqueado (modo privado); nunca debe romper la interfaz.
  const leerLocal = (clave) => {
    try { return window.localStorage.getItem(`kora.${clave}`); } catch { return null; }
  };
  const guardarLocal = (clave, valor) => {
    try { window.localStorage.setItem(`kora.${clave}`, valor); } catch { /* sin almacenamiento: solo esta vista */ }
  };

  window.Alpine.data('barraLateral', () => ({
    colapsada: false,
    abiertaMovil: false,
    init() {
      const local = leerLocal('menu');
      this.colapsada = (local ?? document.documentElement.dataset.menu) === 'colapsado';
      document.addEventListener('keydown', (evento) => {
        if (evento.key === 'Escape' && this.abiertaMovil) this.cerrarMovil();
      });
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
      const valor = this.colapsada ? 'colapsado' : 'expandido';
      guardarLocal('menu', valor);
      guardarPreferencia('menu', valor).catch(() => {
        this.colapsada = anterior;
        guardarLocal('menu', anterior ? 'colapsado' : 'expandido');
        window.retroalimentacionUI?.mostrarToast({ tipo: 'warning', mensaje: 'No se pudo guardar tu preferencia del menú; se restauró la anterior.' });
      });
    },
    alternarMovil() {
      this.abiertaMovil = !this.abiertaMovil;
      if (this.abiertaMovil) this.$nextTick(() => document.querySelector('#barra-lateral a, #barra-lateral button')?.focus());
    },
    cerrarMovil() {
      this.abiertaMovil = false;
    },
  }));

  // Tema claro, oscuro o del sistema (RUI-005). data-tema en <html> activa los tokens correspondientes.
  window.Alpine.data('selectorTema', () => ({
    tema: 'sistema',
    init() {
      this.tema = leerLocal('tema') ?? document.documentElement.dataset.tema ?? 'sistema';
      this.aplicar();
    },
    get esClaro() {
      return this.tema === 'claro';
    },
    get esOscuro() {
      return this.tema === 'oscuro';
    },
    get esSistema() {
      return this.tema === 'sistema';
    },
    usarClaro() {
      this.cambiar('claro');
    },
    usarOscuro() {
      this.cambiar('oscuro');
    },
    usarSistema() {
      this.cambiar('sistema');
    },
    cambiar(tema) {
      const anterior = this.tema;
      this.tema = tema;
      this.aplicar();
      guardarLocal('tema', tema);
      guardarPreferencia('tema', tema).catch(() => {
        this.tema = anterior;
        this.aplicar();
        window.retroalimentacionUI?.mostrarToast({ tipo: 'warning', mensaje: 'No se pudo guardar el tema; se restauró el anterior.' });
      });
    },
    aplicar() {
      document.documentElement.dataset.tema = this.tema;
    },
  }));
});

// El tema guardado se aplica antes de que Alpine arranque para evitar un destello del tema contrario.
(() => {
  try {
    const tema = window.localStorage.getItem('kora.tema');
    if (tema) document.documentElement.dataset.tema = tema;
  } catch { /* sin almacenamiento */ }
})();
