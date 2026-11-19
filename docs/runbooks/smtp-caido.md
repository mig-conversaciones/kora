# Runbook: SMTP caído

- **Severidad típica:** Media. Alta si bloquea invitaciones o la recuperación de acceso.
- **Síntomas y alertas:**
  - `fallidos_excesivos` con trabajos `correo.*`;
  - circuito `smtp` abierto en la tabla `estado_circuitos`;
  - usuarios que no reciben la invitación o el enlace de recuperación.
- **Impacto:** el correo queda en cola, sin perderse. La aplicación sigue funcionando.
- **Responsable:** líder técnico. **Escalamiento:** titular de la cuenta (correo de Hostinger).

Referencia: [cola, cron y mantenimiento §4](cola-cron-mantenimiento.md#4-correo).

## Diagnóstico

1. Ver el estado de la cola y los últimos fallos:

   ```sh
   php current/cli/cola.php estado
   php current/cli/cola.php fallidos --limite=10
   ```

   Pistas en el mensaje del error:
   - autenticación: contraseña cambiada;
   - límite diario: demasiados envíos;
   - conexión: puerto o host.
2. Ver si el cortacircuitos está abierto y desde cuándo:

   ```sql
   SELECT * FROM estado_circuitos WHERE servicio = 'smtp';
   ```

3. En hPanel → Correo, revisar el estado de la cuenta remitente y el límite de envíos por día del plan.

## Mitigación inmediata

1. No hace falta hacer nada para conservar los correos: los trabajos se reintentan con espera exponencial (hasta 6 intentos) y el circuito deja pasar una prueba cada 60 s.
2. Si un usuario necesita acceso urgente, el superadministrador puede reenviar la invitación cuando vuelva el servicio. No se envían contraseñas por otros medios (RAC-007).

## Resolución

1. **Credenciales.** Si cambiaron, actualizar `CORREO_USUARIO` o `CORREO_CONTRASENA` en `compartido/.env` y registrar la huella:

   ```sh
   bash current/scripts/verificar_integridad.sh --registrar-env
   ```

   Ver también [rotación de claves](rotacion-claves.md).
2. **Prueba de envío:**

   ```sh
   php current/cli/correo.php prueba
   php current/cli/trabajador_cron.php --cola=correo
   ```

3. **Fallidos definitivos.** Reintentarlos uno por uno:

   ```sh
   php current/cli/cola.php reintentar ULID --motivo="SMTP restablecido"
   ```

## Verificación

- El correo de prueba llega al buzón de operaciones.
- `estado_circuitos` muestra el circuito `smtp` cerrado.
- No quedan trabajos `correo.*` fallidos sin revisar.

## Comunicación

Avisar a los usuarios afectados (invitaciones vencidas) para que pidan un reenvío.

## Después del incidente

- Si fue el límite diario, revisar el ritmo de la cola de correo.
- Evaluar con el titular el límite del plan de correo.
