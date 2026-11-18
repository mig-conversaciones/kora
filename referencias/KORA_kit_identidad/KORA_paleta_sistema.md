# KORA · Paleta cromática del sistema

**Propósito:** una interfaz clara para gestionar unidades productivas de distintos sectores. Los fondos neutros sostienen la lectura; el azul pizarra organiza la navegación y la jerarquía; el turquesa identifica acciones y datos relevantes.

## Colores de marca e interfaz

| Token | Uso recomendado | Color | Hex |
|---|---|---|---|
| `primary` | Marca, menú lateral, encabezados y elementos estructurales | Azul pizarra | `#182B43` |
| `accent` | Acento del logo, indicadores destacados e iconos sobre azul oscuro | Turquesa KORA | `#20BFAE` |
| `action` | Botones, enlaces y controles activos sobre fondos claros | Turquesa oscuro | `#087F74` |
| `accent-soft` | Selección suave, etiquetas y fondos de énfasis | Turquesa pálido | `#E1F6F3` |
| `canvas` | Fondo general de pantallas | Gris muy claro | `#F6F8FA` |
| `surface` | Tarjetas, formularios y paneles | Blanco | `#FFFFFF` |
| `surface-alt` | Secciones secundarias y bloques alternos | Gris azulado claro | `#EAF0F3` |
| `text` | Texto principal | Azul tinta | `#162538` |
| `text-muted` | Texto secundario y metadatos | Gris pizarra | `#526477` |
| `border` | Divisores y bordes decorativos | Gris frío | `#D6E0E6` |
| `control-border` | Límites de controles que deban distinguirse del fondo | Gris pizarra medio | `#7D8FA0` |

## Colores semánticos

Los estados usan colores propios. Añadir siempre texto o iconografía además del color para comunicar el significado.

| Estado | Texto o icono | Fondo suave | Ejemplo |
|---|---|---|---|
| Correcto | `#0A766D` | `#E8F5EF` | Proceso completado |
| Atención | `#9A5C0A` | `#FFF2D6` | Inventario bajo |
| Error | `#B33D48` | `#FDEBEC` | Operación fallida |
| Información | `#2166A3` | `#E9F2FB` | Aviso del sistema |

## Reglas de aplicación

1. Usar `canvas` como fondo de página y `surface` para las tarjetas. Reservar `primary` para navegación, títulos de alto nivel y zonas de marca.
2. Usar `accent` de forma puntual: selección sobre azul oscuro, pequeños gráficos e indicadores. Evitar llenar grandes superficies de turquesa brillante.
3. En botones con texto blanco, usar `action` (`#087F74`). El blanco sobre `accent` (`#20BFAE`) tiene aproximadamente **2,3:1** de contraste y no alcanza el mínimo para texto normal; el blanco sobre `action` alcanza aproximadamente **4,89:1**.
4. Usar `text` para el contenido principal y `text-muted` para ayudas y metadatos. No usar `border` como único límite visible de un control necesario para operar; para ello usar `control-border` o un contraste equivalente.
5. En gráficos, acompañar los colores con etiquetas y, cuando sea necesario, patrones o formas distintas. Los colores de estado no deben confundirse con series de datos sin una leyenda clara.

## Variables CSS

```css
:root {
  --kora-primary: #182B43;
  --kora-accent: #20BFAE;
  --kora-action: #087F74;
  --kora-accent-soft: #E1F6F3;

  --kora-canvas: #F6F8FA;
  --kora-surface: #FFFFFF;
  --kora-surface-alt: #EAF0F3;
  --kora-text: #162538;
  --kora-text-muted: #526477;
  --kora-border: #D6E0E6;
  --kora-control-border: #7D8FA0;

  --kora-success: #0A766D;
  --kora-success-bg: #E8F5EF;
  --kora-warning: #9A5C0A;
  --kora-warning-bg: #FFF2D6;
  --kora-danger: #B33D48;
  --kora-danger-bg: #FDEBEC;
  --kora-info: #2166A3;
  --kora-info-bg: #E9F2FB;
}
```

## Referencia de accesibilidad

WCAG 2.2 establece una relación mínima de **4,5:1 para texto normal** y de **3:1 para información visual necesaria en componentes de interfaz**. Los contrastes indicados arriba son cálculos de las parejas concretas; comprobar cada combinación final en su contexto real, incluidos estados hover, foco y deshabilitado.

- [W3C WAI · Contraste mínimo para texto](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html)
- [W3C WAI · Contraste de elementos no textuales](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast)
