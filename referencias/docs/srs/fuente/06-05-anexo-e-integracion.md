## Anexo E. Contrato de integración con el software de ventas

Los comentarios de la contraparte en la guía indican que la base de producción y ventas «se espera que la genere el software de ventas que estamos desarrollando». Este contrato fija cómo entregará esos datos, para que ambos desarrollos avancen en paralelo:

- **Versión 1.0:** contrato y simulador, con pruebas de contrato (RF-095), más el intercambio por archivo equivalente (RF-096).
- **Versión posterior:** API operativa (RF-098, prioridad Futuro).

**Reglas del contrato:**

- Autenticación HMAC-SHA256 con clave por cliente, idempotencia por lote y errores con el formato RFC 9457.
- Las claves naturales evitan cualquier unión por nombre o fecha (RF-097).
- En mantenimiento se responde 503 con `Retry-After`, y nunca se confirma la recepción sin haberla persistido (RF-MAN-015).

**Firma de la solicitud.** La cabecera `X-Firma` lleva `base64(HMAC-SHA256(secreto_cliente, metodo + "\n" + ruta + "\n" + X-Fecha + "\n" + hex(SHA-256(cuerpo))))`. `X-Fecha` va en formato ISO 8601 UTC y se rechazan diferencias de más de 5 min, para evitar la repetición. `X-Clave-Id` identifica el secreto y permite rotarlo.

