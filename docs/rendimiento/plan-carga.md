# Plan de carga (RNF-PERF-001)

**Estado:** borrador para validar con la contraparte (S-07 y S-08) y con el titular de la cuenta (S-10). Las pruebas k6 se ejecutan en la I7 en el entorno de pruebas; en la I6 no se midió ninguna cifra.

## Perfil del año 1

- **Cuentas:** 60 cuentas registradas, 25 activas al día y 15 sesiones simultáneas en el pico.
  - Las 60 cuentas no equivalen a 60 peticiones concurrentes.
- **Tráfico:** 1 solicitud por segundo sostenida y 5 en el pico, con ráfagas de 10.
- **Mezcla de flujos:**

  | Flujo | Participación |
  |---|---|
  | Tableros | 35 % |
  | Listados | 25 % |
  | Formularios GET | 15 % |
  | Escrituras | 12 % |
  | Consulta de trabajos | 8 % |
  | Importaciones y exportaciones | 3 % |
  | Autenticación | 2 % |

- **Datos:** carga inicial de unos 650 registros. Crecimiento anual de unas 12.000 salidas, 3.000 recaudos, 3.000 recepciones y 250.000 eventos de auditoría. El conjunto sintético de la prueba equivale a 3 años.

## Objetivos de p95 (RNF-PERF-003 y RNF-PERF-004)

| Flujo | Caché fría | Caché caliente |
|---|---|---|
| Tablero | 1.200 ms | 500 ms |
| Fragmento de filtro | 800 ms | 350 ms |
| Listado | 600 ms | — |
| Inicio de sesión | 900 ms | — |
| Aceptación de trabajo | 400 ms | — |
| Consulta de estado | 250 ms | — |

Objetivos de la cola:

- espera de 90 s o menos;
- importación de 5.000 filas en 5 min o menos;
- PDF en 3 min o menos.

Errores inesperados: menos del 0,5 %.

## Entorno y condiciones de parada (RNF-PERF-015)

- **Entorno:** subdominio aislado del mismo plan de Hostinger Cloud, con su propia base sintética.
- **Autorización:** escrita del titular de la cuenta (pendiente, ítem 18 del 4.8).
- **Límite de tráfico:** hasta el doble del pico (unos 16 solicitudes por segundo) durante 30 minutos o menos.
- **Parada:** con más del 2 % de errores, con un p95 por encima de 3 veces el objetivo durante 2 minutos o a pedido del titular.

## Medición prevista de los tableros

- **Caché fría:** se sube la versión de datos antes de cada iteración.
- **Caché caliente:** con la misma clave.
- **Etiquetas de k6 por ruta:** `/tableros/central`, `/tableros/central/procesos` y `/tableros/unidad/GAN`.
- **Consultas:** el inventario de consultas de `ServicioIndicadores` usa los índices `idx_*_tablero` del Anexo B. Hay que verificarlo con `EXPLAIN` en el entorno de pruebas.
