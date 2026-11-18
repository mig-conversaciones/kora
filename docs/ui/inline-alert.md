# Alertas en línea y errores de formulario

## Alertas

`.alerta` más una variante: `.alerta-info`, `.alerta-exito`, `.alerta-advertencia` o `.alerta-error`. Llevan un borde lateral de 4 px con el color semántico, un icono decorativo, un título (`.alerta-titulo`) y un texto.

## Errores por campo (RUI-012)

El servidor responde **422** con el fragmento del formulario (`ControladorBase::respuestaValidacion`):

- **Conserva lo escrito**, salvo contraseñas, códigos y el token CSRF.
- Cada campo inválido lleva `aria-invalid="true"` y `aria-describedby="error-<campo>"`, y el mensaje va en `<p id="error-<campo>" class="campo-error">`.
- El resumen de errores lleva `data-resumen-errores`. Tras el intercambio, `retroalimentacion-ui.js` mueve el foco al resumen, y sus enlaces llevan a cada campo.
- `responseHandling` intercambia los 422 y 409 en el propio formulario (`error: false`).

## Errores de la solicitud (RUI-015)

Los 4xx y 5xx se intercambian en `#contenedor-errores`. Se generan con el fragmento `errores/fragmento` de `ManejadorErrores` y muestran el código de soporte, que es el `X-Request-ID`. Si falla la red o se agota el tiempo de espera, `retroalimentacion-ui.js` construye un error controlado equivalente.

Prueba manual: los formularios y botones de error de `/sistema-diseno`.
