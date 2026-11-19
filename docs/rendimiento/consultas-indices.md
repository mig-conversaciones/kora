# Inventario de consultas e índices (RNF-PERF-007 a RNF-PERF-011)

Anexo H, H.1. Decisión: [ADR-021](../adr/021-operacion-respaldos-despliegue.md).

- **Estado:** medido en local, en la I7. La medición en staging, con `ANALYZE FORMAT=JSON` de MariaDB 10.6, queda **PENDIENTE DE EJECUCIÓN** hasta que exista el entorno (S-10).
- **Detalle por consulta:** en [`tests/carga/resultados/0.8.0-local/consultas.md`](../../tests/carga/resultados/0.8.0-local/consultas.md), con su plan en `consultas.json`. La medición anterior a la migración 014 está en `antes-014/`.

## Procedimiento

1. Generar el dataset de 3 años en una base aislada:

   ```sh
   php cli/generar_dataset.php --base=kora_carga --escala=3a --preparar --cuentas=30 --usuarios-k6=almacenamiento/carga/usuarios.json
   ```

   El generador aplica `ANALYZE TABLE` al terminar.

2. Ejecutar el inventario:

   ```sh
   php tests/carga/inventario_consultas.php --base=kora_carga --usuarios=almacenamiento/carga/usuarios.json --vigencia=2026
   ```

   La herramienta:
   - entra con las cuentas de carga y recorre los tableros (en frío y en caliente), los listados y un formulario;
   - captura cada consulta con el observador de `BaseDatos`;
   - pasa por `EXPLAIN` cada lectura distinta, con sus mismos parámetros;
   - llama a la cola y a la auditoría;
   - mide el costo de escribir 1.000 salidas.
3. Aplicar el índice con una migración reversible (ida y vuelta) y repetir la medición.
4. En staging, repetir el paso 2 y registrar `ANALYZE FORMAT=JSON` de las consultas marcadas.

## Dataset de la medición

- **Base:** `kora_carga`, en MySQL 5.7.24 (MAMP) y PHP 8.3.36. Semilla 20261119, escala `3a`.
- **Volumen:** 39.611 salidas, 9.900 recaudos, 9.900 recepciones, 2.640 gastos, 42 asignaciones y 825.000 eventos de auditoría sellados, más 30 cuentas de carga.
- **Centros:** el 9124 y el 9999 (aislamiento).
- **Cadena de auditoría:** íntegra en todo el dataset.

## Consultas por solicitud (RNF-PERF-009)

| Solicitud | Máximo | Antes de la I7 (frío / caliente) | Después (frío / caliente) | Causa y corrección |
|---|---|---|---|---|
| Tablero central (centro o líder) | 15 | 22 / 11 | 14 / 5 | Permisos en caché (−3); unidades leídas una vez (−1); opciones de producto en una consulta y cacheadas (−2 en frío, −3 en caliente); estado de la información en una consulta (−2) |
| Tablero técnico GAN | 15 | **1.019** / 8 | 13 / 5 | **N+1 en IND_12**: una consulta de salidas por lote; ahora una sola con `IN`. Parámetros leídos una vez (de 6 a 1) |
| Listado de salidas | 10 | 12 | 8 | Permisos en caché; unidades del catálogo leídas una vez |
| Listado de recepciones | 10 | 11 | 7 | Permisos en caché |
| Listado de recaudos | 10 | 11 | 7 | Permisos en caché |
| Bandeja de clasificación de recaudos | 10 | **210** | 8 | **N+1**: una consulta de venta de referencia por recaudo; ahora una con `IN` sobre las facturas |
| Listado de gastos | 10 | 10 | 6 | Permisos en caché |
| Formulario de salida nueva | 6 | 8 | 5 | Permisos en caché |
| Reclamo de la cola y reservas vencidas | — | 2 y 2 | 2 y 2 | Sin cambio |

Cada solicitud autenticada paga ahora 3 consultas fijas: leer la sesión, la fila del usuario (estado y versión de permisos) y actualizar la actividad. Antes eran 6. `RendimientoOperacionTest` hace fallar la CI si una pantalla supera su máximo.

## Índices: antes y después (H.1)

Medición sobre el mismo dataset, después de `ANALYZE TABLE`. En MySQL 5.7, «filas» es la estimación de `EXPLAIN` y el tiempo es el de ejecución medido por el observador.

