# Runbook: importación fallida

- **Severidad típica:** Media. Alta durante la carga inicial del piloto (RF-094).
- **Síntomas y alertas:**
  - lote en estado `fallido` o `con_errores` en `/importaciones`;
  - notificación «Importación con problemas»;
  - cifras de control que no coinciden con el archivo.
- **Impacto:** los datos del lote no se aplicaron, o se aplicaron sin cuadrar con la fuente.
- **Responsable:** quien importa (Grupo de Recaudo, líder de unidad o administración). **Escalamiento:** líder técnico.

Detalle del flujo: [importaciones y recaudos](importaciones-y-recaudos.md).

## Diagnóstico

1. En el detalle del lote (`/importaciones/{ulid}`), revisar el estado, el resumen, el reporte de errores en XLSX y las cifras de control: filas y suma del archivo frente a lo aplicado.
2. Si el lote quedó `fallido` sin aplicar, leer el motivo:
   - **«falló la copia previa de la base (RDL-004)».** Ningún dato cambió; ver [backup corrupto](backup-corrupto.md).
   - **«El usuario ya no tiene permiso».** Los permisos se releen al ejecutar.
3. Si el trabajo sigue reintentándose, ejecutar `php current/cli/cola.php fallidos`, que muestra la clase de error.

## Mitigación inmediata

- **Con errores de fila:** descargar el reporte, corregir el libro en la fuente y cargarlo de nuevo. La idempotencia por hash evita duplicar lo ya aplicado (RF-089).
- **Aplicado con cifras que no cuadran:** no seguir importando fuentes que dependan de este lote hasta revisarlo.

## Resolución

1. **Revertir un lote aplicado.** Desde el detalle del lote, con motivo y reautenticación (RF-090). Si tiene 500 filas o más, la reversión hace antes su copia previa (RDL-004). No se puede revertir si hay dependencias posteriores: el detalle las lista.
2. **Recargar** el libro corregido.
3. **Último recurso: restaurar la copia previa** del lote (`lotes_importacion.respaldo_previo`), con aceptación de pérdida. Ver [backup corrupto](backup-corrupto.md).

## Verificación

- El lote queda `aplicado` con «Cifras verificadas».
- La bandeja de calidad, recalculada tras la importación, no muestra incidencias nuevas inesperadas.

## Comunicación

Avisar a la contraparte si la importación afecta un periodo cerrado o el tablero que se presenta.

## Después del incidente

- Registrar el tipo de error.
- Si se repite, proponer el ajuste de la plantilla de la fuente o de la regla de validación.
- Si hay una diferencia con el SRS, registrarla como errata, como las de la I6.
