#!/usr/bin/env bash
# Despliegue de una versión empaquetada por la CI (ROB-001, ROB-003, ROB-005, ROB-013, RDL-004; Anexo D, D.3).
#
# Uso en el servidor: scripts/desplegar.sh vX.Y.Z /ruta/kora-vX.Y.Z.tar.gz [--con-mantenimiento]
#
# Estructura (4.8.1): $RAIZ_APP/releases/vX.Y.Z, $RAIZ_APP/current -> releases/vX.Y.Z (cambio atómico),
# $RAIZ_APP/compartido/{.env,almacenamiento}. Se conservan las 5 últimas versiones.
# Secuencia: extraer y verificar el manifiesto → enlazar lo compartido → verificar el entorno → [mantenimiento y
# drenaje] → copia previa → migraciones → cambio atómico de current → caché → humo → registro → [salir del
# mantenimiento]. Se detiene en el primer fallo; con --con-mantenimiento, el mantenimiento queda activo.
set -euo pipefail
umask 027

VERSION="${1:?Indique la versión}"
PAQUETE="$(realpath "${2:?Indique el paquete}")"
MODO="${3:-}"
RAIZ_APP="${RAIZ_APP:?Defina RAIZ_APP, p. ej. \$HOME/domains/DOMINIO/kora}"
PHP="${PHP_BIN:-php}"
DRENAJE_MAXIMO="${DRENAJE_MAXIMO_SEGUNDOS:-900}"
INICIO="$(date +%s)"
cd "$RAIZ_APP"
DESTINO="releases/$VERSION"
[[ ! -e "$DESTINO" ]] || { echo "La versión $VERSION ya existe en releases/." >&2; exit 1; }
[[ -f compartido/.env ]] || { echo "Falta compartido/.env (permisos 600)." >&2; exit 1; }
ANTERIOR=""
[[ -L current ]] && ANTERIOR="$(basename "$(readlink current)")"

registrar() {
  "$PHP" "$1/cli/registrar_despliegue.php" --version="$VERSION" --tipo=despliegue --resultado="$2" \
    --desde="$ANTERIOR" --respaldo="${RESPALDO:-}" --duracion="$(( $(date +%s) - INICIO ))" || true
}

echo "1/9 Extracción y verificación del manifiesto de $VERSION"
mkdir -p "$DESTINO"
tar -xzf "$PAQUETE" -C "$DESTINO"
(cd "$DESTINO" && sha256sum -c --quiet manifiesto.sha256)
ln -s ../../compartido/.env "$DESTINO/.env"
ln -s ../../compartido/almacenamiento "$DESTINO/almacenamiento"
find "$DESTINO/" -type d -exec chmod 750 {} +
find "$DESTINO/" -type f -exec chmod 640 {} +
chmod 750 "$DESTINO"/scripts/*.sh

echo "2/9 Verificación del entorno"
"$PHP" "$DESTINO/cli/verificar_entorno.php"

if [[ "$MODO" == "--con-mantenimiento" ]]; then
  echo "3/9 Mantenimiento y drenaje de la cola"
  CLI_MANT="${ANTERIOR:+current}"
  CLI_MANT="${CLI_MANT:-$DESTINO}"
  "$PHP" "$CLI_MANT/cli/mantenimiento.php" activar --motivo="Despliegue $VERSION"
  ESPERA=0
  until "$PHP" "$CLI_MANT/cli/mantenimiento.php" drenaje; do
    sleep 10
    ESPERA=$((ESPERA + 10))
    [[ $ESPERA -lt $DRENAJE_MAXIMO ]] || { echo "El drenaje superó ${DRENAJE_MAXIMO} s; revise Sistema > Colas. El mantenimiento sigue activo." >&2; exit 1; }
  done
else
  echo "3/9 Sin mantenimiento (migraciones expand/contract, ROB-006)"
fi

echo "4/9 Copia previa de la base (RDL-004)"
if RESPALDO="$("$PHP" "$DESTINO/cli/respaldo.php" crear --tipo=predespliegue --motivo="Antes de desplegar $VERSION" | tail -n1)"; then
  echo "     $RESPALDO"
elif [[ -z "$ANTERIOR" ]]; then
  # Primer despliegue: la base aún no tiene el esquema, no hay nada que copiar.
  RESPALDO=""
  echo "     Primer despliegue sobre una base sin esquema: sin copia previa."
else
  echo "Falló la copia previa (RDL-004): no se migra sin copia. El mantenimiento sigue activo si se usó." >&2
  exit 1
fi

echo "5/9 Migraciones"
"$PHP" "$DESTINO/cli/migrar.php" --aplicar

echo "6/9 Cambio atómico de current"
ln -sfn "releases/$VERSION" current.nuevo && mv -Tf current.nuevo current

echo "7/9 Caché de aplicación"
"$PHP" current/cli/limpiar_cache.php

echo "8/9 Pruebas de humo"
if ! "$PHP" current/cli/humo.php ${URL_PUBLICA:+--url="$URL_PUBLICA"}; then
  registrar current fallido
  echo "Fallaron las pruebas de humo. Ejecute scripts/rollback.sh; el mantenimiento sigue activo si se usó." >&2
  exit 1
fi
registrar current exito

echo "9/9 Cierre"
if [[ "$MODO" == "--con-mantenimiento" ]]; then
  "$PHP" current/cli/mantenimiento.php desactivar --motivo="Despliegue $VERSION completado"
fi
ACTIVA="$(basename "$(readlink current)")"
# Se conservan las 5 últimas; con una sola versión, grep no encuentra nada y no es un error (pipefail).
ls -1 releases | sort -V | { grep -vx "$ACTIVA" || true; } | head -n -4 | while read -r antigua; do rm -rf "releases/$antigua"; done
echo "Versión $VERSION activa en $(( $(date +%s) - INICIO )) s (anterior: ${ANTERIOR:-ninguna})."
