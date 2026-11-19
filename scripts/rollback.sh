#!/usr/bin/env bash
# Reversión de versión (ROB-004; Anexo D, D.3). Por defecto solo cambia current a la versión anterior: las migraciones
# expand/contract son compatibles hacia atrás (RDL-002). Restaurar la base es excepcional y exige aceptar la pérdida
# de lo escrito después de la copia (D-14), con la llave del custodio.
#
# Uso: scripts/rollback.sh [--version vX.Y.Z] [--con-bd ID_COPIA --identidad ARCHIVO_PAR --acepto-perdida]
set -euo pipefail

RAIZ_APP="${RAIZ_APP:?Defina RAIZ_APP, p. ej. \$HOME/domains/DOMINIO/kora}"
PHP="${PHP_BIN:-php}"
INICIO="$(date +%s)"
cd "$RAIZ_APP"
ACTUAL="$(basename "$(readlink current)")"
OBJETIVO=""
COPIA_BD=""
IDENTIDAD=""
ACEPTO=false
while [[ $# -gt 0 ]]; do
  case "$1" in
    --version) OBJETIVO="$2"; shift 2 ;;
    --con-bd) COPIA_BD="$2"; shift 2 ;;
    --identidad) IDENTIDAD="$2"; shift 2 ;;
    --acepto-perdida) ACEPTO=true; shift ;;
    *) echo "Opción desconocida: $1" >&2; exit 2 ;;
  esac
done
if [[ -z "$OBJETIVO" ]]; then
  OBJETIVO="$(ls -1 releases | sort -V | grep -B1 -x "$ACTUAL" | grep -vx "$ACTUAL" | tail -n1 || true)"
fi
if [[ -z "$OBJETIVO" || "$OBJETIVO" == "$ACTUAL" || ! -d "releases/$OBJETIVO" ]]; then
  echo "No hay una versión anterior válida para revertir." >&2
  exit 1
fi

echo "Revirtiendo de $ACTUAL a $OBJETIVO"
"$PHP" current/cli/mantenimiento.php activar --motivo="Reversión de $ACTUAL a $OBJETIVO"
if [[ -n "$COPIA_BD" ]]; then
  if [[ "$ACEPTO" != true || -z "$IDENTIDAD" ]]; then
    echo "Restaurar la base pierde lo escrito después de la copia. Repita con --identidad y --acepto-perdida." >&2
    exit 1
  fi
  BASE="$(grep -E '^BD_NOMBRE=' compartido/.env | head -n1 | cut -d= -f2- | tr -d '"')"
  "$PHP" current/cli/respaldo.php restaurar --id="$COPIA_BD" --base="$BASE" --identidad="$IDENTIDAD" --acepto-perdida
fi
ln -sfn "releases/$OBJETIVO" current.nuevo && mv -Tf current.nuevo current
"$PHP" current/cli/limpiar_cache.php
RESULTADO=exito
"$PHP" current/cli/humo.php || RESULTADO=fallido
"$PHP" current/cli/registrar_despliegue.php --version="$OBJETIVO" --tipo=reversion --desde="$ACTUAL" \
  --respaldo="$COPIA_BD" --resultado="$RESULTADO" --duracion="$(( $(date +%s) - INICIO ))"
if [[ "$RESULTADO" != exito ]]; then
  echo "La versión $OBJETIVO no pasa las pruebas de humo: el mantenimiento sigue activo." >&2
  exit 1
fi
"$PHP" current/cli/mantenimiento.php desactivar --motivo="Reversión completada a $OBJETIVO"
echo "Reversión completada a $OBJETIVO en $(( $(date +%s) - INICIO )) s."