```yaml archivo=docs/api/integracion-ventas.yaml
openapi: 3.1.0
info:
  title: Producción CAB — Integración con el software de ventas
  version: 1.0.0
  description: >
    Contrato para recibir recepciones de lotes y salidas (ventas y autoconsumos) desde el software de ventas del
    centro. En la versión 1.0 se valida contra un simulador (RF-095); la implementación operativa es RF-098.
servers:
  - url: https://produccion.dominio-del-centro.gov.co/api/v1
security:
  - firmaHmac: []
paths:
  /integracion/ventas/lotes:
    post:
      summary: Entrega un lote de recepciones de producción (hasta 500 registros)
      operationId: entregarRecepciones
      parameters:
        - $ref: '#/components/parameters/ClaveIdempotencia'
        - $ref: '#/components/parameters/Fecha'
        - $ref: '#/components/parameters/Firma'
        - $ref: '#/components/parameters/ClaveId'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [centro, recepciones]
              properties:
                centro: { type: string, example: '9124' }
                recepciones:
                  type: array
                  minItems: 1
                  maxItems: 500
                  items: { $ref: '#/components/schemas/Recepcion' }
      responses:
        '202': { $ref: '#/components/responses/LoteAceptado' }
        '400': { $ref: '#/components/responses/Problema' }
        '401': { $ref: '#/components/responses/Problema' }
        '409': { $ref: '#/components/responses/Problema' }
        '413': { $ref: '#/components/responses/Problema' }
        '422': { $ref: '#/components/responses/Problema' }
        '429': { $ref: '#/components/responses/Reintentar' }
        '503': { $ref: '#/components/responses/Reintentar' }
  /integracion/ventas/salidas:
    post:
      summary: Entrega un lote de salidas (venta directa y autoconsumo, hasta 500 registros)
      operationId: entregarSalidas
      parameters:
        - $ref: '#/components/parameters/ClaveIdempotencia'
        - $ref: '#/components/parameters/Fecha'
        - $ref: '#/components/parameters/Firma'
        - $ref: '#/components/parameters/ClaveId'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [centro, salidas]
              properties:
                centro: { type: string, example: '9124' }
                salidas:
                  type: array
                  minItems: 1
                  maxItems: 500
                  items: { $ref: '#/components/schemas/Salida' }
      responses:
        '202': { $ref: '#/components/responses/LoteAceptado' }
        '400': { $ref: '#/components/responses/Problema' }
        '401': { $ref: '#/components/responses/Problema' }
        '409': { $ref: '#/components/responses/Problema' }
        '422': { $ref: '#/components/responses/Problema' }
        '429': { $ref: '#/components/responses/Reintentar' }
        '503': { $ref: '#/components/responses/Reintentar' }
  /integracion/ventas/lotes/{lote}:
    get:
      summary: Estado del procesamiento de un lote entregado
      operationId: consultarLote
      parameters:
        - name: lote
          in: path
          required: true
          schema: { type: string, pattern: '^[0-9A-HJKMNP-TV-Z]{26}$' }
        - $ref: '#/components/parameters/Fecha'
        - $ref: '#/components/parameters/Firma'
        - $ref: '#/components/parameters/ClaveId'
      responses:
        '200':
          description: Estado del lote
          content:
            application/json:
              schema: { $ref: '#/components/schemas/EstadoLote' }
        '404': { $ref: '#/components/responses/Problema' }
components:
  securitySchemes:
    firmaHmac:
      type: apiKey
      in: header
      name: X-Clave-Id
      description: Identificador de la clave; la solicitud se firma en X-Firma con HMAC-SHA256 (ver texto del anexo).
  parameters:
    ClaveIdempotencia:
      name: Idempotency-Key
      in: header
      required: true
      description: Igual clave y mismo contenido devuelven el lote original; igual clave con otro contenido devuelve 422.
      schema: { type: string, minLength: 16, maxLength: 120 }
    Fecha:
      name: X-Fecha
      in: header
      required: true
      schema: { type: string, format: date-time }
    Firma:
      name: X-Firma
      in: header
      required: true
      schema: { type: string }
    ClaveId:
      name: X-Clave-Id
      in: header
      required: true
      schema: { type: string }
  responses:
    LoteAceptado:
      description: Lote persistido y encolado para validación (202 solo después de persistir)
      headers:
        Location: { schema: { type: string }, description: URL del estado del lote }
        Retry-After: { schema: { type: integer } }
      content:
        application/json:
          schema:
            type: object
            required: [lote, estado]
            properties:
              lote: { type: string }
              estado: { type: string, enum: [recibido] }
              duplicado: { type: boolean }
    Problema:
      description: Error con detalle por registro (RFC 9457)
      content:
        application/problem+json:
          schema: { $ref: '#/components/schemas/Problema' }
    Reintentar:
      description: Límite de frecuencia o mantenimiento; el emisor debe reintentar tras Retry-After
      headers:
        Retry-After: { schema: { type: integer } }
      content:
        application/problem+json:
          schema: { $ref: '#/components/schemas/Problema' }
  schemas:
    Recepcion:
      type: object
      required: [numero_interno, codigo_unidad, fecha_recepcion, codigo_sgac, cantidad, medida]
      properties:
        numero_interno: { type: string, pattern: '^[A-Z]{3}-(REC|DEV)-\d{6}-\d{3}$', example: GAN-REC-210126-001 }
        codigo_unidad: { type: string, example: GAN }
        fecha_recepcion: { type: string, format: date }
        codigo_sgac: { type: string, example: '5013180150' }
        cantidad: { type: number, exclusiveMinimum: 0 }
        medida: { type: string, enum: [L, kg, ud, lb, cubeta] }
        precio_sugerido: { type: number, minimum: 0 }
        responsable: { type: string, description: Correo institucional del usuario que recibe }
    Salida:
      type: object
      required: [id_externo, numero_interno, fecha, codigo_sgac, cantidad, medida, precio_unitario, canal]
      properties:
        id_externo: { type: string, description: Identificador único en el software de ventas (RF-097) }
        numero_interno: { type: string, description: Número interno del lote de origen }
        fecha: { type: string, format: date }
        codigo_sgac: { type: string }
        cantidad: { type: number, exclusiveMinimum: 0 }
        medida: { type: string, enum: [L, kg, ud, lb, cubeta] }
        precio_unitario: { type: number, minimum: 0 }
        canal: { type: string, enum: [VENTA_DIRECTA, AUTOCONSUMO] }
        codigo_proceso: { type: string, pattern: '^AUTO_\d{3}$', description: Obligatorio si canal = AUTOCONSUMO }
        destino: { type: string }
        factura: { type: string }
        prefactura: { type: string, description: Permite conciliar con el recaudo de la DG (RF-032) }
        referencia_transaccion: { type: string }
    EstadoLote:
      type: object
      properties:
        lote: { type: string }
        estado: { type: string, enum: [recibido, validando, aplicado, con_errores, fallido] }
        registros_aplicados: { type: integer }
        errores:
          type: array
          items:
            type: object
            properties:
              indice: { type: integer }
              campo: { type: string }
              mensaje: { type: string }
    Problema:
      type: object
      properties:
        type: { type: string, format: uri }
        title: { type: string }
        status: { type: integer }
        detail: { type: string }
        instance: { type: string }
        errores:
          type: array
          items:
            type: object
            properties:
              indice: { type: integer }
              campo: { type: string }
              mensaje: { type: string }
```

**Intercambio por archivo equivalente (RF-096).** Mientras no exista el API, el software de ventas genera dos hojas en un XLSX o dos CSV en UTF-8. Las columnas tienen exactamente los nombres de los esquemas `Recepcion` y `Salida`. El archivo se carga con el módulo de importación, en las fuentes «base de producción» y «base de ventas», usando el formato de contrato. La clave `id_externo` sustituye a la clave compuesta con número de ocurrencia de la fuente heredada (RF-089).
