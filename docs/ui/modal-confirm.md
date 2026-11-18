# Confirmación (modal)

`<dialog id="dialogo-confirmacion">` nativo, definido en la plantilla base. Lo controla `retroalimentacion-ui.js` mediante el evento `htmx:confirm` (RUI-011). Está prohibido usar `confirm()` del navegador.

## Uso

```html
<button type="button" class="boton boton-peligro"
        hx-post="…/archivar" hx-target="closest tr" hx-swap="outerHTML"
        data-confirmar="El gasto dejará de contar en IND_17. Podrás restaurarlo después."
        data-confirmar-titulo="Archivar gasto" data-confirmar-accion="Archivar" data-peligro>
  Archivar
</button>
```

| Atributo | Efecto |
|---|---|
| `data-confirmar` | Mensaje. Su presencia activa la confirmación |
| `data-confirmar-titulo` | Título; por defecto, «Confirmar acción» |
| `data-confirmar-accion` | Texto del botón de aceptar |
| `data-peligro` | Botón de aceptar en rojo y foco inicial en **Cancelar** |

## Accesibilidad

- El foco queda atrapado con Tab y Mayús+Tab.
- Esc cancela.
- Al cerrar, el foco vuelve al disparador.
- El diálogo usa `aria-labelledby` y `aria-describedby`.

Desde JavaScript se invoca con `await retroalimentacionUI.confirmar({ titulo, mensaje, textoAccion, peligro })`.
