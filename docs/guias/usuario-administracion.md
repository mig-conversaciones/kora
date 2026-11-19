# Administración del sistema

Esta guía es para el **administrador funcional** del centro y para el **superadministrador**. Los dos usan obligatoriamente la verificación en dos pasos, y su sesión se cierra tras 15 minutos de inactividad.

Antes de empezar, lee [Primeros pasos](primeros-pasos.md).

## Usuarios

1. En **Sistema > Usuarios**, elige **Invitar**.
2. Escribe el correo y el nombre, elige el rol y, para un líder de unidad, su unidad.
3. KORA envía la invitación. El enlace vence en 72 horas; si vence, reenvíala desde el detalle del usuario.

Desde el detalle de un usuario puedes cambiar su rol o sus unidades y desactivarlo. Desactivar una cuenta **cierra todas sus sesiones** al instante. Los cambios de rol se aplican en la siguiente solicitud del usuario.

### Cerrar las sesiones de una persona

Si alguien perdió un equipo o sospecha que otra persona usó su sesión, abre su detalle y, en **Sesiones abiertas**, elige **Cerrar sus sesiones** con un motivo. La cuenta sigue activa: la persona vuelve a ingresar con su contraseña. Al superadministrador, **Sistema > Usuarios** le ofrece además **Cerrar las sesiones de todos** ante un incidente de seguridad; su propia sesión sigue abierta. Las dos acciones piden reautenticación y quedan en la auditoría.

> No compartas cuentas. Cada acción queda en la auditoría a nombre de quien la hizo.

## Roles y permisos

**Catálogos > Roles y permisos** muestra la matriz de cada rol. Los roles y sus permisos vienen de la matriz aprobada del SRS (3.10). Un cambio a la matriz se hace como un cambio del sistema, con su revisión, y no desde la pantalla.

## Auditoría

**Sistema > Auditoría** lista los eventos: ingresos, cambios, importaciones, exportaciones, denegaciones y operaciones del sistema. Puedes filtrar por fecha, actor, acción y recurso, y exportar el resultado. Los datos personales aparecen tratados: la IP truncada y sin identificaciones.

## Mantenimiento

**Sistema > Mantenimiento** activa el modo mantenimiento con un título, un mensaje y una hora estimada de fin. Mientras está activo:

- los usuarios ven el aviso y no pueden trabajar;
- las personas con acceso excepcional siguen entrando;
- la cola deja de tomar trabajos de negocio.

Desactivarlo exige reautenticación. El procedimiento completo está en la [guía de operación](operacion.md#mantenimiento).

## Colas de trabajos

**Sistema > Colas de trabajos** muestra los trabajos en segundo plano: validaciones e importaciones, exportaciones, correos y recálculos. Un trabajo fallido se puede reintentar o descartar con un motivo. Si la cola se atasca, sigue el runbook *cola atascada* de la [guía de operación](operacion.md#runbooks).

## Salud del sistema

`/health/ready` indica si la base, el esquema, el disco y el almacenamiento están bien. Con sesión de superadministrador muestra además si OPcache está activo y la antigüedad de la última copia de seguridad.
