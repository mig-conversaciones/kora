## Anexo D. Operación: salud, copias, despliegue, reversión, cron, ADR y runbooks

### D.1 Comprobaciones de salud (equivalente a `health.php`)

```php archivo=app/Controladores/SaludControlador.php
<?php
declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Solicitud;
use App\Servicios\ServicioMantenimiento;
use Throwable;

/**
 * Comprobaciones de salud (ROB-011). /health/live es público y mínimo. /health/ready está restringido: token
 * de monitoreo comparado en tiempo constante o sesión de superadministrador (D-28); sin autorización responde
 * 404. Devuelve 503 si una dependencia crítica impide operar o si hay mantenimiento activo. Nunca expone
 * credenciales, rutas, versiones ni topología: solo el nombre de cada comprobación y su resultado.
 */
final class SaludControlador
{
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly ServicioMantenimiento $mantenimiento,
        private readonly Sesion $sesion,
        private readonly string $tokenMonitoreo,
        private readonly string $versionEsquemaEsperada,
    ) {
    }

    public function vivo(Solicitud $solicitud): Respuesta
    {
        return Respuesta::json(['estado' => 'vivo'])->conCabecera('Cache-Control', 'no-store');
    }

    public function listo(Solicitud $solicitud): Respuesta
    {
        $token = (string) $solicitud->cabecera('X-Token-Monitoreo');
        $autorizado = ($token !== '' && hash_equals($this->tokenMonitoreo, $token))
            || ($this->sesion->usuarioAutenticado()?->esSuperadministrador() ?? false);
        if (!$autorizado) {
            throw new ErrorHttp(404);
        }
        $latido = RAIZ . '/almacenamiento/latidos/trabajador.txt';
        $comprobaciones = [
            'base_datos' => $this->comprobar(fn (): bool => (int) $this->bd->valor('SELECT 1') === 1),
            'esquema' => $this->comprobar(fn (): bool => str_starts_with((string) $this->bd->valor('SELECT MAX(nombre) FROM migraciones_aplicadas'), $this->versionEsquemaEsperada)),
            'cola' => $this->comprobar(fn (): bool => (int) $this->bd->valor(
                "SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(disponible_en), UTC_TIMESTAMP(3)), 0)
                   FROM trabajos WHERE estado = 'pendiente' AND disponible_en <= UTC_TIMESTAMP(3)"
            ) < 300),
            'trabajador' => $this->comprobar(fn (): bool => is_file($latido) && time() - (int) strtotime((string) file_get_contents($latido)) < 180),
            'disco' => $this->comprobar(fn (): bool => (float) disk_free_space(RAIZ . '/almacenamiento') > 500 * 1048576),
            'almacenamiento' => $this->comprobar(fn (): bool => is_writable(RAIZ . '/almacenamiento/cache')),
        ];
        $mantenimiento = $this->mantenimiento->activo();
        $listo = !$mantenimiento && !in_array(false, $comprobaciones, true);
        return Respuesta::json(
            ['estado' => $listo ? 'listo' : 'no_listo', 'mantenimiento' => $mantenimiento, 'comprobaciones' => $comprobaciones],
            $listo ? 200 : 503
        )->conCabecera('Cache-Control', 'no-store');
    }

    private function comprobar(callable $prueba): bool
    {
        try {
            return (bool) $prueba();
        } catch (Throwable) {
            return false;
        }
    }
}
```

### D.2 Copias de seguridad cifradas y restauración

