# Instalación de KORA en Hostinger: produccion.herramientascab.com

Es una cuenta compartida (`u371022697`) sin acceso SSH para el proveedor. El titular ejecuta cada paso con una **tarea cron** de hPanel (Avanzado > Tareas cron > Personalizada), y la borra cuando corre. Toda la salida queda en `~/kora-instalacion.log`.

| Elemento | Valor |
|---|---|
| Sitio | `produccion.herramientascab.com` (dominio adicional, SSL activo, CDN desactivado) |
| Código y datos | `~/domains/produccion.herramientascab.com/kora/` (`releases/`, `current`, `compartido/.env`, `compartido/almacenamiento/`, `respaldos/`) |
| Raíz web | `public_html` → enlace a `kora/current/public` (si el servidor no sigue el enlace, el instalador usa un directorio puente) |
| PHP | 8.3 con `sodium` activado y `expose_php` apagado. La CLI de KORA usa `kora/bin/php`, un envoltorio que permite `proc_open` solo para cron (copias y despliegue) |
| Paquete | Release de GitHub `vX.Y.Z`, verificado con su SHA-256 |

## Paso 1 — Preparar (una vez)

Crea una tarea cron que se ejecute cada minuto con este comando, espera a que corra una vez y bórrala:

```sh
/bin/bash -c 'curl -fsSL https://raw.githubusercontent.com/mig-conversaciones/kora/v0.9.2/scripts/instalar_hostinger.sh -o $HOME/kora-instalar.sh && /bin/bash $HOME/kora-instalar.sh preparar v0.9.2 E6jiuH5ZlXxj8Dn1U/pUQaAi6OiFpm45uUCZES5p4Bs= 2>&1 | tee -a $HOME/kora-instalacion.log'
```

Este paso:

- crea la estructura de directorios;
- descarga y verifica el paquete;
- genera el `.env` con claves nuevas (cifrado, HMAC y token de monitoreo), que no se imprimen;
- hace un diagnóstico del entorno.

La llave del comando es la **pública** del custodio de las copias. La privada está en el equipo del proveedor (`almacenamiento/llaves-produccion/custodio.par`) y se entrega al custodio.

## Paso 2 — Base de datos y correo (en hPanel)

1. **Bases de datos > MySQL:** crea la base `kora` y su usuario. hPanel les antepone `u371022697_`. Anota la contraseña.
2. **Correos:** crea el buzón que enviará las notificaciones, por ejemplo `notificaciones@herramientascab.com`.
3. **Administrador de archivos:** abre `domains/produccion.herramientascab.com/kora/compartido/.env` y reemplaza cada `COMPLETAR`:

   | Clave | Valor |
   |---|---|
   | `BD_NOMBRE` | `u371022697_kora` |
   | `BD_USUARIO` | `u371022697_…` (el usuario creado) |
   | `BD_CONTRASENA` | la contraseña de la base |
   | `CORREO_USUARIO` | el buzón completo |
   | `CORREO_CONTRASENA` | la contraseña del buzón |
   | `CORREO_REMITENTE` | el mismo buzón |

   No cambies las demás líneas: las claves de cifrado ya generadas protegen los datos.

## Paso 3 — Desplegar (una vez)

Crea otra tarea cron cada minuto, espera a que corra y bórrala:

```sh
/bin/bash -c '/bin/bash $HOME/kora-instalar.sh desplegar v0.9.2 CORREO_DEL_SUPERADMIN 2>&1 | tee -a $HOME/kora-instalacion.log'
```

Este paso:

- despliega con `scripts/desplegar.sh`: verificación del entorno, migraciones, enlace `current` y pruebas de humo;
- carga las semillas;
- publica `public_html`;
- crea el superadministrador, que recibe la invitación por correo, activa su cuenta y configura la verificación en dos pasos.

## Paso 4 — Tareas cron permanentes

`K` = `/home/u371022697/domains/produccion.herramientascab.com/kora`. Escribe las rutas completas en hPanel.

| Frecuencia | Comando |
|---|---|
| Cada minuto | `K/bin/php K/current/cli/trabajador_cron.php >> K/compartido/almacenamiento/registros/cron.log 2>&1` |
| Cada minuto | `sleep 30; K/bin/php K/current/cli/trabajador_cron.php >> K/compartido/almacenamiento/registros/cron.log 2>&1` |
| Cada minuto | `K/bin/php K/current/cli/programador.php >> K/compartido/almacenamiento/registros/cron.log 2>&1` |
| 02:30 diaria | `RAIZ_APP=K PHP_BIN=K/bin/php /bin/bash K/current/scripts/backup.sh diaria >> K/compartido/almacenamiento/registros/cron.log 2>&1` |

## Actualizar a una versión nueva

Cuando la CI publique la release `vX.Y.Z`, crea una tarea cron de una sola ejecución:

```sh
/bin/bash -c 'curl -fsSL https://raw.githubusercontent.com/mig-conversaciones/kora/vX.Y.Z/scripts/instalar_hostinger.sh -o $HOME/kora-instalar.sh && /bin/bash $HOME/kora-instalar.sh actualizar vX.Y.Z 2>&1 | tee -a $HOME/kora-instalacion.log'
```

La actualización toma la copia previa, aplica las migraciones, cambia `current` y corre las pruebas de humo. Para revertir, usa este comando:

```sh
RAIZ_APP=K PHP_BIN=K/bin/php /bin/bash K/current/scripts/rollback.sh
```

## Pendiente antes de abrir a los usuarios

- La carga inicial con los libros corregidos y el acta firmada ([guía](../guias/carga-inicial.md)).
- El destino externo de las copias (`RESPALDO_DESTINO_EXTERNO`) y el monitoreo externo con `TOKEN_MONITOREO`.
- La UAT, la capacitación y las firmas del acta de salida (`docs/operacion/actas/plantilla-salida-produccion.md`).
