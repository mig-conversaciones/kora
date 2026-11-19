# Pruebas de carga y medición de rendimiento

Requisitos: RNF-PERF-001 a RNF-PERF-020; Anexo H. Decisión: [ADR-021](../../docs/adr/021-operacion-respaldos-despliegue.md).

| Archivo | Para qué |
|---|---|
| `../../cli/generar_dataset.php` | Dataset sintético de 3 años y un segundo centro. Con `--usuarios-k6`, también las cuentas de carga |
| `inventario_consultas.php` | Consultas por solicitud y `EXPLAIN` de cada lectura (RNF-PERF-007 a RNF-PERF-009) |
| `medir_local.php` | Tiempo de servidor por flujo, en proceso y sin concurrencia: indicativo |
| `escenarios.js` | k6: smoke, sostenida, pico, ráfaga y resistencia, con umbrales por flujo |
| `validar_resultados.php` | Validación de negocio antes y después de k6 (RNF-PERF-005) |
| `resultados/<versión>/` | Evidencia por versión; `informe.md` sigue la plantilla H.5 |

`usuarios.json` lleva contraseñas en claro de las cuentas sintéticas, así que **nunca se versiona** (`.gitignore`).

## En local (MAMP)

1. **Bases.** Crear `kora_carga` y `kora_restauracion` con permisos para `kora_app`. Lo hace root, una sola vez.
2. **Dataset.** Tarda cerca de 1 minuto en escala 3a:

   ```sh
   php cli/generar_dataset.php --base=kora_carga --escala=3a --preparar --cuentas=30 --usuarios-k6=almacenamiento/carga/usuarios.json
   ```

3. **Inventario de consultas:**

   ```sh
   php tests/carga/inventario_consultas.php --base=kora_carga --usuarios=almacenamiento/carga/usuarios.json --vigencia=2026
   ```

4. **Tiempos locales:**

   ```sh
   php tests/carga/medir_local.php --base=kora_carga --usuarios=almacenamiento/carga/usuarios.json --repeticiones=30 --vigencia=2026
   ```

5. **Ensayo de restauración con el dataset:**

   ```sh
   php cli/respaldo.php simulacro --identidad=<par> --base-destino=kora_restauracion --base-origen=kora_carga
   ```

El generador y las herramientas solo aceptan bases `*_carga` o `*_pruebas`. Nunca se ejecutan en producción (RDL-009).

## En staging (pendiente: requiere la autorización del titular, S-10)

1. **Despliegue.** Desplegar la versión con `scripts/desplegar.sh`.
2. **Dataset y cuentas.** Generar el dataset y las cuentas en la base de staging (`<base>_carga`). Configurar el `.env` de staging para usar esa base.
3. **Cifras de control antes de la prueba:**

   ```sh
   php tests/carga/validar_resultados.php --registrar
   ```

4. **k6, desde un equipo externo** con capacidad verificada (CPU por debajo del 70 % y sin `dropped_iterations` significativas):

   ```sh
   k6 run -e BASE_URL=https://staging.dominio -e ESCENARIO=smoke -e VERSION=0.8.0 -e USUARIOS=./usuarios.json -e VIGENCIA=2026 tests/carga/escenarios.js
   ```

   Después, `sostenida`, `pico` y `rafaga`; `resistencia` solo si está autorizada. Detener la prueba según las condiciones del plan: más del 5 % de errores durante 2 min, un p95 por encima de 3 veces el objetivo, alertas de hPanel u orden del titular.
5. **Métricas del servidor** durante la prueba:
   - hPanel: CPU, RAM y procesos PHP;
   - MariaDB: `SHOW PROCESSLIST` y bloqueos;
   - cola: `/sistema/colas`.
6. **Validación posterior:**

   ```sh
   php tests/carga/validar_resultados.php
   ```

   Verifica las sumas de control, la ausencia de duplicados, la cadena de auditoría y la cola vacía.
7. **Informe.** Completar `resultados/<versión>/informe.md`. Tras cada optimización se repite el escenario comparable con el mismo dataset.