```bash archivo=scripts/backup.sh
#!/usr/bin/env bash
# Copia de seguridad cifrada de Producción CAB (RDL-003, RDL-004, RS-019).
# Uso: scripts/backup.sh [diaria|predespliegue|preimportacion]
# Cifrado asimétrico con la llave pública del custodio (age o gpg): el servidor nunca tiene la llave privada.
set -euo pipefail
umask 077

TIPO="${1:-diaria}"
RAIZ_APP="${RAIZ_APP:-$HOME/domains/DOMINIO/produccion_cab}"
COMPARTIDO="$RAIZ_APP/compartido"
DESTINO_LOCAL="$RAIZ_APP/respaldos"
RETENCION_DIAS="${RETENCION_DIAS:-30}"
SELLO="$(date -u +%Y%m%dT%H%M%SZ)"
BASE="$DESTINO_LOCAL/pcab-${TIPO}-${SELLO}"

leer_env() { grep -E "^$1=" "$COMPARTIDO/.env" | head -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

cifrar() {
  local origen="$1"
  if command -v age >/dev/null 2>&1; then
    age -r "${LLAVE_AGE:?Defina LLAVE_AGE con la llave pública del custodio}" -o "${origen}.age" "$origen"
  else
    gpg --batch --yes --trust-model always --recipient "${LLAVE_GPG:?Defina LLAVE_GPG}" --output "${origen}.gpg" --encrypt "$origen"
  fi
  rm -f "$origen"
}

# Credenciales en un archivo de opciones temporal (600): nunca en la línea de comandos ni en el registro.
OPCIONES_MYSQL="$(mktemp)"
trap 'rm -f "$OPCIONES_MYSQL"' EXIT
printf '[client]\nuser=%s\npassword=%s\nhost=127.0.0.1\n' "$(leer_env BD_USUARIO)" "$(leer_env BD_CONTRASENA)" > "$OPCIONES_MYSQL"

mkdir -p "$DESTINO_LOCAL"
# --single-transaction: copia coherente de InnoDB sin bloquear la operación.
mysqldump --defaults-extra-file="$OPCIONES_MYSQL" --single-transaction --quick --routines --triggers \
  --default-character-set=utf8mb4 "$(leer_env BD_NOMBRE)" | gzip -9 > "${BASE}.sql.gz"
tar -czf "${BASE}.almacenamiento.tar.gz" --exclude='almacenamiento/cache' --exclude='almacenamiento/bloqueos' \
  -C "$COMPARTIDO" almacenamiento

cifrar "${BASE}.sql.gz"
cifrar "${BASE}.almacenamiento.tar.gz"
sha256sum "${BASE}".*.age "${BASE}".*.gpg 2>/dev/null > "${BASE}.sha256" || true

# Copia fuera de Hostinger (RDL-003): destino del centro o del proveedor por SSH.
if [[ -n "${DESTINO_EXTERNO:-}" ]]; then
  rsync -az --chmod=F600 "${BASE}".* "$DESTINO_EXTERNO/"
fi

find "$DESTINO_LOCAL" -name 'pcab-diaria-*' -mtime +"$RETENCION_DIAS" -delete
echo "Copia ${TIPO} completada: $(basename "$BASE")"
```

```bash archivo=scripts/restaurar.sh
#!/usr/bin/env bash
# Restauración desde una copia cifrada (RDL-005). Solo en staging para la prueba trimestral, o en producción
# como último recurso con aceptación explícita de pérdida (ROB-004). La llave privada la aporta el custodio.
# Uso: scripts/restaurar.sh /ruta/pcab-diaria-AAAAMMDDTHHMMSSZ.sql.gz.age
set -euo pipefail
umask 077
COPIA="${1:?Indique el archivo de copia}"
RAIZ_APP="${RAIZ_APP:-$HOME/domains/DOMINIO/produccion_cab}"
COMPARTIDO="$RAIZ_APP/compartido"
leer_env() { grep -E "^$1=" "$COMPARTIDO/.env" | head -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

TEMPORAL="$(mktemp)"
OPCIONES_MYSQL="$(mktemp)"
trap 'rm -f "$TEMPORAL" "$OPCIONES_MYSQL"' EXIT
case "$COPIA" in
  *.age) age -d -i "${IDENTIDAD_AGE:?Ruta de la llave privada del custodio}" -o "$TEMPORAL" "$COPIA" ;;
  *.gpg) gpg --batch --yes --output "$TEMPORAL" --decrypt "$COPIA" ;;
  *) echo "Formato de copia no reconocido" >&2; exit 2 ;;
esac
printf '[client]\nuser=%s\npassword=%s\nhost=127.0.0.1\n' "$(leer_env BD_USUARIO)" "$(leer_env BD_CONTRASENA)" > "$OPCIONES_MYSQL"
INICIO="$(date +%s)"
gunzip -c "$TEMPORAL" | mysql --defaults-extra-file="$OPCIONES_MYSQL" "$(leer_env BD_NOMBRE)"
echo "Restauración completada en $(( $(date +%s) - INICIO )) s. Ejecute la verificación de integridad y las cifras de control."
```