| Consulta | Índice | Filas examinadas (antes / después) | Tiempo (antes / después) | Plan (antes → después) | Decisión |
|---|---|---|---|---|---|
| Listado de salidas del centro: `centro_id`, `archivado_en IS NULL`, `ORDER BY fecha DESC, id DESC LIMIT 26` | `idx_salidas_listado (centro_id, archivado_en, fecha)` | 19.599 / 19.653 estimadas; lee 26 | 43,4 / 0,4 ms | clave natural con *filesort* → índice en orden, sin *filesort* | Migración 014 |
| Listado de salidas del líder: `unidad_id IN (…)`, página 5 | `idx_salidas_unidad_listado (unidad_id, archivado_en, fecha)` | 19.599 / 4.283 estimadas | 48,0 / 1,0 ms | *filesort* → índice en orden | Migración 014. Empieza por `unidad_id` para no reemplazar el índice de la llave foránea `fk_salidas_unidad` (la reversión no dependería de cómo se creó) |
| Listado de recepciones del centro | `idx_recepciones_listado (centro_id, archivado_en, fecha_recepcion)` | 5.010 / 5.010 estimadas; lee 26 | 47,3 / 0,5 ms | *filesort* → índice en orden | Migración 014 |
| Listado de recaudos del centro | `idx_recaudos_listado (centro_id, archivado_en, fecha_pago)` | 4.920 / 4.920 estimadas; lee 26 | 8,5 / 0,3 ms | *filesort* → índice en orden | Migración 014. Para el alcance de unidad: `idx_recaudos_unidad_listado` |
| Auditoría por fecha (rango de 7 días, página de 50) | `idx_auditoria_fecha (ocurrido_en, id)`, ya existente | 11.176 / 11.176 estimadas; lee 51 | 50,8 / 3,7 ms | join buffer con tabla temporal y *filesort* → orden del índice, `eq_ref` por actor | Sin índice nuevo: el nombre del actor pasa a una subconsulta escalar (ServicioConsultaAuditoria) |
| Bandeja: ventas por factura (`factura IN (…)`) | `idx_salidas_factura`, ya existente | 1.200 / 1.200 | 7,1 / 3,6 ms | rango con *filesort* pequeño | Aceptado |
| Opciones del filtro de producto (`UNION` de recaudos y salidas de la vigencia) | Ninguno nuevo | 19.599 + 4.920 | 173,9 / 43,3 ms | temporal para el `DISTINCT` | Aceptado: se cachea por versión de datos y se precalienta. Reevaluar en staging |
| Agregados de IND_001 (recaudos y salidas por unidad del periodo) | `idx_recaudos_tablero`, `idx_salidas_tablero` (cobertura), ya existentes | 4.920 y 1.410 | 3,0 ms | rango por índice de cobertura con temporal para el `GROUP BY unidad_id` | Aceptado: sin lectura de filas |
| Entregas por proceso (IND_002) | `idx_salidas_proceso`, ya existente | 1.410 | 2,7 ms | rango con *filesort* pequeño | Aceptado |
| Reclamo de la cola | `idx_trabajos_reclamo`, ya existente | — | 3,5 ms | — | Medir en staging con un millón de trabajos históricos (4.6.1) |

**Costo en escritura** (H.1, paso 4). Insertar 1.000 salidas en bloques de 250, en una transacción revertida, da la mediana de 5 repeticiones: 33,7 ms con 11 índices en `salidas` y entre 33,7 y 40,4 ms con 13. Es una diferencia dentro del ruido del equipo, así que se acepta.

## Reglas que quedan en el código

- Los indicadores se agregan en SQL. Las relaciones se cargan en bloque con `IN` (rotación, bandeja de clasificación, listados).
- Los valores que una solicitud pide varias veces se leen una sola vez:
  - la versión de datos y las unidades del catálogo, durante 1 s, para que el trabajador, que vive más, no use valores viejos;
  - los parámetros y las unidades del alcance, durante la solicitud.
- Las condiciones de alcance que se combinan en una sola consulta se renombran con un sufijo (`ServicioIndicadores::sufijar`). Con sentencias preparadas nativas, un marcador con nombre no se puede repetir.
- Ningún listado carece de `LIMIT`. Los listados ordenan por la fecha del índice y desempatan por la clave primaria, que InnoDB añade a cada índice secundario.

## Pendiente en staging

- **MariaDB 10.6:** `ANALYZE FORMAT=JSON` de las consultas de la tabla anterior, con las filas reales examinadas.
- **Cola:** el reclamo con un millón de trabajos históricos.
- **Volumen y concurrencia:** el tablero central con 5 req/s (k6, `plan-carga.md`).
