# Runbook: base de datos lenta

- **Severidad típica:** Media. Alta si el p95 supera 3 veces el SLO.
- **Síntomas y alertas:**
  - tableros o listados lentos;
  - el p95 del monitoreo está por encima de los objetivos de RNF-PERF-003;
  - alertas de recursos en hPanel;
  - cola atascada por trabajos lentos.
- **Impacto:** toda la aplicación. Los tableros se sostienen un tiempo con la caché, pero los listados y las escrituras van directo a la base.
- **Responsable:** líder técnico. **Escalamiento:** titular de la cuenta, para el soporte de Hostinger o la ampliación del plan.

Referencias: [inventario de consultas](../rendimiento/consultas-indices.md), [estrategia de caché](../rendimiento/estrategia-cache.md) y RNF-PERF-019.

## Diagnóstico

1. **Base frente a PHP.** `/health/ready` con el token: si `base_datos` falla, ver [cola atascada](cola-atascada.md) y el estado de MariaDB en hPanel.
2. **Consultas en curso:**

   ```sql
   SHOW FULL PROCESSLIST;
   SELECT * FROM information_schema.innodb_trx ORDER BY trx_started;
   ```

   Hay que buscar consultas de más de 5 s, bloqueos (`LOCK WAIT`) o transacciones abiertas. Una importación grande es esperable mientras aplica sus bloques de 500.
3. **Plan de la consulta lenta.** Repetirla con `ANALYZE FORMAT=JSON <consulta>` (MariaDB) y compararla con su fila en `consultas-indices.md`. Las señales son `rows_examined` mucho mayor que `rows_produced`, *filesort* sobre miles de filas o un índice no usado.
4. **Número de consultas.** En un entorno con copia de los datos:

   ```sh
   php tests/carga/inventario_consultas.php --base=<copia>_carga …
   ```

   Muestra si alguna pantalla volvió a hacer N+1.
5. **Recursos.** En hPanel, revisar CPU, RAM, procesos PHP y conexiones (`SHOW STATUS LIKE 'Threads_connected'` frente a `max_user_connections`).

## Mitigación inmediata

1. **Una consulta o transacción bloquea a las demás.** Identificar su origen: un trabajo en `/sistema/colas` o una importación. Si es un trabajo, cancelarlo o esperar a que termine. Ejecutar `KILL <id>` solo si no es una importación aplicándose, que se reanuda sola.
2. **Picos de tableros.** Confirmar que la caché funciona (métricas `cache.acierto` y `cache.fallo` en el registro técnico) y precalentar:

   ```sh
   php current/cli/cola.php estado
   ```

   Encolar `cache.precalentar` desde el programador o esperar a las 06:30.
3. **Saturación sostenida.** Activar el mantenimiento para cortar la carga mientras se corrige (ver [mantenimiento](mantenimiento.md)).

## Resolución

- **Índice que falta.** Crear una migración reversible: probar la ida y vuelta (`--revertir=1` y `--aplicar`), medir antes y después y actualizar `consultas-indices.md`.
  - Si el índice nuevo empieza por las columnas de una llave foránea, InnoDB lo usará para esa llave y quitará su índice automático. Hay que diseñar la reversión con eso en cuenta (lección de la migración 014).
- **N+1.** Cargar en bloque con `IN` y añadir el caso a `RendimientoOperacionTest`, con su máximo de consultas.
- **Capacidad.** Si se cumple durante una semana alguna señal de RNF-PERF-019, proponer al titular Cloud Professional o un VPS: CPU por encima del 80 %, workers por encima del 70 %, conexiones por encima del 70 % o p95 por encima del SLO con la carga esperada.

## Verificación

- El p95 vuelve al objetivo en el monitoreo.
- La consulta corregida tiene un plan sin *filesort* ni recorridos completos sobre miles de filas.
- La prueba de consultas por solicitud pasa.

## Comunicación

Aviso a los usuarios si hubo lentitud de más de 15 min o mantenimiento.

## Después del incidente

- Registrar la consulta, el plan antes y después, y la causa.
- Revisar si el dataset sintético refleja el volumen real (RNF-PERF-016).
