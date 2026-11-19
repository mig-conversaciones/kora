# Integración con el software de ventas (RF-095 a RF-098)

- **Contrato:** [`integracion-ventas.yaml`](integracion-ventas.yaml), en OpenAPI 3.1 (anexo E del SRS).
- **Versión 1.0:**
  - el contrato y un **simulador** del receptor de KORA, con pruebas de contrato (RF-095);
  - el intercambio por archivo con las mismas columnas (RF-096).
- **Versión posterior:** el endpoint operativo de KORA (RF-098).

## Reglas del contrato

- **Autenticación.**
  - Cada solicitud lleva:
    - `X-Clave-Id`: el identificador del secreto del cliente; permite rotarlo;
    - `X-Fecha`: en ISO 8601 UTC, por ejemplo `2026-11-19T15:04:05Z`, a no más de 5 minutos del reloj del servidor;
    - `X-Firma`.
  - La firma se calcula así:

    ```text
    X-Firma = base64( HMAC-SHA256( secreto, METODO + "\n" + ruta + "\n" + X-Fecha + "\n" + hex(SHA-256(cuerpo)) ) )
    ```

    La ruta va sin dominio ni consulta, por ejemplo `/api/v1/integracion/ventas/lotes`. En un GET el cuerpo es vacío.
- **Idempotencia.** `Idempotency-Key`, de 16 a 120 caracteres, en cada envío de lote:
  - el mismo contenido con la misma clave devuelve el mismo lote;
  - otro contenido con una clave ya usada responde 409.
- **Identidad (RF-097).** Los registros se relacionan solo por las claves de unidad, producto, lote y proceso. Una salida sin `id_externo` se rechaza.
- **Errores.** Siguen el RFC 9457 (`application/problem+json`), con `errores: [{indice, campo, mensaje}]`.
- **Mantenimiento.** KORA responde 503 con `Retry-After` y no recibe nada. El cliente reintenta después.

## Probar el cliente contra el simulador

El simulador aplica el contrato completo sin tocar ninguna base: firma, idempotencia, validación y mantenimiento.

```sh
SIMULADOR_CLAVES="ventas-pruebas:UN_SECRETO_DE_32_CARACTERES_O_MAS" SIMULADOR_DATOS=/tmp/kora-simulador \
  php -S 127.0.0.1:8090 tests/contrato/simulador.php
```

- **URL base:** `http://127.0.0.1:8090/api/v1`.
- **Activar el mantenimiento:** crear el archivo `$SIMULADOR_DATOS/mantenimiento`.
- **Código que se reutiliza:** `App\Integracion\FirmaIntegracion` y `App\Integracion\ValidadorLoteVentas` son la firma y la validación que usará el endpoint operativo (RF-098).

## Pruebas de contrato (Schemathesis)

En cada push corre el trabajo «Contrato de integración» de la CI. En local:

```sh
python -m venv .venv-contrato && .venv-contrato/bin/pip install -r tests/contrato/requisitos.txt
SIMULADOR_CLAVES="…" SCHEMATHESIS_HOOKS=tests.contrato.ganchos .venv-contrato/bin/schemathesis run docs/api/integracion-ventas.yaml \
  --experimental=openapi-3.1 --base-url http://127.0.0.1:8090/api/v1 --checks all --hypothesis-max-examples 150
```

- `tests/contrato/ganchos.py` firma cada solicitud generada, como lo haría el cliente.
- **Criterio de RF-095:** ninguna violación de esquema en ninguna comprobación.
- **Resultado del 2026-11-19 en local:** 7 comprobaciones, 160 de 160 casos. Incluye las respuestas conformes al esquema, el rechazo de datos inválidos y que la firma no se puede omitir.
