#!/usr/bin/env bash
# Restauración desde una copia cifrada (RDL-005, ROB-004; Anexo D, D.2). Solo en staging para la prueba trimestral, o
# en producción como último recurso con aceptación explícita de pérdida. La llave privada la aporta el custodio y no
# se deja en el servidor al terminar.
#
# Uso:
#   scripts/restaurar.sh ID_COPIA BASE_DESTINO ARCHIVO_PAR [--acepto-perdida]   restauración
#   scripts/restaurar.sh --simulacro ARCHIVO_PAR [BASE_DESTINO]                  prueba trimestral con acta (RDL-005)
set -euo pipefail
umask 077

APP="${RAIZ_APP:+$RAIZ_APP/current}"
APP="${APP:-$(cd "$(dirname "$0")/.." && pwd)}"
PHP="${PHP_BIN:-php}"

if [[ "${1:-}" == "--simulacro" ]]; then
  "$PHP" "$APP/cli/respaldo.php" simulacro --identidad="${2:?Indique el par de llaves del custodio}" --base-destino="${3:-kora_restauracion}"
  exit $?
fi
ID="${1:?Indique el identificador de la copia}"
BASE="${2:?Indique la base de destino}"
PAR="${3:?Indique el par de llaves del custodio}"
INICIO="$(date +%s)"
"$PHP" "$APP/cli/respaldo.php" restaurar --id="$ID" --base="$BASE" --identidad="$PAR" ${4:+"$4"}
"$PHP" "$APP/cli/limpiar_cache.php"
echo "Restauración completada en $(( $(date +%s) - INICIO )) s. Ejecute cli/humo.php y compare las cifras de control (runbook «Backup corrupto»)."
