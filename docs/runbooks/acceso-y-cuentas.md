# Runbook: acceso, cuentas y auditoría

Se aplica a la plataforma de la I2 y sus paneles de la I3. Requisitos: RAC-001 a RAC-031, RS-005, RAU-001 a RAU-007 y RF-MAN-001 a RF-MAN-004. Decisiones: ADR-006, ADR-007 y ADR-008.

## 1. Arranque de una instalación nueva

```bash
php cli/migrar.php --aplicar
php cli/sembrar.php                     # centro 9124, unidades, roles, permisos y matriz de autorización (idempotente)
php cli/usuarios.php crear-superadministrador --correo=persona@dominio --nombre="Nombre Apellido"
php cli/trabajador_cron.php             # envía la invitación (en local queda en almacenamiento/correos/*.eml)
```

1. La persona abre el enlace de activación, que vence a las 72 h y es de un solo uso.
2. Define una contraseña de al menos 15 caracteres.
3. Configura la verificación en dos pasos, obligatoria para su rol.
4. Guarda los 10 códigos de respaldo.

**Contraseñas por consola.** Nunca se escriben en la consola ni se envían por correo.

**Separación de funciones (RF-012).** El superadministrador no tiene centro ni roles de negocio. Para operar el piloto se invitan los demás roles:

```bash
php cli/usuarios.php invitar --correo=... --nombre="..." --rol=lider_unidad --unidades=GAN
php cli/usuarios.php invitar --correo=... --nombre="..." --rol=grupo_recaudo
```

Roles disponibles: `administrador`, `coordinacion_administrativa`, `grupo_recaudo`, `lider_produccion_centro`, `lider_unidad` (exige unidades) y `coordinacion_academica`.

Desde la I3 la gestión diaria se hace en `/sistema/usuarios`: invitar, cambiar el rol o las unidades, desactivar y reenviar invitaciones. Ver el [runbook de maestros y finanzas](maestros-y-finanzas.md), § 4. La CLI queda para el arranque y la recuperación.

## 2. Cuenta bloqueada o dispositivo perdido

| Situación | Qué hacer |
|---|---|
| «Demasiados intentos» | Esperar: el bloqueo es temporal, 15 min por cuenta o por red (RAC-029). No hay desbloqueo manual; es a propósito |
| Olvidó la contraseña | «¿Olvidaste tu contraseña?» en `/ingresar`. El enlace vale 30 min y cierra todas las sesiones al usarse |
| Perdió el teléfono y tiene códigos de respaldo | Ingresar con un código de respaldo y, en «Mi cuenta», regenerar los códigos o reconfigurar la 2FA |
| Perdió el teléfono y no tiene códigos | Verificar la identidad por otro canal (llamada o presencial) y ejecutar `php cli/usuarios.php restablecer-2fa --correo=... --motivo="..."`. Cierra sus sesiones, avisa por correo y genera una alerta de seguridad (RAC-014, RAC-031) |

## 3. Sesiones

- **Expiración por inactividad:** 30 min, o 15 min para los roles privilegiados. Dos minutos antes aparece el aviso «Seguir trabajando».
- **Expiración absoluta:** 12 h, o 8 h para los privilegiados.
- **Desde «Mi cuenta»:** cada usuario ve sus sesiones (navegador, red tratada y última actividad) y puede cerrar las demás.
- **Corte inmediato:** desactivar a un usuario o cambiar sus permisos tiene efecto en su siguiente solicitud, porque la identidad se lee de la base en cada una.

## 4. Auditoría

```bash
php cli/auditoria.php sellar      # sella lo pendiente (el programador lo hace cada minuto)
php cli/auditoria.php verificar   # recorre la cadena; código 1 si detecta manipulación
```

- **Programador:** encola `auditoria.verificar_cadena` a la 01:30 y `auditoria.exportar_lote` (día anterior, UTC) a las 02:00, ambos en hora de Colombia.
- **Lotes:** van a `almacenamiento/exportaciones/auditoria/auditoria-AAAA-MM-DD.jsonl.cifrado`, cifrados con `CLAVES_CIFRADO`. La copia externa la hace `backup.sh` (I7).
- **Cadena rota** (alerta «cadena de auditoría»):
  1. No reparar la base.
  2. Conservar los lotes exportados.
  3. Identificar la secuencia que indica `verificar`.
  4. Escalar como incidente de seguridad.

## 5. Alertas de seguridad (RAC-031)

El programador las revisa cada 5 min y las silencia 30 min por condición. Llegan al registro técnico (`alerta.*`), a las notificaciones de los superadministradores y al buzón `CORREO_ALERTAS`.

| Alerta | Significado |
|---|---|
| `cuentas_atacadas_desde_una_ip` | 5 o más cuentas con fallos desde una misma red: posible relleno de credenciales |
| `fallos_otp_repetidos` | 10 o más códigos fallidos en 5 min |
| `restablecimiento_2fa` | Un administrador restableció la 2FA de alguien |
| `ingreso_privilegiado_hora_inusual` | Superadministrador o administrador ingresó entre las 22:00 y las 05:00 |

## 6. Paneles del superadministrador

| Panel | Ruta | Uso |
|---|---|---|
| Modo mantenimiento | `/sistema/mantenimiento` | Activar o desactivar con motivo, confirmación y reautenticación (código de la app, válido 5 min) |
| Colas de trabajos | `/sistema/colas` | Reintentar, cancelar o descartar con motivo |
| Usuarios | `/sistema/usuarios` | Invitar, asignar rol y unidades (con reautenticación), desactivar y reactivar |
| Roles y permisos | `/sistema/roles` | Matriz de autorización; conceder o retirar con reautenticación |
| Auditoría | `/sistema/auditoria` | Consulta filtrada por fecha, acción, resultado y actor, con el estado del sellado |
| Salud | `/health/ready` | Con la sesión del superadministrador o con `X-Token-Monitoreo` |
