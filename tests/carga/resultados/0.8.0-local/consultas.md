# Inventario de consultas — 0.8.0 (MAMP local)

Medición en MySQL 5.7.24 sobre el dataset sintético «kora_carga» (salidas: 39.611, recaudos: 9.900, recepciones_lote: 9.900, gastos_ejecutados: 2.640, eventos_auditoria: 825.012, trabajos: 0).
Los planes son estimados (EXPLAIN de MySQL 5.7); en staging se repiten con ANALYZE FORMAT=JSON de MariaDB 10.6. Los tiempos son de un equipo de desarrollo: orientan, no demuestran el SLO (RNF-PERF-018).

## Consultas por solicitud (RNF-PERF-009)

| Escenario | Consultas en frío / caliente | Máximo | Tiempo en frío / caliente | Cumple |
|---|---|---|---|---|
| Tablero central (centro) | 14 / 5 | 15 | 326,9 / 39,7 ms | Sí |
| Tablero central (centro, filtro de fechas, HTMX) | 14 / 5 | 15 | 156,4 / 29,6 ms | Sí |
| Tablero central (líder de GAN) | 14 / 5 | 15 | 118,5 / 21,7 ms | Sí |
| Tablero técnico GAN (líder de GAN) | 13 / 5 | 15 | 114,6 / 29,2 ms | Sí |
| Listado de salidas (centro) | 8 / 8 | 10 | 50,2 / 22,5 ms | Sí |
| Listado de salidas, página 5 (líder de GAN) | 8 / 8 | 10 | 20,3 / 18,0 ms | Sí |
| Listado de recepciones (centro) | 7 / 7 | 10 | 31,9 / 32,0 ms | Sí |
| Listado de recaudos (Grupo de Recaudo) | 7 / 7 | 10 | 25,2 / 42,2 ms | Sí |
| Bandeja de clasificación de recaudos | 8 / 8 | 10 | 32,2 / 36,1 ms | Sí |
| Listado de gastos (centro) | 6 / 6 | 10 | 53,7 / 19,3 ms | Sí |
| Formulario de salida nueva (líder de GAN) | 5 / 5 | 6 | 85,4 / 50,9 ms | Sí |
| Reclamo de la cola (predeterminada) | 2 | — | 3,9 ms | — |
| Recuperación de reservas vencidas | 2 | — | 0,5 ms | — |
| Auditoría, primera página | 1 | — | 5,9 ms | — |
| Auditoría por acción | 1 | — | 5,0 ms | — |

## Planes de las lecturas distintas

