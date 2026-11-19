#!/usr/bin/env bash
# Verificación de integridad de la versión activa y del .env (RS-012, RS-022; Anexo D, D.3). La lógica está en
# cli/verificar_integridad.php (la misma que usa el trabajo diario seguridad.verificar_integridad). Código 1 si hay
# alertas. Tras una rotación de claves: scripts/verificar_integridad.sh --registrar-env
set -euo pipefail
APP="${RAIZ_APP:+$RAIZ_APP/current}"
APP="${APP:-$(cd "$(dirname "$0")/.." && pwd)}"
exec "${PHP_BIN:-php}" "$APP/cli/verificar_integridad.php" "$@"
