# Runbook: copias de seguridad, backup corrupto y restauración

- **Severidad típica:** Alta.
- **Síntomas y alertas:**
  - `respaldo.php verificar` falla, o la restauración aborta con «falla la autenticación de un bloque» o «truncada»;
  - la copia diaria no aparece en `respaldo.php listar`;
  - el detalle de `/health/ready` muestra más de 26 h desde la última copia diaria.
- **Impacto:** sin una copia válida no se cumple el RPO de 24 h ni el RTO de 4 h (RDL-003, RDL-005).
- **Responsable:** líder técnico. **Custodio de la llave:** el que designe la contraparte (ítem 13 del 4.8).

Decisión: [ADR-021](../adr/021-operacion-respaldos-despliegue.md).

## Cómo funcionan las copias

- **Cron diario** (D.4):

  ```sh
  30 2 * * * /bin/bash $RAIZ_APP/current/scripts/backup.sh diaria >> …/registros/cron.log 2>&1
  ```

- **Qué guarda.** `cli/respaldo.php crear` genera:
  - `kora-<tipo>-<sello>.sql.gz.kora` (la base);
  - `kora-<tipo>-<sello>.almacenamiento.zip.kora`;
  - el manifiesto con el SHA-256 de cada archivo.
- **Cifrado.** Es un sobre de libsodium con la **llave pública** del custodio (`RESPALDO_LLAVE_PUBLICA`). El servidor no puede descifrar.
- **Copia externa.** `scripts/backup.sh` hace el rsync a `RESPALDO_DESTINO_EXTERNO` (`usuario@servidor:/ruta`), marca la copia como externa y poda.
- **Retención:** 30 diarias y la última de cada uno de los 12 meses anteriores; las de cambios de riesgo, 30 días.
- **Copias previas (RDL-004):** cada despliegue con migraciones (`predespliegue`) y cada importación o reversión de 500 filas o más (`preimportacion`). El lote guarda el identificador en `respaldo_previo`.

## Diagnóstico

1. Listar las copias:

   ```sh
   php current/cli/respaldo.php listar
   ```

   Muestra el estado, los bytes, si hay copia externa y el error de las fallidas.
2. Verificar los SHA-256 de una copia, sin descifrar:

   ```sh
   php current/cli/respaldo.php verificar --id=kora-diaria-…
   ```

3. Si falló la copia, leer `respaldos.error` y `almacenamiento/registros/cron.log`. Causas típicas:
   - `mysqldump` no está en el PATH (`RESPALDO_MYSQLDUMP`);
   - disco lleno;
   - llave pública mal copiada.

## Mitigación inmediata

1. **No restaurar desde una copia que no verifica.** Usar la anterior que sí verifique, local o externa.
2. **Si no hay copia del día,** ejecutar una a mano:

   ```sh
   bash current/scripts/backup.sh manual
   ```

3. **Si la llave pública está mal o se perdió el par:**
   1. Generar un par nuevo fuera del servidor: `php cli/respaldo.php llaves --salida=DIR`.
   2. Actualizar `RESPALDO_LLAVE_PUBLICA` en `compartido/.env`.
   3. Registrar la huella: `scripts/verificar_integridad.sh --registrar-env`.
   4. Hacer una copia nueva.
   - Las copias anteriores solo se abren con el par anterior.

## Restauración

- **Prueba trimestral en staging (RDL-005).** Es una base aislada `*_restauracion`, que se vacía antes; el simulacro escribe el acta:

  ```sh
  php current/cli/respaldo.php simulacro --identidad=/ruta/custodio.par --base-destino=<base>_restauracion
  ```

- **Producción.** Es el último recurso:
  1. Activar el mantenimiento.
  2. Restaurar:

     ```sh
     bash current/scripts/restaurar.sh <ID> <BD_NOMBRE> /ruta/custodio.par --acepto-perdida
     ```

  3. `php current/cli/humo.php`.
  4. Comparar las cifras de control con el acta de la última copia.
  5. Desactivar el mantenimiento.
  6. Borrar `custodio.par` del servidor.
- **Almacenamiento.** Restaurarlo en un directorio vacío y moverlo a mano:

  ```sh
  php current/cli/respaldo.php restaurar --id=<ID> --base=<base> --identidad=… --almacenamiento=/ruta/vacia
  ```

## Verificación

- La base restaurada aplica `--estado` sin pendientes.
- El acta del simulacro dice «Conforme»: conteos y sumas por centro, migración y cadena de auditoría coinciden, y el tiempo está por debajo de las 4 h.

## Comunicación

- Contraparte y titular de la cuenta: qué copia se usó, hasta qué hora hay datos y qué se perdió.
- Si se perdieron datos personales, aplica la Ley 1581: ver [incidente de seguridad](incidente-seguridad.md).

## Después del incidente

- Registrar el acta y la causa.
- Revisar el cron y el destino externo.
- Repetir el simulacro con la copia siguiente.

## Ensayos

- [Acta del ensayo local del 2026-11-19](../operacion/actas/2026-11-19-ensayo-restauracion-local.md): dataset sintético de 3 años, 66 MB cifrados, restauración en unos 40 s, conforme. Un ensayo local no sustituye la prueba trimestral en staging.
