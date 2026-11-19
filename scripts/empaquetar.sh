#!/usr/bin/env bash
# Paquete de una versión de KORA (ROB-001, paso 7): código del commit, dependencias de producción, activos construidos
# y manifiesto SHA-256 de cada archivo (lo verifican scripts/desplegar.sh y la verificación diaria de integridad).
# Lo ejecuta la CI en Linux; nunca incluye .env, almacenamiento, referencias/, pruebas ni documentación.
#
# Uso: scripts/empaquetar.sh vX.Y.Z [salida.tar.gz]
set -euo pipefail
umask 022

VERSION="${1:?Indique la versión (vX.Y.Z)}"
[[ "$VERSION" =~ ^v[0-9A-Za-z._-]{1,39}$ ]] || { echo "Versión no válida: $VERSION" >&2; exit 2; }
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
SALIDA="$(realpath -m "${2:-$RAIZ/kora-$VERSION.tar.gz}")"
COMPOSER="${COMPOSER_BIN:-composer}"
TEMPORAL="$(mktemp -d)"
trap 'rm -rf "$TEMPORAL"' EXIT

# Solo lo versionado en el commit actual: nada local se cuela en el paquete.
git -C "$RAIZ" archive --format=tar HEAD | tar -x -C "$TEMPORAL"
cd "$TEMPORAL"
"$COMPOSER" install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet
npm ci --no-audit --no-fund --silent
npm run --silent construir
rm -rf node_modules referencias tests docs .github herramientas almacenamiento
rm -f .env .env.* phpunit.xml phpstan.neon* .php-cs-fixer* package-lock.json composer.lock.bak
echo "${VERSION#v}" > VERSION
git -C "$RAIZ" rev-parse HEAD > COMMIT

# Manifiesto: rutas relativas, ordenadas, sin el propio manifiesto.
find . -type f ! -name manifiesto.sha256 -printf '%P\0' | sort -z | xargs -0 sha256sum > manifiesto.sha256
tar -czf "$SALIDA" .
echo "Paquete $(basename "$SALIDA"): $(wc -l < manifiesto.sha256) archivos, $(du -h "$SALIDA" | cut -f1)."
