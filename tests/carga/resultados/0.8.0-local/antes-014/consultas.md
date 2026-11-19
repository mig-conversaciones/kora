# Inventario de consultas — 0.8.0 (MAMP local)

Medición en MySQL 5.7.24 sobre el dataset sintético «kora_carga» (salidas: 39.611, recaudos: 9.900, recepciones_lote: 9.900, gastos_ejecutados: 2.640, eventos_auditoria: 825.004, trabajos: 0).
Los planes son estimados (EXPLAIN de MySQL 5.7); en staging se repiten con ANALYZE FORMAT=JSON de MariaDB 10.6. Los tiempos son de un equipo de desarrollo: orientan, no demuestran el SLO (RNF-PERF-018).

## Consultas por solicitud (RNF-PERF-009)

| Escenario | Consultas en frío / caliente | Máximo | Tiempo en frío / caliente | Cumple |
|---|---|---|---|---|
| Tablero central (centro) | 6 / 5 | 15 | 224,3 / 24,1 ms | Sí |
| Tablero central (centro, filtro de fechas, HTMX) | 6 / 5 | 15 | 95,3 / 42,8 ms | Sí |
| Tablero central (líder de GAN) | 6 / 5 | 15 | 89,1 / 22,2 ms | Sí |
| Tablero técnico GAN (líder de GAN) | 5 / 5 | 15 | 50,0 / 45,2 ms | Sí |
| Listado de salidas (centro) | 8 / 8 | 10 | 76,7 / 83,7 ms | Sí |
| Listado de salidas, página 5 (líder de GAN) | 8 / 8 | 10 | 67,8 / 75,7 ms | Sí |
| Listado de recepciones (centro) | 7 / 7 | 10 | 77,0 / 31,2 ms | Sí |
| Listado de recaudos (Grupo de Recaudo) | 7 / 7 | 10 | 35,2 / 27,0 ms | Sí |
| Bandeja de clasificación de recaudos | 8 / 8 | 10 | 31,7 / 24,8 ms | Sí |
| Listado de gastos (centro) | 6 / 6 | 10 | 49,9 / 38,7 ms | Sí |
| Formulario de salida nueva (líder de GAN) | 5 / 5 | 6 | 57,0 / 45,5 ms | Sí |
| Reclamo de la cola (predeterminada) | 2 | — | 23,6 ms | — |
| Recuperación de reservas vencidas | 2 | — | 0,4 ms | — |
| Auditoría, primera página | 1 | — | 53,4 ms | — |
| Auditoría por acción | 1 | — | 14,3 ms | — |

## Planes de las lecturas distintas

