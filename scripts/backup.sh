#!/usr/bin/env bash
# Copia de seguridad cifrada de KORA (RDL-003, RDL-004, RS-019; Anexo D, D.2). La hace cli/respaldo.php: mysqldump
# coherente, ZIP del almacenamiento privado y cifrado en sobre con la llave PÚBLICA del custodio (el servidor nunca
# tiene la privada). Este script añade la copia fuera de Hostinger por rsync y la retención.
#
# Uso: scripts/backup.sh [diaria|predespliegue|preimportacion|manual]
# Cron de hPanel (D.4): 30 2 * * * /bin/bash $RAIZ_APP/current/scripts/backup.sh diaria >> .../registros/cron.log 2>&1
set -euo pipefail
umask 077

TIPO="${1:-diaria}"
RAIZ_APP="${RAIZ_APP:-$(cd "$(dirname "$0")/../.." && pwd)}"
APP="$RAIZ_APP/current"
[[ -d "$APP" ]] || APP="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP_BIN:-php}"
leer_env() { { grep -E "^$1=" "$APP/.env" || true; } | head -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

ID="$("$PHP" "$APP/cli/respaldo.php" crear --tipo="$TIPO" | tail -n1)"
DIRECTORIO="$("$PHP" "$APP/cli/respaldo.php" directorio)"
DESTINO_EXTERNO="$(leer_env RESPALDO_DESTINO_EXTERNO || true)"

# Copia fuera de Hostinger (RDL-003): usuario@servidor:/ruta por SSH con llave. Un directorio montado ya lo copió PHP.
if [[ "$DESTINO_EXTERNO" == *:* ]]; then
  rsync -az --chmod=F600 "$DIRECTORIO/$ID".* "$DESTINO_EXTERNO/"
  "$PHP" "$APP/cli/respaldo.php" marcar-externo --id="$ID"
fi
"$PHP" "$APP/cli/respaldo.php" podar
echo "Copia $TIPO completada: $ID"