### D.3 Despliegue y reversión

```bash archivo=scripts/desplegar.sh
#!/usr/bin/env bash
# Despliegue de una versión empaquetada por la CI (ROB-001, ROB-003, ROB-005).
# Uso en el servidor: scripts/desplegar.sh vX.Y.Z /ruta/paquete.tar.gz [--con-mantenimiento]
set -euo pipefail
umask 027

VERSION="${1:?Indique la versión}"
PAQUETE="${2:?Indique el paquete}"
MODO="${3:-}"
RAIZ_APP="${RAIZ_APP:-$HOME/domains/DOMINIO/produccion_cab}"
DESTINO="$RAIZ_APP/releases/$VERSION"
cd "$RAIZ_APP"
[[ ! -e "$DESTINO" ]] || { echo "La versión $VERSION ya existe." >&2; exit 1; }

mkdir -p "$DESTINO"
tar -xzf "$PAQUETE" -C "$DESTINO"
(cd "$DESTINO" && sha256sum -c --quiet manifiesto.sha256)
ln -s ../../compartido/.env "$DESTINO/.env"
ln -s ../../compartido/almacenamiento "$DESTINO/almacenamiento"
find "$DESTINO" -type d -exec chmod 750 {} +
find "$DESTINO" -type f -exec chmod 640 {} +
php "$DESTINO/cli/verificar_entorno.php"

if [[ "$MODO" == "--con-mantenimiento" ]]; then
  php current/cli/mantenimiento.php activar --motivo="Despliegue $VERSION"
  ESPERA=0
  until php current/cli/mantenimiento.php drenaje; do
    sleep 10
    ESPERA=$((ESPERA + 10))
    [[ $ESPERA -lt 900 ]] || { echo "El drenaje superó 15 min; revise Sistema > Colas." >&2; exit 1; }
  done
fi

"$DESTINO/scripts/backup.sh" predespliegue
php "$DESTINO/cli/migrar.php" --aplicar
ln -sfn "releases/$VERSION" current.nuevo && mv -Tf current.nuevo current
php current/cli/limpiar_cache.php
php current/cli/registrar_despliegue.php --version="$VERSION" --tipo=despliegue
if ! php current/cli/humo.php; then
  echo "Fallaron las pruebas de humo. Ejecute scripts/rollback.sh; el mantenimiento sigue activo si se usó." >&2
  exit 1
fi
if [[ "$MODO" == "--con-mantenimiento" ]]; then
  php current/cli/mantenimiento.php desactivar --motivo="Despliegue $VERSION completado"
fi
ls -1 releases | sort -V | head -n -5 | while read -r antigua; do rm -rf "releases/$antigua"; done
echo "Versión $VERSION activa."
```

