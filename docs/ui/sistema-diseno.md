# Sistema de diseño de KORA

Estilo: **skeuomorfismo funcional sutil** (RUI-002). Profundidad moderada con gradientes del 2 al 4 %, sombras suaves y sin texturas. Los campos tienen relieve interior y los botones, un estado presionado reconocible.

La vitrina viva está en `/sistema-diseno` (en local, `http://localhost/dashboard_laila/sistema-diseno`). Allí se prueba cada componente contra el servidor real.

## Capas de tokens (RUI-001)

El archivo fuente es `recursos/css/tokens.css`. Tailwind 3.4 lo consume a través de `tailwind.config.cjs`.

| Capa | Contenido | Cómo se cambia |
|---|---|---|
| 1. Identidad y semántica | Marca KORA, neutros, estados, series de datos, fuentes y escala tipográfica | Cambio del kit de identidad o autorización de la marca SENA (S-03) |
| 2. Estructura | Espaciado de 4 px, radios 6, 10 y 14 px, anchos del menú | Rara vez |
| 3. Superficie y elevación | `--superficie-*`, `--sombra-0` a `--sombra-3`, `--sombra-campo`, `--sombra-boton*`, `--anillo-foco` | Sustituyendo **solo este bloque** se cambia de estilo (por ejemplo, a plano) |

## Marca (ADR-016)

La fuente es el kit `referencias/KORA_kit_identidad`. `npm run marca` deriva los recursos web en `public/marca/` y `public/favicon.svg`; `npm run construir` genera los PNG e ICO.

| Archivo | Uso |
|---|---|
| `kora-logo-claro.svg` / `kora-logo-oscuro.svg` | Logo horizontal con lema, para fondo claro / fondo azul pizarra (oscuro con fondo transparente) |
| `kora-logo-*-sin-lema.svg` | Tamaños pequeños; el menú lateral usa el oscuro |
| `kora-simbolo-oscuro.svg` / `-claro.svg` | Símbolo «K» con la flecha turquesa; menú colapsado |
| `kora-logo-vertical.svg`, `kora-logo-monocromatico.svg` | Composiciones cuadradas y reproducción a una tinta |
| `favicon.svg` → `favicon-32.png`, `favicon.ico` (16, 32, 48) | Pestaña del navegador |
| `kora-icono-movil.svg` → `icono-192/512.png`, `apple-touch-icon.png` | Manifest e iOS |
| `icono-512-mascara.png` | Ícono adaptable: símbolo dentro de la zona segura del 80 % |

No se deforman, recolorean ni recomponen los logos. El kit no sustituye la revisión de marca ni la verificación de disponibilidad del nombre, que siguen pendientes.

## Paleta del sistema (RUI-003)

Fuente: `KORA_paleta_sistema.md` del kit.

| Token | Claro | Uso |
|---|---|---|
| `--color-primario` | #182B43 | Azul pizarra: marca, menú lateral, encabezados y elementos estructurales |
| `--color-acento` | #20BFAE | Turquesa KORA: selección sobre azul oscuro, indicadores y pequeños gráficos. **Uso puntual; no para texto sobre fondos claros** |
| `--color-accion` | #087F74 | Turquesa oscuro: botones, enlaces, foco y controles activos (blanco encima: 4,89:1) |
| `--color-acento-suave` | #E1F6F3 | Selección suave, etiquetas y fondos de énfasis |
| `--color-fondo` / `--color-superficie` / `--color-superficie-alt` | #F6F8FA / #FFFFFF / #EAF0F3 | Canvas, tarjetas y secciones secundarias |
| `--color-texto` / `--color-texto-secundario` | #162538 / #526477 | Texto principal y metadatos |
| `--color-linea` | #D6E0E6 | Divisores decorativos (nunca el único límite de un control) |
| `--color-borde-control` | #7D8FA0 | Límite de campos y botones secundarios (3,3:1) |

**Estados.** Siempre van acompañados de texto o icono.

| Estado | Texto o icono | Fondo |
|---|---|---|
| Éxito | #0A766D | #E8F5EF |
| Advertencia | #9A5C0A | #FFF2D6 |
| Error | #B33D48 | #FDEBEC |
| Información | #2166A3 | #E9F2FB |

**Series de datos de los tableros.** Son distintas de los estados, siempre con leyenda (regla 5 del kit):

| Serie | Color |
|---|---|
| `--serie-venta` | #15803D, verde de la Guía de tableros |
| `--serie-autoconsumo` | #D97706, ámbar de la Guía |
| `--serie-presupuesto` | #182B43, azul pizarra de la marca |
| `--serie-asignacion` | #7D8FA0 |

`npm run contraste` verifica 33 pares en modo claro y oscuro: texto, acción, foco, bordes de control, estados, botones, series y menú. El mínimo es 4,5:1 para texto y 3:1 para componentes y gráficos.

## Modo oscuro (RUI-005)

