# Medición local del tiempo de servidor — 0.8.0

**Indicativa, no es la prueba de carga** (RNF-PERF-018): en proceso, sin Apache ni red, una solicitud a la vez, en el equipo de desarrollo (MySQL 5.7.24, PHP 8.3.36) sobre el dataset «kora_carga». 30 repeticiones por flujo; «fría» sube la versión de datos antes de cada solicitud.

| Flujo | Fría p50 / p95 / p99 | Objetivo fría | Caliente p50 / p95 / p99 | Objetivo caliente |
|---|---|---|---|---|
| Tablero central (centro) | 155,8 / 171,6 / 176,5 ms | 1.200 ms | 20,4 / 32,3 / 39,6 ms | 500 ms |
| Tablero central (líder de GAN) | 100,2 / 128,2 / 150,7 ms | 1.200 ms | 20,5 / 29,4 / 37,7 ms | 500 ms |
| Tablero técnico GAN | 85,1 / 104,1 / 108,2 ms | 1.200 ms | 31,3 / 45,9 / 47,5 ms | 500 ms |
| Fragmento de filtro (IND_002 en unidades) | 81,1 / 93,4 / 94,0 ms | 800 ms | 10,4 / 13,3 / 29,1 ms | 350 ms |
| Listado de salidas (centro) | — | — | 24,5 / 43,5 / 50,9 ms | 600 ms |
| Listado de recaudos | — | — | 22,9 / 42,1 / 44,0 ms | 600 ms |
