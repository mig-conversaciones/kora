# Runbook: mantenimiento (ventana planificada o incidente)

- **Severidad típica:**
  - Baja, en una ventana planificada;
  - Alta, si se activa por un incidente.
- **Disparador:**
  - despliegue con migraciones incompatibles o carga masiva (ROB-005);
  - incidente de seguridad;
  - base de datos degradada.
- **Impacto:** la aplicación responde 503 con el aviso, salvo `/health/live` y las rutas exceptuadas. La cola solo ejecuta trabajos técnicos.
- **Responsable:**
  - superadministrador, desde el panel `/sistema/mantenimiento`;
  - titular de la cuenta, por SSH, si no hay panel.

Requisitos: RF-MAN-001 a RF-MAN-016; anexo C. Detalle técnico: [cola, cron y mantenimiento §5](cola-cron-mantenimiento.md#5-modo-mantenimiento-sin-panel-rf-man-012).

## Diagnóstico

1. Ver el estado del mantenimiento:

   ```sh
   php current/cli/mantenimiento.php estado
   ```

2. ¿Hay trabajos de negocio en curso? El código 0 indica que está listo para migrar:

   ```sh
   php current/cli/mantenimiento.php drenaje
   ```

## Activación

1. **Ventana planificada.** Avisar con al menos 24 h de anticipación (plantilla abajo).
2. **Activar.** Desde el panel, o por SSH:

   ```sh
   php current/cli/mantenimiento.php activar --motivo="…" --titulo="Mantenimiento programado" --hasta="2027-04-29T22:00:00-05:00"
   ```

3. **Esperar el drenaje.** `drenaje` devuelve 0. El despliegue lo hace solo, con un máximo de 15 min.

## Salida

1. **Desactivar:**

   ```sh
   php current/cli/mantenimiento.php desactivar --motivo="…"
   ```

   Antes ejecuta las comprobaciones de salida (RF-MAN-016): el esquema esperado, la base de datos y un latido del trabajador de menos de 3 min. Si alguna falla, el mantenimiento sigue activo.
2. **Sin trabajador reciente.** Ejecutar `php current/cli/trabajador_cron.php` y repetir el paso anterior.
3. **Sin base de datos.** `--forzar-sin-bd` solo si es imprescindible. El evento queda pendiente de auditar hasta que vuelva la base.

## Verificación

- `/health/ready` con el token responde 200.
- `cli/humo.php` termina en verde.
- Un usuario de prueba ingresa.

## Comunicación (plantilla)

> **Mantenimiento de KORA**
>
> El [fecha] de [hora inicio] a [hora fin] (hora de Colombia), KORA no estará disponible por [motivo breve]. Los datos registrados antes de esa hora se conservan. Si tienen una importación o una exportación pendiente, por favor termínenla antes. Contacto: [canal].

## Después del incidente

- La auditoría registra la activación y la salida (`mantenimiento.activar` y `mantenimiento.desactivar`).
- Si la ventana se extendió, registrar la causa.
