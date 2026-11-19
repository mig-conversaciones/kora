# Guía de operación

Para el **titular de la cuenta de Hostinger** y el **soporte técnico**. Resume cómo se despliega, se programa, se respalda y se vigila KORA. El detalle de cada incidente está en los runbooks del repositorio (`docs/runbooks/`).

## Estructura en el servidor

| Ruta | Contenido |
|---|---|
| `kora/releases/vX.Y.Z/` | Cada versión desplegada; se conservan las 5 últimas |
| `kora/current` | Enlace a la versión activa; se cambia de forma atómica |
| `kora/compartido/.env` | Configuración y secretos (permisos 600) |
| `kora/compartido/almacenamiento/` | Archivos privados: importaciones, adjuntos, exportaciones, caché y registros |
| `kora/respaldos/` | Copias cifradas (si `RESPALDO_DIRECTORIO` no indica otra ruta) |

`public_html` apunta a `current/public`. La alternativa es un `.htaccess` en la raíz que reescribe a `public/`.

## Desplegar una versión

1. La CI arma el paquete con `scripts/empaquetar.sh vX.Y.Z`. Lleva un manifiesto SHA-256 de cada archivo y nunca incluye `.env`, pruebas ni datos.
2. En el servidor: `bash scripts/desplegar.sh vX.Y.Z kora-vX.Y.Z.tar.gz`. Agrega `--con-mantenimiento` si la versión trae migraciones que no son compatibles hacia atrás.
3. El script hace, en orden:
   - verifica el manifiesto y el entorno (`cli/verificar_entorno.php`);
   - activa el mantenimiento y espera a que se vacíe la cola, si se pidió;
   - toma la copia previa y aplica las migraciones;
   - cambia `current`, vacía la caché y corre las pruebas de humo;
   - registra el despliegue en la auditoría.
4. Si las pruebas de humo fallan, revierte con `bash scripts/rollback.sh`. El objetivo es volver en 10 minutos o menos.

Runbook: `despliegue-reversion.md`.

## Tareas programadas (cron)

En hPanel → Avanzado → Tareas cron, con el binario de PHP 8.3:

| Frecuencia | Comando | Para qué |
|---|---|---|
| Cada minuto | `php current/cli/trabajador_cron.php` | Ejecuta la cola de trabajos (50 s como máximo) |
| Cada minuto, 30 s después | `sleep 30; php current/cli/trabajador_cron.php` | Segunda pasada desfasada |
| Cada minuto | `php current/cli/programador.php` | Limpiezas, vigilancia de la cola, integridad (03:15), certificado (03:20) y precalentamiento (06:30 en días hábiles) |
| Diaria, 02:30 | `bash current/scripts/backup.sh diaria` | Copia cifrada, retención y envío fuera de Hostinger |

Runbook: `cola-cron-mantenimiento.md`.

## Copias de seguridad

- Cada copia cifra la base y los archivos privados con la **llave pública del custodio**. El servidor no puede descifrarlas: solo el custodio, con su llave privada.
- Se conservan 30 copias diarias y la última de cada uno de los 12 meses anteriores. Hay copia previa antes de cada despliegue y de cada importación de 500 filas o más.
- `php current/cli/respaldo.php listar` muestra las copias; `verificar` comprueba su integridad sin descifrarlas.
- **Prueba de restauración trimestral:** `php current/cli/respaldo.php simulacro` restaura en una base aislada, compara las cifras de control y la cadena de auditoría, y escribe un acta.

Runbooks: `backup-corrupto.md` y `rotacion-claves.md`.

## Rotar la clave de cifrado

1. Agrega la nueva clave **al inicio** de `CLAVES_CIFRADO` en `compartido/.env`. La primera cifra y todas descifran.
2. Ejecuta `php current/cli/recifrar.php ejecutar`, o `encolar` para dejárselo a la cola. Vuelve a cifrar con la nueva los secretos TOTP, los correos pendientes y los lotes de auditoría.
3. Cuando `php current/cli/recifrar.php estado` informe 0 pendientes, retira la clave anterior y registra la huella del `.env` (`php current/cli/verificar_integridad.php --registrar-env`).

## Inventario de componentes

Cada paquete trae `sbom.cdx.json` (CycloneDX 1.5) con las dependencias de esa versión. Ante un aviso de seguridad de una biblioteca, ahí se ve si la versión desplegada la usa.

## Vigilancia

- **Monitoreo externo:** `/health/live` (proceso vivo) y `/health/ready` (base, esquema, disco y almacenamiento), con `TOKEN_MONITOREO`.
- **Integridad diaria:** compara la versión activa con su manifiesto y la huella del `.env`. Ante una alerta, sigue `incidente-seguridad.md`.
- **Certificado:** aviso 15 días antes de que venza. Runbook: `ssl-expirado.md`.
- **Auditoría:** `php current/cli/auditoria.php verificar` comprueba la cadena de eventos.

## Mantenimiento

`php current/cli/mantenimiento.php activar --motivo="…"` y `desactivar --motivo="…"`; también desde **Sistema > Mantenimiento**. El estado se consulta con `mantenimiento.php estado` y el vaciado de la cola con `mantenimiento.php drenaje`. Runbook: `mantenimiento.md`.

## Runbooks

| Situación | Runbook |
|---|---|
| Despliegue fallido o reversión | `despliegue-reversion.md` |
| Cola atascada o cron detenido | `cola-atascada.md`, `cola-cron-mantenimiento.md` |
| Base de datos lenta | `bd-lenta.md` |
| Copia dañada o restauración | `backup-corrupto.md` |
| Importación fallida | `importacion-fallida.md` |
| Correo caído | `smtp-caido.md` |
| Servicio de IA caído | `ia-caida.md` |
| Certificado vencido | `ssl-expirado.md` |
| Rotación de claves | `rotacion-claves.md` |
| Incidente de seguridad | `incidente-seguridad.md` |

## Antes de cada versión de producción

- Lista ASVS nivel 2 firmada (`docs/seguridad/asvs.md`) y hallazgos de ZAP sin riesgos altos.
- CI en verde: pruebas en MariaDB 10.6 y MySQL 5.7, despliegue y reversión, accesibilidad y ZAP.
- Copia previa completada y prueba de restauración vigente (menos de 3 meses).
