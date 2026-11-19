/**
 * Tailwind CSS 3.4 para KORA. Las utilidades leen los tokens CSS (RUI-001): cambiar tokens.css cambia también
 * las utilidades, sin recompilar la configuración. Se construye con la CLI en local o en la CI (RNF-019).
 * @type {import('tailwindcss').Config}
 */
module.exports = {
  content: ['./app/Vistas/**/*.php', './app/Tableros/**/*.php', './recursos/js/**/*.js'],
  // El modo oscuro lo resuelven los tokens; la variante dark: queda para casos puntuales con tema explícito.
  darkMode: ['selector', '[data-tema="oscuro"]'],
  theme: {
    extend: {
      colors: {
        primario: 'var(--color-primario)',
        acento: { DEFAULT: 'var(--color-acento)', suave: 'var(--color-acento-suave)' },
        accion: 'var(--color-accion)',
        fondo: 'var(--color-fondo)',
        superficie: { DEFAULT: 'var(--color-superficie)', alt: 'var(--color-superficie-alt)' },
        texto: { DEFAULT: 'var(--color-texto)', secundario: 'var(--color-texto-secundario)' },
        linea: 'var(--color-linea)',
        'borde-control': 'var(--color-borde-control)',
        exito: 'var(--color-exito)',
        advertencia: 'var(--color-advertencia)',
        error: 'var(--color-error)',
        info: 'var(--color-info)',
        serie: { venta: 'var(--serie-venta)', autoconsumo: 'var(--serie-autoconsumo)', presupuesto: 'var(--serie-presupuesto)', asignacion: 'var(--serie-asignacion)' },
      },
      fontFamily: { base: 'var(--fuente-base)' },
      fontSize: {
        xs: 'var(--tamano-xs)', sm: 'var(--tamano-sm)', base: 'var(--tamano-base)', lg: 'var(--tamano-lg)',
        xl: 'var(--tamano-xl)', '2xl': 'var(--tamano-2xl)', kpi: 'var(--tamano-kpi)',
      },
      borderRadius: { pequeno: 'var(--radio-pequeno)', medio: 'var(--radio-medio)', grande: 'var(--radio-grande)' },
      boxShadow: { 0: 'var(--sombra-0)', 1: 'var(--sombra-1)', 2: 'var(--sombra-2)', 3: 'var(--sombra-3)', campo: 'var(--sombra-campo)' },
    },
  },
  plugins: [],
};