```bash archivo=scripts/rollback.sh
#!/usr/bin/env bash
# Reversión de versión (ROB-004). Por defecto solo cambia el enlace a la versión anterior: las migraciones
# expand/contract son compatibles hacia atrás (RDL-002). Restaurar la base es excepcional y exige aceptar la
# pérdida de lo escrito después de la copia (D-14).
# Uso: scripts/rollback.sh [--version vX.Y.Z] [--con-bd COPIA --acepto-perdida]
set -euo pipefail

RAIZ_APP="${RAIZ_APP:-$HOME/domains/DOMINIO/produccion_cab}"
cd "$RAIZ_APP"
ACTUAL="$(basename "$(readlink -f current)")"
OBJETIVO=""
COPIA_BD=""
ACEPTO=false
while [[ $# -gt 0 ]]; do
  case "$1" in
    --version) OBJETIVO="$2"; shift 2 ;;
    --con-bd) COPIA_BD="$2"; shift 2 ;;
    --acepto-perdida) ACEPTO=true; shift ;;
    *) echo "Opción desconocida: $1" >&2; exit 2 ;;
  esac
done
if [[ -z "$OBJETIVO" ]]; then
  OBJETIVO="$(ls -1 releases | sort -V | grep -B1 -x "$ACTUAL" | head -n1)"
fi
if [[ -z "$OBJETIVO" || "$OBJETIVO" == "$ACTUAL" || ! -d "releases/$OBJETIVO" ]]; then
  echo "No hay una versión anterior válida para revertir." >&2
  exit 1
fi

echo "Revirtiendo de $ACTUAL a $OBJETIVO"
php current/cli/mantenimiento.php activar --motivo="Reversión de $ACTUAL a $OBJETIVO"
if [[ -n "$COPIA_BD" ]]; then
  if [[ "$ACEPTO" != true ]]; then
    echo "Restaurar la base pierde lo escrito después de la copia. Repita con --acepto-perdida." >&2
    exit 1
  fi
  "releases/$ACTUAL/scripts/restaurar.sh" "$COPIA_BD"
fi
ln -sfn "releases/$OBJETIVO" current.nuevo && mv -Tf current.nuevo current
php current/cli/registrar_despliegue.php --version="$OBJETIVO" --tipo=reversion --desde="$ACTUAL"
php current/cli/humo.php
php current/cli/mantenimiento.php desactivar --motivo="Reversión completada a $OBJETIVO"
echo "Reversión completada a $OBJETIVO."
```

```bash archivo=scripts/verificar_integridad.sh
#!/usr/bin/env bash
# Verificación diaria de integridad (RS-012, RS-022). Alternativa a auditd en Hostinger Cloud (D-13): compara la
# versión activa con su manifiesto y vigila los permisos y los cambios no registrados del .env. Lo ejecuta el
# trabajo seguridad.verificar_integridad, que notifica al superadministrador si el código de salida es 1.
set -euo pipefail

RAIZ_APP="${RAIZ_APP:-$HOME/domains/DOMINIO/produccion_cab}"
ESTADO="$RAIZ_APP/compartido/almacenamiento/estado"
ENV_ARCHIVO="$RAIZ_APP/compartido/.env"
ALERTAS=()

if ! (cd "$RAIZ_APP/current" && sha256sum -c --quiet manifiesto.sha256 >/dev/null 2>&1); then
  ALERTAS+=("Archivos de la versión activa distintos del manifiesto")
fi
PERMISOS="$(stat -c '%a' "$ENV_ARCHIVO")"
[[ "$PERMISOS" == "600" ]] || ALERTAS+=("Permisos del .env: $PERMISOS (se esperaba 600)")
HUELLA_ACTUAL="$(sha256sum "$ENV_ARCHIVO" | cut -d' ' -f1)"
HUELLA_REGISTRADA="$(cat "$ESTADO/env.sha256" 2>/dev/null || true)"
if [[ -n "$HUELLA_REGISTRADA" && "$HUELLA_ACTUAL" != "$HUELLA_REGISTRADA" ]]; then
  ALERTAS+=(".env modificado sin registro de rotación (runbook Rotación de claves)")
fi

if [[ ${#ALERTAS[@]} -gt 0 ]]; then
  printf '%s\n' "${ALERTAS[@]}"
  exit 1
fi
echo "Integridad verificada."
```

### D.4 Configuración de cron en Hostinger

Se configura en hPanel → Avanzado → Tareas cron, con la opción «Personalizado». La ruta del binario de PHP (`/usr/bin/php` o la que indique hPanel para PHP 8.3) se confirma en la iteración 0. La salida se redirige a un registro rotado por el programador para diagnosticar fallos del propio cron.

