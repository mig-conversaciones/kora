# Runbook: operación productiva, hato, calidad y recaudos

Se aplica a la plataforma de la I4. Requisitos: RF-029, RF-035, RF-039 a RF-061. Decisiones: [ADR-007](../adr/007-autorizacion-hibrida-alcance.md), [ADR-017](../adr/017-modulos-de-gestion.md) y [ADR-018](../adr/018-operacion-productiva.md).

## 1. Puesta en marcha

```bash
php cli/migrar.php --aplicar   # hasta 012_crear_calidad_exportaciones
```

- **Migración 010:** recepciones de lotes, salidas, transformaciones y conciliaciones de recaudo.
- **Migración 011:** animales, cortes del hato y registros reproductivos.
- **Migración 012:** incidencias de calidad y exportaciones (las exportaciones se usan desde la I6).
- Con la 010 se activan solas las reglas de bloqueo de la I3: una unidad con lotes o salidas activos no se inactiva, un producto con recepciones no cambia de medida y un proceso con entregas no cambia su unidad, medida, producto ni vigencia.

## 2. Quién hace qué

| Pantalla | Ruta | Consulta | Edita |
|---|---|---|---|
| Recepciones y lotes | `/produccion/recepciones` | `recepciones.consultar`, con el alcance de cada uno | Líder de unidad y Líder de producción de centro |
| Salidas | `/produccion/salidas` | `salidas.consultar` | Ídem (`salidas.gestionar`) |
| Transformación láctea | `/produccion/transformaciones` | `transformaciones.consultar`; el líder de origen ve el resultado aunque el derivado sea de otra unidad | Líderes y Coordinación académica (`transformaciones.gestionar`) |
| Hato | `/hato` | `hato.consultar` | Líder de la unidad pecuaria (`hato.gestionar`). La Coordinación académica solo el lote de práctica (`hato.gestionar_practica`) |
| Recaudos | `/recaudos` | Todos con `recaudos.consultar`; un recaudo sin unidad solo con alcance de centro | Solo el Grupo de Recaudo |
| Calidad de datos | `/calidad` | `calidad.consultar` | `calidad.gestionar` |

## 3. Lotes y salidas

- **Número interno:** `{PREFIJO}-REC-{DDMMAA}-{NNN}`. El formulario sugiere el siguiente consecutivo al elegir la unidad y la fecha. Si dos personas guardan el mismo número y producto a la vez, la segunda recibe «Ese número interno ya tiene una recepción de este producto»: basta con tomar el siguiente.
- **Saldo:** recibido − salidas activas. El lote se cierra con la salida que lo agota; el detalle muestra la fecha de cierre.

| Mensaje | Qué hacer |
|---|---|
| «La entrega supera el saldo del lote (X L)» | Registrar solo el saldo o usar otro lote |
| «Vincula la entrega con un proceso de autoconsumo» | Crear o elegir el proceso AUTO_xxx de la unidad, la medida y la vigencia |
| «La recepción no puede ser menor que las cantidades ya entregadas» | Archivar primero la salida equivocada |
| «Este lote tiene salidas. Conserva su producto, unidad, medida y número interno» | Archivar las salidas o registrar una recepción nueva |
| 409 «El lote tiene salidas o transformaciones activas» al archivar | Archivar primero esas salidas o transformaciones |

- **La venta directa no crea recaudos.** El recaudo lo registra el Grupo de Recaudo y se cruza con la venta por conciliación.

## 4. Transformación láctea

1. Se elige la entrega de leche por autoconsumo, en litros, con litros disponibles.
2. Se elige el derivado configurado y se registra su recepción en el mismo formulario, o se vincula una ya registrada.
3. Si una regla falla, no queda ni la transformación ni la recepción del derivado.

- **«No hay derivados lácteos configurados»:** configurar en `/catalogos/productos?derivados=1` el factor de leche de los derivados (S-12).
- **«No hay entregas de leche disponibles»:** registrar la entrega de leche por autoconsumo desde su lote.
- **Rendimiento:** litros asignados / cantidad del derivado. Está «En meta» si es igual o menor que el máximo del rango del catálogo.

## 5. Hato

- **Cortes:** cada corte es una fotografía fechada. «Nuevo corte» copia el estado del vigente, recalcula los días abiertos con la nueva fecha y pasa a ser el vigente. El anterior se conserva para consulta.
- **Cálculos en el servidor:** IEP = último parto − penúltimo parto; días abiertos = servicio efectivo (o la fecha del corte) − último parto.
- **Semáforos:** IEP verde hasta 365 días, ámbar de 366 a 400 y rojo desde 401. Días abiertos sin umbral por debajo de 91, verde de 91 a 120, ámbar de 121 a 135 y rojo desde 136.
- **Lote de práctica:** la Coordinación académica marca o retira el animal con un motivo de al menos 5 caracteres. Queda en la auditoría (`hato.gestionar_practica`).

## 6. Bandeja de calidad

- **Recalcular:** «Recalcular la bandeja» encola `calidad.recalcular`, que el trabajador procesa en el siguiente minuto. Desde la I5 se encola también tras cada importación.
- **Correcciones:** vincular una salida heredada a un lote (valida unidad, medida, fecha y saldo), asignar un proceso a una entrega de autoconsumo, o aceptar sin corrección con un motivo de al menos 10 caracteres. Cada corrección queda auditada con el antes y el después y sube la versión de datos del centro, lo que invalida la caché de los tableros.
- **Homologación y factores:** la incidencia enlaza al catálogo, donde se corrigen.

## 7. Recaudos

- **Consulta:** filtros por fecha, unidad, producto, pasarela, clasificación y conciliación, con orden por fecha o valor.
- **Registro manual:** solo el Grupo de Recaudo. La identificación del pagador se guarda como huella (HMAC) y los últimos 4 dígitos.
- **Duplicados:** la clave natural (cupón, prefactura, factura, valor y fecha) impide registrar dos veces el mismo pago.
- **Importación del archivo de la DG:** I5.

## 8. Cola en MariaDB

Si el registro muestra `Deadlock found when trying to get lock` en el trabajador, es un interbloqueo esperado entre dos trabajadores: el reclamo se reintenta solo, hasta cinco veces (ADR-018). Solo hay que investigar si el aviso se repite en cada ejecución.
