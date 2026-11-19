# Informe de rendimiento — 0.8.0, medición local (plantilla H.5)

> **Estado: PENDIENTE DE EJECUCIÓN en staging.**
>
> Este informe registra la medición **local** de la I7: el inventario de consultas, el tiempo de servidor sin concurrencia y el ensayo de restauración. No contiene pruebas de carga con k6, porque aún no existe el entorno de staging ni la autorización del titular (S-10). Ninguna cifra de aquí demuestra un SLO de producción (RNF-PERF-018).

## Identificación

| Campo | Valor |
|---|---|
| Versión | 0.8.0 (I7), sobre el commit base `d3d0232` más los cambios de la iteración |
| Fecha | 2026-11-19 |
| Responsable | Líder técnico (asistido por IA; registro en `docs/ia/2026-11-19-iteracion-7-rendimiento-operacion.md`) |
| Escenarios | Inventario de consultas; tiempos locales con 30 repeticiones por flujo, en frío y en caliente; simulacro de restauración |
| Comandos | `cli/generar_dataset.php --base=kora_carga --escala=3a --preparar --cuentas=30 …`, `tests/carga/inventario_consultas.php …`, `tests/carga/medir_local.php --repeticiones=30 …` y `cli/respaldo.php simulacro --base-origen=kora_carga …` |

## Entorno

| Campo | Valor |
|---|---|
| Equipo | Portátil de desarrollo con Windows 11 y MAMP: Apache sin uso (medición en proceso), PHP 8.3.36 y MySQL 5.7.24 |
| Diferencias con producción | MySQL 5.7 en lugar de MariaDB 10.6. Sin LiteSpeed, red, TLS ni concurrencia. Disco y CPU del portátil, no los de Hostinger Cloud. OPcache sin verificar en la CLI |

## Dataset

| Campo | Valor |
|---|---|
| Escala | `3a`, semilla 20261119 |
| Volumen | 39.611 salidas, 9.900 recaudos, 9.900 recepciones, 2.640 gastos y 825.000 eventos de auditoría; dos centros |
| Sumas de control | Huella `eab97cadae9c8c8a…` (`CifrasControl`), igual antes y después del simulacro |

## Resultados por flujo

Tiempo de servidor en proceso: [tiempos-locales.md](tiempos-locales.md). Consultas por solicitud: [consultas.md](consultas.md).

| Flujo | Consultas por solicitud (frío / caliente) | p95 local en frío | p95 local en caliente | Objetivo p95 (frío / caliente) |
|---|---|---|---|---|
| Tablero central (centro) | 14 / 5 | 171,6 ms | 32,3 ms | 1.200 / 500 ms |
| Tablero central (líder de GAN) | 14 / 5 | 128,2 ms | 29,4 ms | 1.200 / 500 ms |
| Tablero técnico GAN | 13 / 5 | 104,1 ms | 45,9 ms | 1.200 / 500 ms |
| Fragmento de filtro | 14 / 5 | 93,4 ms | 13,3 ms | 800 / 350 ms |
| Listado de salidas | 8 | — | 43,5 ms | 600 ms |
| Listado de recaudos | 7 | — | 42,1 ms | 600 ms |

Errores, rendimiento alcanzado frente al ofrecido y `dropped_iterations`: **PENDIENTE (k6 en staging)**.

## Cola

**PENDIENTE (k6 en staging).** Falta medir la espera p95, el tiempo total de la exportación y del PDF, la profundidad máxima y el vaciado tras el pico. Las consultas de reclamo y de recuperación de reservas vencidas hacen 2 consultas cada una, con 3,5 y 0,4 ms en el dataset local.

## Recursos

**PENDIENTE (staging).** Falta medir CPU, RAM, procesos PHP, conexiones y bloqueos de MariaDB, y aciertos de la caché.

## Integridad y aislamiento

- **Simulacro de restauración** ([acta](../../../../docs/operacion/actas/2026-11-19-ensayo-restauracion-local.md)):
  - 66 MB cifrados, restaurados en unos 40 s;
  - 62.207 filas en 12 tablas por centro y 825.014 eventos de auditoría con la cadena recalculada;
  - conforme.
- **Aislamiento:** el líder del segundo centro no recibe datos del 9124, y el líder de GAN no recibe enlaces a otras unidades (`RendimientoOperacionTest`).
- **`validar_resultados.php` y `fugas_alcance`:** PENDIENTE (k6).

## Limitaciones

- Una solicitud a la vez y en proceso: no mide la concurrencia, la contención de la base, la red ni LiteSpeed.
- MySQL 5.7 estima los planes. Las filas reales examinadas salen de `ANALYZE FORMAT=JSON` en MariaDB 10.6 (staging).
- El equipo de desarrollo no equivale al plan Cloud: no se extrapola la capacidad de producción.
- No hay dobles de SMTP ni de integración en esta medición.

## Decisiones

- **N+1:** se eliminaron dos (rotación IND_12 y bandeja de clasificación).
- **Caché:** permisos en caché por versión; lecturas repetidas por solicitud memorizadas.
- **Consultas:** se unieron consultas del tablero y la auditoría pasó a una subconsulta escalar.
- **Migración 014:** índices de listados. El costo en escritura queda dentro del ruido (ver [consultas-indices.md](../../../../docs/rendimiento/consultas-indices.md)).
- **Precalentamiento:** el trabajo `cache.precalentar`, los días hábiles.
- **Señales de ampliación (RNF-PERF-019):** sin datos hasta staging.

## Estado

**PENDIENTE DE EJECUCIÓN** de los escenarios k6 (smoke, sostenida, pico, ráfaga y resistencia) en staging, con la autorización escrita del titular.
