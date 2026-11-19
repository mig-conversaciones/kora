# Auditoría WCAG 2.2 AA (I8)

El anexo M.1 pide, para la accesibilidad (RUI-002 a RUI-024, RNF-003), **axe sin errores y revisión manual con teclado y lector de pantalla**. Este informe separa lo automatizado, que ya corre en cada push, de la revisión manual, que hace una persona antes de la salida.

## 1. Auditoría automática con axe-core

- **Herramienta:** `@axe-core/playwright` 4.10.1 sobre Chromium (Playwright 1.55.1).
- **Reglas:** `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa` y `wcag22aa`.
- **Cobertura:** 37 pantallas de 5 perfiles (público, líder de producción de centro, líder de unidad, coordinación administrativa y Grupo de Recaudo), en escritorio (1280 px) y en móvil (Pixel 7). Son 74 recorridos, en `tests/e2e/accesibilidad.spec.js`.
- **CSP:** cada recorrido falla si el navegador informa una violación de la política de contenido.
- **Dónde corre:** en el trabajo «Navegador, accesibilidad y ZAP» de la CI, en cada push, con MariaDB 10.6 y el dataset «mini». Cualquier violación lo hace fallar y queda como anotación.

### Resultado del 2026-11-19 (local, dataset de 3 años)

**74 de 74 recorridos sin violaciones**, después de corregir lo que encontró la primera pasada:

| Regla de axe | Criterio WCAG | Dónde | Corrección |
|---|---|---|---|
| `color-contrast` | 1.4.3 | Código grande de las páginas de error (404, 403) | Color de texto secundario en lugar del color de línea |
| `aria-allowed-attr` | 4.1.2 | Botones de modo del tablero central (`aria-pressed` en enlaces) | `aria-current="true"` en el modo activo, con el mismo estilo |
| `scrollable-region-focusable` | 2.1.1 | Cinco tablas desplazables de los tableros, visibles en móvil | `tabindex="0"`, `role="region"` y nombre accesible |
| `link-name` | 2.4.4, 4.1.2 | Menú lateral contraído: los enlaces quedaban sin nombre | Texto oculto solo a la vista (no `display: none`) y `title` |

El último hallazgo apareció porque una prueba dejó el menú contraído en las preferencias del usuario. Ahora hay una prueba que audita expresamente el menú contraído.

## 2. Flujos de teclado automatizados

En `tests/e2e/flujos.spec.js`:

- el primer **Tab** lleva a «Ir al contenido» y **Enter** mueve el foco al contenido principal (2.4.1);
- el diálogo de confirmación recibe el foco, lo retiene, se cierra con **Escape** sin ejecutar la acción y devuelve el foco al botón que lo abrió (2.1.2, 2.4.3);
- la vitrina de retroalimentación no usa `alert`, `confirm` ni `prompt` nativos (RUI-009).

## 3. Revisión manual (pendiente antes de la salida)

La hace una persona con **NVDA y Firefox** en Windows, y con **VoiceOver** en un teléfono, sobre staging. Las casillas se marcan con la fecha y quien revisó.

| Comprobación | Criterios | Estado |
|---|---|---|
| Recorrer las pantallas de cada rol solo con el teclado: orden lógico y foco siempre visible | 2.1.1, 2.4.3, 2.4.7, 2.4.11 | ☐ |
| Formularios con errores: el lector anuncia el resumen y cada campo con su error | 3.3.1, 3.3.3, 4.1.3 | ☐ |
| Mensajes breves (toasts) anunciados sin mover el foco; los de error persisten | 4.1.3, 2.2.1 | ☐ |
| Tableros: cada gráfico tiene su tabla equivalente y el lector la recorre | 1.1.1, 1.3.1 | ☐ |
| Zoom al 200 % y ancho de 320 px sin desplazamiento horizontal de la página | 1.4.4, 1.4.10 | ☐ |
| Espaciado de texto aumentado sin pérdida de contenido | 1.4.12 | ☐ |
| Objetivos táctiles de al menos 24 × 24 px | 2.5.8 | ☐ |
| Modo oscuro: contraste y foco en las mismas pantallas | 1.4.3, 1.4.11 | ☐ |
| Tiempo de sesión: el aviso previo permite extenderla | 2.2.1 | ☐ |
| Ayuda en línea e impresión de guías y actas | 1.3.1, 2.4.6 | ☐ |

**Resultado de la revisión manual:** ☐ Aprobada ☐ Con hallazgos (ver la lista)

| Revisó | Fecha | Firma |
|---|---|---|
| | | |