| # | Tablas: acceso, índice y filas estimadas | Extra relevante | Tiempo máx. | Observación |
|---|---|---|---|---|
| 1 `c01e1c1df2dd` | sesiones: const, PRIMARY, 1 | — | 0,2 ms | Sin alertas |
| 2 `79756d7ffaf7` | usuarios: const, PRIMARY, 1 | — | 0,2 ms | Sin alertas |
| 3 `3acd2068749e` | unidades_productivas: index, PRIMARY, 15 | — | 1,0 ms | Sin alertas |
| 4 `79393a87ec86` | versiones_datos: const, PRIMARY, 1 | — | 0,1 ms | Sin alertas |
| 5 `f22ce79fe81e` | <derived2>: ALL, sin índice, 271<br>p: eq_ref, PRIMARY, 1<br>r: ref, uq_recaudos_clave, 4.920<br>s: ref, uq_salidas_clave, 19.599<br><union2,3>: ALL, sin índice, 0 | Using filesort, Using temporary | 173,9 ms | **temporal en r; temporal en s** |
| 6 `c9ba161ca1a6` | sesiones: range, PRIMARY, 1 | — | 1,1 ms | Sin alertas |
| 7 `e4e08f8b49a2` | unidades_productivas: const, PRIMARY, 1 | — | 0,1 ms | Sin alertas |
| 8 `4fecac1fc628` | <derived2>: ALL, sin índice, 38<br>p: eq_ref, PRIMARY, 1<br>r: ref, fk_recaudos_unidad, 3.075<br>s: ref, uq_salidas_clave, 19.599<br><union2,3>: ALL, sin índice, 0 | Using filesort, Using temporary | 38,4 ms | **temporal en r; temporal en s** |
| 9 `efb7b68666ec` | s: ref, uq_salidas_clave, 19.599 | Using filesort, Using index | 43,4 ms | **filesort en s** |
| 10 `aa1519f4b6fa` | productos: ALL, sin índice, 33 | — | 0,6 ms | Sin alertas |
| 11 `1886f6603227` | recepciones_lote: range, PRIMARY, 20 | — | 1,8 ms | Sin alertas |
| 12 `72fc8b0f8aa1` | procesos_autoconsumo: range, PRIMARY, 2 | — | 0,4 ms | Sin alertas |
| 13 `242c744c1637` | unidades_productivas: ALL, sin índice, 15 | Using filesort | 0,4 ms | Sin alertas |
| 14 `27b1e697d1a9` | s: ref, uq_salidas_clave, 19.599 | Using filesort, Using index | 48,0 ms | **filesort en s** |
| 15 `1d0faf5126c8` | recepciones_lote: range, PRIMARY, 22 | — | 0,9 ms | Sin alertas |
| 16 `fe41a3ebf87f` | procesos_autoconsumo: const, PRIMARY, 1 | — | 0,1 ms | Sin alertas |
| 17 `cc77aab2554d` | r: ref, uq_recepciones_lote_producto, 5.010 | Using filesort, Using index | 47,3 ms | **filesort en r** |
| 18 `5269ffc2dba3` | salidas: range, idx_salidas_recepcion, 94 | Using index | 2,7 ms | Sin alertas |
| 19 `9e7c68bd7f69` | r: range, PRIMARY, 25<br>p: eq_ref, PRIMARY, 1 | — | 0,3 ms | Sin alertas |
| 20 `1ff92162680e` | rc: ref, uq_recaudos_clave, 4.920 | Using filesort, Using index | 8,5 ms | **filesort en rc** |
| 21 `535193e9f735` | productos: ALL, sin índice, 33 | Using filesort | 0,2 ms | Sin alertas |
| 22 `57448f64e692` | recaudos: range, idx_recaudos_clasificacion, 272 | Using filesort, Using index | 0,9 ms | Sin alertas |
| 23 `ad1835c5ace7` | unidades_productivas: ALL, sin índice, 15 | — | 0,1 ms | Sin alertas |
| 24 `5cbe57dba30f` | salidas: range, idx_salidas_factura, 1.200 | Using filesort, Using index | 7,1 ms | **filesort en salidas** |
| 25 `59acac7f5eda` | categorias_gasto: index, PRIMARY, 6 | — | 0,8 ms | Sin alertas |
| 26 `062f6cd5c749` | g: ref, idx_gastos_listado, 2.400 | Using filesort, Using index | 3,3 ms | **filesort en g** |
| 27 `1db83df577ee` | p: ALL, sin índice, 33<br>r: range, idx_recepciones_tablero, 3.220<br>s: ref, idx_salidas_recepcion, 4 | Using filesort, Using temporary, Using index | 38,8 ms | Sin alertas |
| 28 `17507a62f5aa` | p: ref, fk_procesos_unidad, 3 | Using filesort, Using index | 0,4 ms | Sin alertas |
| 29 `cbd14fcc9677` | trabajos: range, idx_trabajos_reclamo, 1 | Using temporary | 0,7 ms | Sin alertas |
| 30 `a835f713c6bb` | trabajos: ref, idx_trabajos_vencidos, 1 | — | 0,1 ms | Sin alertas |
| 31 `ed4664320535` | trabajos: range, idx_trabajos_vencidos, 1 | Using index | 0,1 ms | Sin alertas |
| 32 `1076299de923` | trabajos: range, idx_trabajos_vencidos, 1 | Using temporary | 0,1 ms | Sin alertas |
| 33 `c0abf7bf3ebc` | e: range, idx_auditoria_fecha, 11.176<br>u: ALL, sin índice, 1 | Using filesort, Using temporary, Using index | 50,8 ms | **filesort en e; temporal en e** |
| 34 `27805ce2df2d` | e: range, idx_auditoria_fecha, 11.176<br>u: ALL, sin índice, 1 | Using filesort, Using temporary, Using index | 13,9 ms | **filesort en e; temporal en e** |

El texto completo de cada consulta y su plan están en `consultas.json`, con la misma huella.

## Costo en escritura (H.1, paso 4)

Inserción de 1.000 salidas en bloques de 250, dentro de una transacción revertida (mediana de 5): **33,7 ms**, con 11 índices en `salidas` (PRIMARY, uq_salidas_clave, uq_salidas_externo, idx_salidas_tablero, idx_salidas_recepcion, idx_salidas_proceso, idx_salidas_factura, fk_salidas_unidad, fk_salidas_recepcion, fk_salidas_producto, fk_salidas_lote).
