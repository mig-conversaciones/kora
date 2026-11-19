# Runbook: cola atascada

- **Severidad típica:** Media. Alta si afecta el correo de acceso o las importaciones del cierre.
- **Síntomas y alertas** (RF-Q-016):
  - `cola_atascada`: el pendiente más antiguo supera 5 min;
  - `trabajador_sin_latido`: más de 3 min sin latido;
  - `fallidos_excesivos`: más de 5 fallidos en 1 h;
  - `/health/ready` con `cola` o `trabajador` en `false`.
- **Impacto:** las exportaciones, importaciones, correos y recálculos esperan. La operación síncrona sigue funcionando.
- **Responsable:** líder técnico. **Escalamiento:** titular de la cuenta, si el cron de hPanel no corre.

El detalle técnico de la cola, el cron y la estrategia de reclamo está en [cola, cron y mantenimiento](cola-cron-mantenimiento.md).

## Diagnóstico

1. Ver el estado de la cola:

   ```sh
   php current/cli/cola.php estado
   ```

   Muestra los conteos, la antigüedad del pendiente más viejo y los latidos del trabajador y del programador.
2. Revisar `tail -n 100 compartido/almacenamiento/registros/cron.log`:
   - errores de PHP;
   - «Could not open input file», que indica una ruta del cron equivocada tras un cambio de `current`;
   - procesos que agotan la memoria (`COLA_MEMORIA_MAX_MB`).
3. En hPanel → Tareas cron, comprobar que estén las tres entradas (trabajador, trabajador desfasado y programador) y la ruta de PHP 8.3.
4. Ver los últimos fallos:

   ```sh
   php current/cli/cola.php fallidos --limite=20
   ```

   Busca la clase de error común (SMTP, permisos, datos).

## Mitigación inmediata

1. Ejecutar el trabajador a mano y ver si avanza:

   ```sh
   php current/cli/trabajador_cron.php --presupuesto=50
   ```

2. Si un trabajo de importación o exportación agota la reserva una y otra vez, dejarlo fallar (5 intentos) y atender los demás. Para un trabajo pendiente que no debe ejecutarse:

   ```sh
   php current/cli/cola.php cancelar ULID --motivo="…"
   ```

## Resolución

- **Cron caído.** Corregir la entrada en hPanel: `/usr/bin/php …/current/cli/trabajador_cron.php`.
- **Reservas vencidas.** Las recupera el propio trabajador en cada pasada. Comprobar que el conteo de `reservado` baja.
- **Fallidos por una causa corregida.** Reintentarlos:

  ```sh
  php current/cli/cola.php reintentar ULID --motivo="Causa corregida: …"
  ```

  Si se revisaron y no aplican:

  ```sh
  php current/cli/cola.php descartar ULID --motivo="…"
  ```

## Verificación

- `cola.php estado` muestra 0 pendientes de más de 5 min y un latido del trabajador de menos de 1 min.
- `/health/ready` muestra `cola` y `trabajador` en `true`.

## Comunicación

Si hubo correos de invitación o de recuperación retrasados, avisar a los afectados; los trabajos de correo se reenvían solos.

## Después del incidente

- Causa raíz en la bitácora.
- Si el volumen supera la capacidad de vaciado, aplicar las señales de RNF-PERF-019.
