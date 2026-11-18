# ADR-006: Sesiones en la base de datos con el ID convertido a hash

- Estado: Aceptado
- Fecha: 2026-11-17
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RAC-017, RAC-019, D-32

## Contexto

La revocación de sesiones y la opción «cerrar todas» requieren un registro consultable. Guardar el ID en claro permitiría secuestrar sesiones con una copia de la base.

## Decisión

La tabla `sesiones` (migración 003) guarda el SHA-256 del ID, el nivel de autenticación, la actividad y la expiración absoluta. Un manejador propio (`ManejadorSesionBd`, I2) reemplaza al de archivos. La cookie es `__Host-kora_sesion` (Secure, HttpOnly, SameSite=Lax).

## Alternativas consideradas

- Sesiones en archivos: no permiten listar ni revocar por usuario.

## Consecuencias

- Cada solicitud autenticada lee la sesión desde la base; se mide en la PCA.
- En MAMP local la cookie `__Host-` debería funcionar en `http://localhost` porque los navegadores tratan localhost como contexto seguro; se verifica en la I2.

## Implementación en la I2 (2026-11-18)

- **Capa propia en lugar del manejador nativo.** En vez de `SessionHandlerInterface` con `session_start()` (código de referencia del 4.7.3), KORA usa la capa `App\Nucleo\Sesion` sobre la misma tabla `sesiones`. El motivo es doble:
  - `Set-Cookie` pasa por `App\Nucleo\Respuesta` como cualquier otra cabecera.
  - El flujo completo (ingreso, 2FA, regeneración, expiración, revocación) se prueba sin servidor web (`NavegadorPrueba`, `IdentidadTest`).
- **Garantías.** No cambian:
  - ID de 256 bits, guardado como SHA-256.
  - Nunca se acepta un ID que el servidor no emitió, que es el equivalente a `use_strict_mode`.
  - Expiración por inactividad (30 o 15 min) y absoluta (12 u 8 h), evaluadas en el servidor.
  - Regeneración al ingresar, al completar la 2FA, al reautenticarse, al cambiar la contraseña y al cambiar los permisos.
- **Datos de la sesión.** Se guardan en JSON y no con `serialize`, lo que evita la inyección de objetos.
- **Sesiones anónimas.** Solo se crean cuando un formulario necesita su token CSRF, y vencen a la hora sin actividad.
- **Cookie.** En producción es `__Host-kora_sesion` (Secure, HttpOnly, SameSite=Lax, Path=/). En MAMP, que usa HTTP y un subdirectorio, el prefijo `__Host-` no es posible: la cookie se llama `kora_sesion`, va sin Secure y con `Path=/dashboard_laila/`. Verificado con curl contra Apache.

## Evolución

Con un VPS y Redis, la sesión podría cachearse, conservando la base como registro.