```text
# Trabajador de la cola: cada minuto, más una ejecución desfasada 30 s (espera efectiva de 30 s o menos, RF-Q-005)
* * * * * /usr/bin/php /home/USUARIO/domains/DOMINIO/produccion_cab/current/cli/trabajador_cron.php >> /home/USUARIO/domains/DOMINIO/produccion_cab/compartido/almacenamiento/registros/cron.log 2>&1
* * * * * sleep 30; /usr/bin/php /home/USUARIO/domains/DOMINIO/produccion_cab/current/cli/trabajador_cron.php >> /home/USUARIO/domains/DOMINIO/produccion_cab/compartido/almacenamiento/registros/cron.log 2>&1
# Programador: tareas periódicas, sellado de la auditoría y alertas (ROB-010)
* * * * * /usr/bin/php /home/USUARIO/domains/DOMINIO/produccion_cab/current/cli/programador.php >> /home/USUARIO/domains/DOMINIO/produccion_cab/compartido/almacenamiento/registros/cron.log 2>&1
# Copia diaria cifrada a las 02:30, hora del servidor (RDL-003)
30 2 * * * /bin/bash /home/USUARIO/domains/DOMINIO/produccion_cab/current/scripts/backup.sh diaria >> /home/USUARIO/domains/DOMINIO/produccion_cab/compartido/almacenamiento/registros/cron.log 2>&1
```

| Tarea del programador | Frecuencia | Mecanismo | Requisito |
|---|---|---|---|
| Sellado de eventos de auditoría | Cada minuto | Directo (`ServicioAuditoria::sellarPendientes`) | RAU-004, D-39 |
| Volcado de eventos pendientes de la CLI a la auditoría | Cada minuto | Directo | RF-MAN-012 |
| Monitor de la cola (antigüedad, fallidos, latido) | Cada minuto | Directo con alertas | RF-Q-016 |
| Monitor de seguridad (patrones anómalos) | Cada 5 min | Directo con alertas | RAC-031 |
| Limpieza de sesiones, idempotencia y límites vencidos | Cada hora | Trabajo `mantenimiento.limpieza` | RDL-010 |
| Limpieza de trabajos, exportaciones y caché | Diaria a la 01:00 | Trabajo `mantenimiento.limpieza` | RF-Q-015, RNF-PERF-012 |
| Verificación de la cadena de auditoría | Diaria a la 01:30 | Trabajo `auditoria.verificar_cadena` | RAU-004 |
| Exportación del lote de auditoría | Diaria a las 02:00 | Trabajo `auditoria.exportar_lote` | RAU-005 |
| Verificación de integridad | Diaria a las 03:15 | Trabajo `seguridad.verificar_integridad` | RS-022 |
| Precalentamiento de la caché de tableros | Días hábiles a las 06:30 | Trabajo `cache.precalentar` | RNF-PERF-012 |
| Archivo de la auditoría de más de 2 años | Mensual | Trabajo `auditoria.archivar` | RAU-006 |
| Rotación del registro técnico y de `cron.log` | Diaria | Directo | RAU-008 |

### D.5 Plantilla de ADR y ejemplo

```markdown
# ADR-NNN: <Título de la decisión>

- Estado: Propuesto | Aceptado | Reemplazado por ADR-MMM
- Fecha: AAAA-MM-DD
- Responsables: <roles>
- Requisitos relacionados: <IDs>

## Contexto
<Problema, restricciones (hosting, normativa, plazos) y fuerzas en tensión.>

## Decisión
<La decisión en una o dos frases, y su alcance.>

## Alternativas consideradas
<Opción y motivo para descartarla.>

## Consecuencias
<Positivas, negativas, riesgos, deuda aceptada y cómo se verificará.>

## Evolución
<Señales que harían reconsiderar la decisión.>
```

**Ejemplo condensado de ADR-003 (aceptado): cola durable en base de datos con trabajador por cron.**

