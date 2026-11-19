# ADR-023: Contrato de integración con la firma del anexo E, simulador propio y pruebas de contrato con Schemathesis

- Estado: Aceptado
- Fecha: 2026-11-19
- Responsables: Líder técnico (Senior); revisión del equipo del software de ventas
- Requisitos relacionados: RF-095 a RF-098, RF-MAN-015; ADR-019

## Contexto

RF-095 pide validar el contrato «contra un simulador mediante pruebas de contrato», con el criterio «Schemathesis ejecuta el contrato sobre el simulador sin violaciones de esquema». Al prepararlas aparecieron dos problemas:

- **Firma distinta.** El contrato versionado en la I5 (`docs/api/integracion-ventas.yaml`) firmaba con `X-Kora-Marca` y `X-Kora-Firma` sobre `marca.cuerpo`. El anexo E define en cambio `X-Clave-Id`, `X-Fecha` y `X-Firma` sobre el método, la ruta, la fecha y la huella del cuerpo.
- **Patrones con `\d`.** Python, y por tanto los generadores de Schemathesis, interpreta `\d` como cualquier dígito Unicode. PHP lo interpreta como un dígito ASCII.

## Decisión

- **Firma.** El contrato adopta la firma del **anexo E**. Firmar el método y la ruta impide reutilizar una firma en otra operación, y `X-Clave-Id` permite rotar el secreto sin cortar el servicio. Todavía no hay ningún cliente, así que el cambio no rompe nada.
- **Patrones.** Usan `[0-9]` en lugar de `\d`, para que el contrato signifique lo mismo en todos los lenguajes.
- **Simulador** (`tests/contrato/simulador.php`, con el servidor embebido de PHP). Usa la misma firma (`App\Integracion\FirmaIntegracion`) y la misma validación (`App\Integracion\ValidadorLoteVentas`) que usará RF-098, e implementa:
  - la idempotencia con bloqueo;
  - los errores RFC 9457;
  - el 503 con `Retry-After`.
- **Schemathesis 3.39.7** con Hypothesis fijado (`tests/contrato/requisitos.txt`). Un gancho firma cada solicitud generada. La CI ejecuta todas las comprobaciones con 150 ejemplos por operación y una semilla fija.

## Consecuencias

- **Positivas.** El equipo del software de ventas tiene un receptor para probar su cliente antes de que exista RF-098. La firma y la validación ya están escritas y probadas para RF-098.
- **Negativas.** Schemathesis 3.x marca OpenAPI 3.1 como experimental. Se fijan las versiones para que una actualización no cambie el resultado sin aviso.

## Alternativas consideradas

- **Prism como simulador:** responde con ejemplos del esquema, pero no valida la firma ni la idempotencia, que son lo que más importa probar.
- **Conservar la firma de la I5:** se descartó porque contradice el anexo E y no protege el método ni la ruta.
