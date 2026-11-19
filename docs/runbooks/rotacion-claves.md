# Runbook: rotación de claves

- **Severidad típica:**
  - Baja, en la rotación periódica cada 90 días (ROB-009, D-15);
  - Alta, ante una sospecha de exposición.
- **Disparador:**
  - el calendario de 90 días;
  - la salida de una persona con acceso;
  - una alerta de integridad «el .env cambió sin registro de rotación»;
  - un [incidente de seguridad](incidente-seguridad.md).
- **Impacto:** según la clave. La tabla indica cuáles cortan sesiones o integraciones.
- **Responsable:** titular de la cuenta (`compartido/.env`) y líder técnico.

## Qué se rota y cómo

| Clave (`compartido/.env`) | Frecuencia | Procedimiento | Efecto |
|---|---|---|---|
| `CLAVES_CIFRADO` (llavero versionado) | 90 días | Agregar la nueva **al inicio**: `CLAVES_CIFRADO="v3:NUEVA,v2:ANTERIOR,v1:…"`. La primera cifra y todas descifran | Ninguno. Después de agregarla: `php current/cli/recifrar.php ejecutar` (o `encolar`, para dejarlo a la cola). Vuelve a cifrar con la nueva el secreto TOTP, los correos pendientes o fallidos de la cola y los lotes de auditoría. **Retirar la anterior** solo cuando `php current/cli/recifrar.php estado` informe 0 pendientes y no haya correos reservados en curso |
| `TOKEN_MONITOREO` | 90 días | Generar con `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` y actualizar el monitor externo en el mismo momento | `/health/ready` responde 404 al monitor hasta actualizarlo |
| `BD_CONTRASENA` | 90 días | Cambiar en hPanel → Bases de datos y, de inmediato, en el `.env` | Segundos de errores de conexión. Hacerlo en baja actividad |
| `CORREO_CONTRASENA` | 90 días o al cambiar la cuenta | Cambiar en hPanel → Correo y en el `.env`. Luego `php cli/correo.php prueba` | El correo espera en la cola mientras tanto |
| `RESPALDO_LLAVE_PUBLICA` | Al cambiar de custodio | El custodio genera un par nuevo **fuera del servidor**: `php cli/respaldo.php llaves --salida=DIR` | Las copias anteriores solo se abren con el par anterior, que el custodio conserva el tiempo de la retención |
| `CLAVE_HMAC_PII`, `CLAVE_HMAC_CODIGOS` | **Solo ante compromiso** | Generar una nueva | Las identificaciones ya seudonimizadas y las IP de la auditoría dejan de correlacionarse con las nuevas; los códigos de respaldo de la 2FA dejan de valer (regenerarlos). Documentar la fecha |

Generar las claves de 32 bytes con:

```sh
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

## Pasos

1. **Copia previa** del `.env`, cifrada fuera del servidor o en el gestor de secretos del titular. **Nunca** por correo ni en el chat.
2. **Editar** `compartido/.env`, con permisos 600, según la tabla.
3. **Cambios del lado del proveedor** que correspondan: hPanel y el monitor externo.
4. **Registrar la huella** del `.env`, para que la verificación diaria no alerte:

   ```sh
   bash current/scripts/verificar_integridad.sh --registrar-env
   ```

5. **Pruebas de humo:**

   ```sh
   php current/cli/humo.php
   ```

   Si se tocó el correo, también `php current/cli/correo.php prueba`.
6. **Anotar la rotación** en el registro de abajo: fecha, clave, responsable y motivo. **Nunca el valor.**

## Verificación

- `verificar_integridad.php` no muestra alertas.
- El monitor externo ve `/health/ready` en 200.
- La 2FA de una cuenta de prueba sigue funcionando: el llavero descifra.

## Registro de rotaciones

| Fecha | Clave | Responsable | Motivo |
|---|---|---|---|
| — | — | — | Sin rotaciones en producción (pendiente de la salida a producción, I8) |

## Después del incidente

Si la rotación fue por exposición:

- revisar la auditoría del periodo expuesto (ver [incidente de seguridad](incidente-seguridad.md));
- confirmar que la clave anterior ya no da acceso.