| # | Tablas: acceso, índice y filas estimadas | Extra relevante | Tiempo máx. | Observación |
|---|---|---|---|---|
| 1 `c01e1c1df2dd` | sesiones: const, PRIMARY, 1 | — | 0,2 ms | Sin alertas |
| 2 `79756d7ffaf7` | usuarios: const, PRIMARY, 1 | — | 0,2 ms | Sin alertas |
| 3 `3acd2068749e` | unidades_productivas: index, PRIMARY, 15 | — | 0,8 ms | Sin alertas |
| 4 `79393a87ec86` | versiones_datos: const, PRIMARY, 1 | — | 0,2 ms | Sin alertas |
| 5 `f22ce79fe81e` | <derived2>: ALL, sin índice, 271<br>p: eq_ref, PRIMARY, 1<br>r: ref, uq_recaudos_clave, 4.920<br>s: ref, uq_salidas_clave, 19.621<br><union2,3>: ALL, sin índice, 0 | Using filesort, Using temporary | 198,4 ms | **temporal en r; temporal en s** |
| 6 `74adcc8dcfb2` | a: index, uq_asignaciones_activa, 42 | — | 1,1 ms | Sin alertas |
| 7 `c9de91ed217a` | r: range, idx_recaudos_tablero, 4.920 | Using filesort, Using temporary, Using index | 5,1 ms | **filesort en r; temporal en r** |
| 8 `db70ec9bc58b` | s: range, idx_salidas_tablero, 1.410 | Using filesort, Using temporary, Using index | 3,9 ms | **filesort en s; temporal en s** |
| 9 `a6682e078cb2` | s: range, idx_salidas_tablero, 19.621 | Using index | 10,4 ms | Sin alertas |
| 10 `884c6ee91bad` | r: ref, idx_recaudos_tablero, 4.920 | Using index | 4,8 ms | Sin alertas |
| 11 `6061918f8183` | p: ALL, sin índice, 18<br>u: eq_ref, PRIMARY, 1<br>d: eq_ref, PRIMARY, 1<br>pr: eq_ref, PRIMARY, 1 | Using filesort | 0,9 ms | Sin alertas |
| 12 `605869147410` | s: range, idx_salidas_proceso, 1.410 | Using filesort, Using index | 3,8 ms | **filesort en s** |
| 13 `51feb6b00a9f` | <derived2>: system, sin índice, 1<br><derived3>: system, sin índice, 1<br><derived4>: system, sin índice, 1<br>c: ref, uq_cortes_fecha, 1<br>s: ref, uq_salidas_clave, 19.621<br>r: ref, idx_recaudos_unidad_listado, 4.920 | Using index | 41,5 ms | Sin alertas |
| 14 `c9ba161ca1a6` | sesiones: range, PRIMARY, 1 | — | 0,9 ms | Sin alertas |
| 15 `e4e08f8b49a2` | unidades_productivas: const, PRIMARY, 1 | — | 0,2 ms | Sin alertas |
| 16 `4fecac1fc628` | <derived2>: ALL, sin índice, 353<br>p: eq_ref, PRIMARY, 1<br>r: range, idx_recaudos_unidad_listado, 1.052<br>s: range, idx_salidas_unidad_listado, 4.283<br><union2,3>: ALL, sin índice, 0 | Using filesort, Using temporary, Using index | 14,7 ms | **temporal en r; temporal en s** |
| 17 `9c534627e677` | a: ref, uq_asignaciones_activa, 1 | — | 0,2 ms | Sin alertas |
| 18 `273b88269c51` | r: range, idx_recaudos_unidad_listado, 1.052 | Using index | 2,8 ms | Sin alertas |
| 19 `fcf3dc5cc260` | s: range, idx_salidas_tablero, 1.410 | Using index | 2,4 ms | Sin alertas |
| 20 `ad0b38f7bfc8` | s: range, idx_salidas_unidad_listado, 4.283 | Using index | 6,2 ms | Sin alertas |
| 21 `ed87e5a7cbcc` | r: range, idx_recaudos_unidad_listado, 1.052 | Using index | 1,5 ms | Sin alertas |
| 22 `801382efd38f` | u: const, PRIMARY, 1<br>p: ref, fk_procesos_unidad, 3<br>d: eq_ref, PRIMARY, 1<br>pr: eq_ref, PRIMARY, 1 | Using filesort, Using index | 0,3 ms | Sin alertas |
| 23 `d7ed1ebfc04d` | s: range, idx_salidas_proceso, 623 | Using filesort, Using index | 2,0 ms | Sin alertas |
| 24 `d74433b2336d` | <derived2>: system, sin índice, 1<br><derived3>: system, sin índice, 1<br><derived4>: system, sin índice, 1<br>c: ref, uq_cortes_fecha, 1<br>s: ref, uq_salidas_clave, 19.621<br>r: ref, idx_recaudos_unidad_listado, 3.075 | Using index | 38,1 ms | Sin alertas |
| 25 `6ded83095158` | <derived2>: ALL, sin índice, 2<br>p: eq_ref, uq_parametros_vigencia, 1<br>parametros_indicadores: ref, uq_parametros_vigencia, 6 | Using index | 0,9 ms | Sin alertas |
| 26 `6a3ac54cdeda` | cortes_hato: ref, uq_cortes_fecha, 1 | — | 0,1 ms | Sin alertas |
| 27 `0fc113af508b` | t: range, idx_transformaciones_tablero, 1<br>r: eq_ref, PRIMARY, 1<br>p: eq_ref, PRIMARY, 1<br>s: eq_ref, PRIMARY, 1 | Using index | 0,7 ms | Sin alertas |
| 28 `be6dab6d559e` | r: range, idx_recepciones_tablero, 1.057<br>p: eq_ref, PRIMARY, 1 | Using index | 12,3 ms | Sin alertas |
| 29 `aae3ae5d67e8` | salidas: range, idx_salidas_recepcion, 3.991 | Using index | 14,2 ms | Sin alertas |
| 30 `0c3ab98ecf4a` | c: ALL, sin índice, 6<br>g: range, idx_gastos_listado, 288 | Using index | 3,1 ms | Sin alertas |
| 31 `8f026219b08b` | r: range, idx_recepciones_tablero, 1.057<br>p: eq_ref, PRIMARY, 1 | Using index | 2,0 ms | Sin alertas |
| 32 `efb7b68666ec` | s: ref, idx_salidas_listado, 19.621 | — | 0,4 ms | Sin alertas |
| 33 `aa1519f4b6fa` | productos: ALL, sin índice, 42 | — | 0,6 ms | Sin alertas |
| 34 `1886f6603227` | recepciones_lote: range, PRIMARY, 20 | — | 0,1 ms | Sin alertas |
| 35 `72fc8b0f8aa1` | procesos_autoconsumo: range, PRIMARY, 2 | — | 0,1 ms | Sin alertas |
| 36 `242c744c1637` | unidades_productivas: ALL, sin índice, 15 | Using filesort | 0,2 ms | Sin alertas |
| 37 `27b1e697d1a9` | s: ref, idx_salidas_listado, 19.621 | — | 1,2 ms | Sin alertas |
| 38 `1d0faf5126c8` | recepciones_lote: range, PRIMARY, 22 | — | 0,1 ms | Sin alertas |
| 39 `fe41a3ebf87f` | procesos_autoconsumo: const, PRIMARY, 1 | — | 0,1 ms | Sin alertas |
| 40 `cc77aab2554d` | r: ref, idx_recepciones_listado, 5.010 | — | 2,8 ms | Sin alertas |
| 41 `5269ffc2dba3` | salidas: range, idx_salidas_recepcion, 94 | Using index | 0,4 ms | Sin alertas |
| 42 `9e7c68bd7f69` | r: range, PRIMARY, 25<br>p: eq_ref, PRIMARY, 1 | — | 0,2 ms | Sin alertas |
| 43 `1ff92162680e` | rc: ref, idx_recaudos_listado, 4.920 | — | 0,3 ms | Sin alertas |
| 44 `535193e9f735` | productos: ALL, sin índice, 42 | Using filesort | 0,3 ms | Sin alertas |
| 45 `57448f64e692` | recaudos: range, idx_recaudos_clasificacion, 272 | Using filesort, Using index | 0,9 ms | Sin alertas |
| 46 `ad1835c5ace7` | unidades_productivas: ALL, sin índice, 15 | — | 0,1 ms | Sin alertas |
| 47 `5cbe57dba30f` | salidas: range, idx_salidas_factura, 1.200 | Using filesort, Using index | 7,5 ms | **filesort en salidas** |
| 48 `59acac7f5eda` | categorias_gasto: index, PRIMARY, 6 | — | 0,5 ms | Sin alertas |
| 49 `062f6cd5c749` | g: ref, idx_gastos_listado, 2.400 | Using filesort, Using index | 1,7 ms | **filesort en g** |
| 50 `1db83df577ee` | p: ALL, sin índice, 42<br>r: range, idx_recepciones_tablero, 3.220<br>s: ref, idx_salidas_recepcion, 4 | Using filesort, Using temporary, Using index | 52,7 ms | Sin alertas |
| 51 `17507a62f5aa` | p: ref, fk_procesos_unidad, 3 | Using filesort, Using index | 0,2 ms | Sin alertas |
| 52 `cbd14fcc9677` | trabajos: range, idx_trabajos_reclamo, 1 | Using temporary | 0,9 ms | Sin alertas |
| 53 `a835f713c6bb` | trabajos: ref, idx_trabajos_vencidos, 1 | — | 0,2 ms | Sin alertas |
| 54 `ed4664320535` | trabajos: range, idx_trabajos_vencidos, 1 | Using index | 0,1 ms | Sin alertas |
| 55 `1076299de923` | trabajos: range, idx_trabajos_vencidos, 1 | Using temporary | 0,1 ms | Sin alertas |
| 56 `c4f974d75dd7` | e: range, idx_auditoria_fecha, 11.176<br>u: eq_ref, PRIMARY, 1 | Using index | 3,3 ms | Sin alertas |
| 57 `ad60ff057d74` | e: range, idx_auditoria_fecha, 11.176<br>u: eq_ref, PRIMARY, 1 | Using index | 4,7 ms | Sin alertas |

El texto completo de cada consulta y su plan están en `consultas.json`, con la misma huella.

## Costo en escritura (H.1, paso 4)

Inserción de 1.000 salidas en bloques de 250, dentro de una transacción revertida (mediana de 5): **33,7 ms**, con 13 índices en `salidas` (PRIMARY, uq_salidas_clave, uq_salidas_externo, idx_salidas_tablero, idx_salidas_recepcion, idx_salidas_proceso, idx_salidas_factura, fk_salidas_recepcion, fk_salidas_producto, fk_salidas_lote, fk_salidas_unidad, idx_salidas_listado, idx_salidas_unidad_listado).
