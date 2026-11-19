# Runbook: incidente de seguridad

- **Severidad típica:** Alta.
- **Síntomas y alertas:**
  - alertas del monitor de seguridad (RAC-031): cuentas atacadas desde una IP, fallos de OTP repetidos, restablecimientos de 2FA o ingresos privilegiados a horas inusuales;
  - «Alerta de integridad» del trabajo `seguridad.verificar_integridad`: un archivo de la versión activa no coincide con su manifiesto o el `.env` cambió sin registro de rotación (RS-022);
  - cadena de auditoría alterada (`php cli/auditoria.php verificar`);
  - sospecha de compromiso reportada por una persona.
- **Impacto:** posible acceso indebido a datos de negocio o personales.
- **Responsable:** líder técnico y superadministrador. **Escalamiento:** contraparte del CAB, titular de la cuenta y, si hay datos personales, el responsable del tratamiento (Ley 1581 de 2012).

## Diagnóstico

1. **Integridad del código y del `.env`:**

   ```sh
   php current/cli/verificar_integridad.php
   ```

   Lista los archivos alterados frente a `manifiesto.sha256`, los permisos del `.env` y su huella.
2. **Cadena de auditoría:**

   ```sh
   php current/cli/auditoria.php verificar
   ```

   Indica la primera secuencia alterada, si la hay.
3. **Eventos recientes** en `/sistema/auditoria`: `sesion.ingresar` denegados, `2fa.restablecer`, cambios de roles (`usuarios.*`) y exportaciones (`exportaciones.solicitar`), filtrados por fecha, actor, acción y recurso.
4. **Sesiones activas:**

   ```sql
   SELECT usuario_id, COUNT(*) FROM sesiones WHERE revocada_en IS NULL GROUP BY usuario_id;
   ```

## Contención inmediata

1. **Preservar la evidencia antes de cambiar nada:**
   - copia de `almacenamiento/registros/`;
   - `bash current/scripts/backup.sh manual`;
   - exportación del lote de auditoría.
2. **Activar el mantenimiento** si el compromiso está en curso (ver [mantenimiento](mantenimiento.md)).
3. **Revocar sesiones**, de todas las cuentas o de las afectadas:

   ```sql
   UPDATE sesiones SET revocada_en = UTC_TIMESTAMP(3) WHERE revocada_en IS NULL;
   ```

   Desactivar las cuentas comprometidas desde `/sistema/usuarios`; eso sube su versión de permisos. Si se cambiaron permisos por SQL, subir `version_permisos` de los afectados o ejecutar `php current/cli/limpiar_cache.php`, porque los permisos efectivos se guardan en caché hasta 5 min.
4. **Código alterado.** Redesplegar la versión desde su paquete verificado, o revertir con `scripts/rollback.sh` (ver [despliegue y reversión](despliegue-reversion.md)). No se corrige a mano en el servidor.

## Resolución

1. **Rotar las claves** expuestas (ver [rotación de claves](rotacion-claves.md)): token de monitoreo, contraseña de la base, SMTP, llavero de cifrado y, si se comprometieron, las claves HMAC.
2. **Restablecer la 2FA** de las cuentas afectadas:

   ```sh
   php current/cli/usuarios.php restablecer-2fa --correo=… --motivo="…"
   ```

3. **Si la cadena de auditoría está alterada,** comparar con el último lote exportado y firmado (RAU-005) para acotar qué cambió.

## Verificación

- `verificar_integridad.php` termina sin alertas.
- `auditoria.php verificar` informa «Cadena íntegra».
- No hay ingresos nuevos denegados desde el origen del ataque.

## Comunicación

- Contraparte y titular de la cuenta: de inmediato.
- Si hubo acceso a datos personales, notificar a los titulares y a la SIC en los plazos de la Ley 1581 y su reglamentación, con lo que se sabe, lo que se hizo y lo que sigue.
- No publicar detalles técnicos que faciliten otro ataque.

## Después del incidente

- Análisis de causa raíz y lecciones aprendidas en un plazo de 5 días hábiles.
- Actualizar la lista ASVS (anexo J) y este runbook.
- Registrar la huella del `.env` tras las rotaciones.