- Sigue a `prefers-color-scheme` mientras `<html data-tema>` valga `sistema`.
- `data-tema="claro"` o `"oscuro"` fuerzan el tema. El componente Alpine `selectorTema` los aplica.
- Sin sesión (I0), la preferencia se guarda solo en el navegador. Desde la I2 se guarda también en `usuarios.preferencias`.
- El kit KORA solo define el modo claro. El oscuro se deriva de la familia del azul pizarra (fondo #0C1522, superficie #142236); en él la acción pasa al turquesa brillante con texto oscuro.
- El bloque oscuro se declara dos veces en `tokens.css`: una bajo la consulta de medios y otra bajo `[data-tema="oscuro"]`. CSS no permite combinar ambos en un solo selector.

## Tipografía (RUI-004)

Inter autoalojada en WOFF2: subconjunto latino con tildes y ñ, pesos 400, 500, 600 y 700, y `font-display: swap`. Los indicadores y las tablas usan cifras tabulares (`.cifras`, `.numero`, `.kpi-valor`).

| Token | Tamaño | Uso |
|---|---|---|
| `--tamano-kpi` | 2 rem | Valor de un indicador |
| `--tamano-2xl` | 1,75 rem | Título de página |
| `--tamano-xl` | 1,375 rem | Título de sección |
| `--tamano-lg` | 1,125 rem | Título de tarjeta |
| `--tamano-base` | 1 rem | Texto |
| `--tamano-sm` | 0,875 rem | Tablas, botones y menú |
| `--tamano-xs` | 0,75 rem | Notas e insignias |

## Elevación

| Nivel | Uso |
|---|---|
| `--sombra-0` | Elementos planos dentro de una tarjeta |
| `--sombra-1` | Tarjetas, con brillo superior interno |
| `--sombra-2` | Elementos al pasar el puntero |
| `--sombra-3` | Toasts, diálogo y menú móvil |

## Componentes (clases semánticas)

| Componente | Clases | Documento |
|---|---|---|
| Tarjeta y tarjeta KPI | `.tarjeta`, `.tarjeta-kpi`, `.kpi-venta`, `.kpi-autoconsumo`, `.kpi-presupuesto` | [bento-card.md](bento-card.md) |
| Botones | `.boton`, `.boton-primario`, `.boton-peligro`, `.boton-texto`, `.boton-icono`, `.boton-pequeno` | (este documento) |
| Insignias y semáforos | `.insignia-exito`, `-advertencia`, `-error`, `-info`, `-neutra` | (este documento) |
| Campos | `.campo`, `.campo-grupo`, `.campo-ayuda`, `.campo-error` | [inline-alert.md](inline-alert.md) |
| Alertas en línea | `.alerta-info`, `-exito`, `-advertencia`, `-error` | [inline-alert.md](inline-alert.md) |
| Toasts | `#region-toasts`, `#region-toasts-urgentes`, `#plantilla-toast` | [toast.md](toast.md) |
| Confirmación | `#dialogo-confirmacion` con `data-confirmar` | [modal-confirm.md](modal-confirm.md) |
| Estado vacío | `.estado-vacio`, `parciales/estado_vacio` | [empty-state.md](empty-state.md) |
| Esqueleto | `#esqueleto-tabla`, `#esqueleto-kpi`, `data-esqueleto` | [skeleton-loader.md](skeleton-loader.md) |
| Tabla densa | `.contenedor-tabla`, `.tabla`, `.numero` | [table-dense.md](table-dense.md) |
| Gráficos | SVG generados en el servidor (I6) | [graficos-svg.md](graficos-svg.md) |

## Estados

- **Foco:** `--anillo-foco`, un anillo sólido de 2 px en el color de acción separado 2 px del control.
- **Presionado:** `.boton:active` hunde el botón 1 px y muestra una sombra interior.
- **Deshabilitado:** opacidad del 55 % y cursor «no permitido».
- **Inválido:** `aria-invalid="true"` pone el borde en color de error.
- **Movimiento reducido:** `prefers-reduced-motion` anula transiciones, animaciones y View Transitions.

## Iconografía (RUI-008)

Los iconos son SVG en línea de 24×24 con trazo `currentColor`, en `app/Vistas/parciales/icono.php`. No hay fuente de iconos. Son decorativos por defecto (`aria-hidden`); un botón que solo lleva icono necesita `aria-label` o un texto `.sr-only`.

## Comportamiento adaptable

- **Hasta 64 rem (1024 px):** la barra lateral se vuelve un panel deslizable con fondo inerte (Esc lo cierra), la rejilla de tarjetas pasa a dos columnas y los chips de la barra superior muestran solo el icono.
- **Hasta 40 rem (640 px):** todo pasa a una columna.
- **Tablas:** se desplazan en horizontal dentro de `.contenedor-tabla`, que es enfocable con el teclado.

## Reglas por la CSP (ADR-004)

- No hay estilos ni scripts en línea. Los colores dinámicos se resuelven con clases.
- Las clases que se arman en la plantilla (por ejemplo, `muestra-<?= $token ?>`) **deben declararse fuera de `@layer`**, porque Tailwind purga las que no encuentra literalmente en el contenido.
- No se usa `hx-on`, filtros `[...]` en `hx-trigger`, `hx-vals="js:"` ni expresiones de Alpine con lógica.
