# Toast (mensaje emergente)

Implementado en `recursos/js/retroalimentacion-ui.js` (RUI-009, RUI-010).

## Taxonomía y duración

| Tipo | Duración | Región | Rol ARIA |
|---|---|---|---|
| `success` | 5 s | `#region-toasts` (abajo a la derecha) | `status` |
| `info` | 5 s | `#region-toasts` | `status` |
| `warning` | 8 s | `#region-toasts` | `status` |
| `error` | Persiste hasta que se cierra | `#region-toasts-urgentes` (arriba, `aria-live="assertive"`) | `alert` |
| `loading` / `confirmation` | Persisten | `#region-toasts` | `status` |

- El cierre automático se pausa al pasar el puntero o al enfocar el mensaje, y se reanuda con al menos 1,5 s restantes.
- Se apilan como máximo 4 mensajes; primero se retira el más antiguo que no sea un error.
- Se conserva un historial de los últimos 20 en `retroalimentacionUI.historial()`.

## Contrato con el servidor

```http
HX-Trigger: {"mostrarToast":{"tipo":"success","mensaje":"Cambios guardados."}}
X-Toast-Type: success
```

- Se genera con `ControladorBase::conToast()`.
- El JSON va escapado en ASCII (`ó`), porque las cabeceras no transportan UTF-8 de forma confiable (D-34).
- Por compatibilidad, `X-Toast-Type` + `X-Toast-Message` solo se usan si no llega `HX-Trigger`.

## Redacción

Cada mensaje dice qué pasó, qué implica y qué hacer. Ejemplo: «No se pudo guardar el gasto: el componente académico supera el valor total. Corrige el valor e intenta de nuevo». Nunca se muestran respuestas crudas del servidor.

## Prueba manual

En `/sistema-diseno`, sección «Mensajes emergentes». Hay una prueba automática del contrato en `AplicacionTest::testToastViajaEnHxTriggerEnAscii`.