- **Contexto.** Hostinger Cloud no ofrece Redis ni procesos persistentes. El trabajo pesado (importaciones, exportaciones, correo) no cabe en el presupuesto síncrono de 1.500 ms.
- **Decisión.** La tabla `trabajos` en MariaDB es el registro durable. El reclamo es atómico (`SKIP LOCKED` o actualización condicional), con reservas que vencen y un trabajador CLI cada minuto, más una ejecución desfasada de 30 s.
- **Consecuencias.**
  - La espera p95 es de 90 s o menos, lo que es aceptable para el negocio.
  - Hay carga adicional sobre MariaDB, medida en la PCA.
  - Los manejadores deben ser idempotentes.
- **Evolución.** Si la espera exigida baja de 30 s, o si la entrada supera la capacidad de vaciado (RNF-PERF-019), se pasa a un VPS con trabajador permanente y Redis opcional, que solo acelera y nunca reemplaza a MySQL como fuente durable.

### D.6 Plantilla de runbook e inventario

```markdown
# Runbook: <Nombre del incidente u operación>

- Severidad típica: Alta | Media | Baja
- Síntomas y alertas que lo disparan: <monitoreo, panel de salud, quejas de usuarios>
- Impacto: <qué funciones se ven afectadas>
- Responsable: <rol>; escalamiento: <rol y canal>

## Diagnóstico
1. <Paso verificable con comando o pantalla y resultado esperado.>

## Mitigación inmediata
1. <Acción segura y reversible; ¿activar el modo mantenimiento?>

## Resolución
1. <Pasos exactos.>

## Verificación
- <Criterios objetivos de servicio restablecido.>

## Comunicación
- <A quién se informa, con qué plantilla y cuándo.>

## Después del incidente
- Registro en la auditoría o bitácora, causa raíz, acciones preventivas y actualización de este runbook.
```

| Runbook (`docs/runbooks/`) | Disparador | Acciones clave |
|---|---|---|
| `cola-atascada.md` | Pendiente más antiguo de más de 5 min o latido de más de 3 min (RF-Q-016) | Revisar `cron.log` y el latido; ejecutar el trabajador a mano por SSH; revisar las reservas vencidas; reintentar los fallidos desde el panel; verificar los límites del plan |
| `bd-lenta.md` | p95 por encima del SLO o alertas de recursos | `SHOW PROCESSLIST`; consultas lentas con `ANALYZE FORMAT=JSON`; revisar los índices (anexo H); vaciar o precalentar la caché; señales de ampliación (RNF-PERF-019) |
| `ssl-expirado.md` | Monitoreo a 15 días del vencimiento o error TLS | Renovar en hPanel; verificar la cadena y HSTS; nota en SSL Labs |
| `backup-corrupto.md` | Fallo de la suma de verificación o de la restauración | Usar la copia anterior; restaurar en staging; comparar las cifras de control; revisar el proceso de copia |
| `smtp-caido.md` | Circuito `smtp` abierto o fallos de correo | Revisar la cuenta y los límites; los trabajos siguen en cola; avisar a los usuarios; restablecer las credenciales |
| `importacion-fallida.md` | Lote en estado `fallido` o `con_errores` | Descargar el reporte de errores; corregir y recargar; revertir si se aplicó parcialmente (RF-090) |
| `mantenimiento.md` | Ventana planificada o incidente | Anexo C.6 |
| `incidente-seguridad.md` | Alerta de seguridad, integridad o sospecha de compromiso | Contener (mantenimiento y revocar sesiones), preservar la evidencia, rotar claves, notificar según la Ley 1581, analizar las lecciones |
| `rotacion-claves.md` | Cada 90 días o ante una sospecha | Añadir la nueva clave al llavero, ejecutar `seguridad.recifrar`, registrar la huella del `.env`, retirar la clave anterior |
| `despliegue-reversion.md` | Cada versión | Anexo D.3 |
| `ia-caida.md` | NO APLICA en la v1 (D-27): el sistema no usa servicios de IA | Se conserva la plantilla para el futuro |
