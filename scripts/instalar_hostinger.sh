#!/usr/bin/env bash
# Instalación y actualización de KORA en el hosting compartido de Hostinger sin SSH (I8; SRS 4.8). Cada fase se
# ejecuta UNA vez desde una tarea cron de hPanel y deja su salida en ~/kora-instalacion.log.
#
#   preparar  vX.Y.Z LLAVE_PUBLICA   estructura, paquete verificado, .env con claves nuevas y diagnóstico del entorno
#   desplegar vX.Y.Z [CORREO_ADMIN]  primer despliegue: migraciones, semillas, public_html -> current/public y
#                                    superadministrador (requiere el .env completo: base de datos y SMTP)
#   actualizar vX.Y.Z [--con-mantenimiento]   nueva versión con scripts/desplegar.sh (copia previa, humo, reversión)
#
# El paquete sale de la release de GitHub que publica la CI (kora-vX.Y.Z.tar.gz y su .sha256). Los secretos se
# generan aquí y nunca salen del servidor; la contraseña de la base y la del SMTP las escribe el titular en el .env.
set -euo pipefail
umask 027

DOMINIO="${KORA_DOMINIO:-produccion.herramientascab.com}"
REPOSITORIO="${KORA_REPOSITORIO:-mig-conversaciones/kora}"
BASE="$HOME/domains/$DOMINIO"
APP="$BASE/kora"
PHP_REAL="${KORA_PHP:-/opt/alt/php83/usr/bin/php}"
[[ -x "$PHP_REAL" ]] || PHP_REAL="$(command -v php)"
FASE="${1:?Indica la fase: preparar, desplegar o actualizar}"
VERSION="${2:?Indica la versión, p. ej. v0.9.1}"
[[ "$VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Versión no válida: $VERSION" >&2; exit 2; }
PAQUETE="$APP/paquetes/kora-$VERSION.tar.gz"
ENV="$APP/compartido/.env"

paso() { echo; echo "== $(date -u +%FT%TZ) $*"; }

descargar_paquete() {
  mkdir -p "$APP/paquetes"
  if [[ ! -f "$PAQUETE" && -n "${KORA_PAQUETE_LOCAL:-}" ]]; then
    # Ensayo de la CI: el paquete recién armado, con su suma.
    cp "$KORA_PAQUETE_LOCAL" "$PAQUETE" && (cd "$APP/paquetes" && sha256sum "kora-$VERSION.tar.gz" > "kora-$VERSION.tar.gz.sha256")
  fi
  if [[ ! -f "$PAQUETE" ]]; then
    paso "Descarga de $VERSION desde la release de GitHub"
    curl -fsSL -o "$PAQUETE.tmp" "https://github.com/$REPOSITORIO/releases/download/$VERSION/kora-$VERSION.tar.gz"
    curl -fsSL -o "$PAQUETE.sha256" "https://github.com/$REPOSITORIO/releases/download/$VERSION/kora-$VERSION.tar.gz.sha256"
    mv "$PAQUETE.tmp" "$PAQUETE"
  fi
  (cd "$APP/paquetes" && sha256sum -c "kora-$VERSION.tar.gz.sha256")
}

# Envoltorio de PHP para la CLI de KORA: el PHP de Hostinger deshabilita proc_open (que usan mysqldump y mysql en las
# copias). Solo lo usan las tareas cron del titular; la web conserva las restricciones del hosting.
envoltorio_php() {
  mkdir -p "$APP/bin"
  printf '#!/bin/sh\nexec "%s" -d disable_functions= -d display_errors=stderr -d opcache.enable_cli=1 "$@"\n' "$PHP_REAL" > "$APP/bin/php"
  chmod 750 "$APP/bin/php"
}

aleatorio_b64() { "$APP/bin/php" -r 'echo base64_encode(random_bytes(32));'; }
aleatorio_hex() { "$APP/bin/php" -r 'echo bin2hex(random_bytes(32));'; }
fijar() { # fijar CLAVE VALOR: reemplaza la línea CLAVE=… del .env sin imprimir el valor
  local clave="$1" valor="$2" temporal
  temporal="$(mktemp "$APP/compartido/.env.XXXXXX")"
  awk -v c="$clave" -v v="$valor" 'BEGIN { FS = "="; hecho = 0 } $1 == c { print c "=" v; hecho = 1; next } { print } END { if (!hecho) print c "=" v }' "$ENV" > "$temporal"
  chmod 600 "$temporal" && mv "$temporal" "$ENV"
}

preparar() {
  local llave="${3:?Indica la llave PÚBLICA del custodio (php cli/respaldo.php llaves en el equipo del custodio)}"
  paso "Estructura en $APP"
  mkdir -p "$APP"/{releases,respaldos,paquetes} "$APP"/compartido/almacenamiento/{cache,registros,estado,importaciones,exportaciones,adjuntos,latidos,bloqueos,correos,carga,construccion}
  chmod 750 "$APP" "$APP/compartido"
  envoltorio_php
  descargar_paquete
  local temporal; temporal="$(mktemp -d)"
  tar -xzf "$PAQUETE" -C "$temporal" ./.env.example ./scripts
  if [[ -f "$ENV" ]]; then
    paso "El .env ya existe: no se toca (sus claves cifran los datos)"
  else
    paso "Creación de compartido/.env con claves nuevas (no se imprimen)"
    cp "$temporal/.env.example" "$ENV" && chmod 600 "$ENV"
    fijar APP_ENTORNO produccion
    fijar APP_URL "https://$DOMINIO"
    fijar APP_DEPURACION false
    fijar BD_HOST 127.0.0.1
    fijar BD_NOMBRE COMPLETAR
    fijar BD_USUARIO COMPLETAR
    fijar BD_CONTRASENA COMPLETAR
    fijar BD_SKIP_LOCKED true
    fijar COLA_ESTRATEGIA_RECLAMO skip_locked
    fijar CLAVES_CIFRADO "\"v1:$(aleatorio_b64)\""
    fijar CLAVE_HMAC_PII "$(aleatorio_b64)"
    fijar CLAVE_HMAC_CODIGOS "$(aleatorio_b64)"
    fijar TOKEN_MONITOREO "$(aleatorio_hex)"
    fijar CORREO_TRANSPORTE smtp
    fijar CORREO_HOST smtp.hostinger.com
    fijar CORREO_PUERTO 465
    fijar CORREO_CIFRADO ssl
    fijar CORREO_USUARIO COMPLETAR
    fijar CORREO_CONTRASENA COMPLETAR
    fijar CORREO_REMITENTE COMPLETAR
    fijar RESPALDO_LLAVE_PUBLICA "$llave"
    fijar RESPALDO_DIRECTORIO "$APP/respaldos"
    fijar RESPALDO_MYSQLDUMP "$(command -v mysqldump || command -v mariadb-dump || echo mysqldump)"
    fijar RESPALDO_MYSQL "$(command -v mysql || command -v mariadb || echo mysql)"
  fi
  rm -rf "$temporal"

  paso "Diagnóstico del entorno"
  "$PHP_REAL" -v | head -n1
  "$APP/bin/php" -r 'foreach (["sodium","intl","mbstring","pdo_mysql","zip","fileinfo","openssl"] as $e) { echo $e, "=", extension_loaded($e) ? "sí" : "NO", " "; } echo "\nproc_open (CLI de KORA)=", function_exists("proc_open") ? "sí" : "NO", "\n";'
  for b in mysqldump mysql curl sha256sum rsync; do printf '%s=%s\n' "$b" "$(command -v "$b" || echo NO)"; done
  mysql --version 2>/dev/null || true
  echo "Pendiente en $ENV (Administrador de archivos de hPanel): BD_NOMBRE, BD_USUARIO, BD_CONTRASENA, CORREO_USUARIO, CORREO_CONTRASENA y CORREO_REMITENTE."
  grep -c '=COMPLETAR$' "$ENV" | sed 's/^/Valores por completar: /'
}

exigir_env_completo() {
  [[ -f "$ENV" ]] || { echo "Falta $ENV: ejecuta antes la fase preparar." >&2; exit 1; }
  if grep -q '=COMPLETAR$' "$ENV"; then
    echo "El .env aún tiene valores por completar:" >&2
    grep '=COMPLETAR$' "$ENV" | cut -d= -f1 >&2
    exit 1
  fi
}

publicar_raiz() {
  paso "public_html -> kora/current/public"
  if [[ -L "$BASE/public_html" ]]; then
    echo "public_html ya es un enlace a $(readlink "$BASE/public_html")."
    return
  fi
  local anterior="$BASE/public_html.anterior-$(date -u +%Y%m%dT%H%M%SZ)"
  mv "$BASE/public_html" "$anterior"
  ln -s "kora/current/public" "$BASE/public_html"
  sleep 3
  local codigo; codigo="$(curl -s -o /dev/null -w '%{http_code}' "https://$DOMINIO/health/live" || true)"
  if [[ "$codigo" != "200" ]]; then
    echo "El servidor no sirve public_html como enlace (HTTP $codigo): se restaura y se usa el directorio puente."
    rm "$BASE/public_html" && mv "$anterior" "$BASE/public_html"
    # Alternativa: public_html real con enlaces a los archivos públicos de la versión activa.
    mkdir -p "$BASE/public_html"
    for entrada in "$APP"/current/public/* "$APP"/current/public/.htaccess "$APP"/current/public/.user.ini; do
      [[ -e "$entrada" ]] || continue
      ln -sfn "$(realpath --relative-to="$BASE/public_html" "$APP/current/public")/$(basename "$entrada")" "$BASE/public_html/$(basename "$entrada")"
    done
    codigo="$(curl -s -o /dev/null -w '%{http_code}' "https://$DOMINIO/health/live" || true)"
  fi
  echo "https://$DOMINIO/health/live -> HTTP $codigo"
  [[ "$codigo" == "200" ]]
}

desplegar() {
  local correo="${3:-}"
  exigir_env_completo
  envoltorio_php
  descargar_paquete
  local temporal; temporal="$(mktemp -d)"
  tar -xzf "$PAQUETE" -C "$temporal" ./scripts
  if [[ -L "$APP/current" ]]; then
    echo "Ya hay una versión activa ($(readlink "$APP/current")): usa la fase actualizar." >&2
    exit 1
  fi
  paso "Primer despliegue de $VERSION"
  RAIZ_APP="$APP" PHP_BIN="$APP/bin/php" bash "$temporal/scripts/desplegar.sh" "$VERSION" "$PAQUETE"
  rm -rf "$temporal"
  paso "Semillas (roles, permisos, unidades y catálogos base)"
  "$APP/bin/php" "$APP/current/cli/sembrar.php"
  if [[ -z "${KORA_SIN_PUBLICAR:-}" ]]; then
    publicar_raiz
  fi
  if [[ -n "$correo" ]]; then
    paso "Superadministrador"
    "$APP/bin/php" "$APP/current/cli/usuarios.php" crear-superadministrador --correo="$correo" --nombre="Superadministrador KORA" || true
    "$APP/bin/php" "$APP/current/cli/trabajador_cron.php" || true
    echo "La invitación sale por el SMTP configurado; si no llega, revisa CORREO_* en el .env y Sistema > Colas."
  fi
  paso "Listo. Crea ahora las tareas cron permanentes (docs/operacion/hostinger.md)."
}

actualizar() {
  exigir_env_completo
  envoltorio_php
  descargar_paquete
  local temporal; temporal="$(mktemp -d)"
  tar -xzf "$PAQUETE" -C "$temporal" ./scripts
  paso "Actualización a $VERSION"
  RAIZ_APP="$APP" PHP_BIN="$APP/bin/php" bash "$temporal/scripts/desplegar.sh" "$VERSION" "$PAQUETE" ${3:-}
  rm -rf "$temporal"
  "$APP/bin/php" "$APP/current/cli/sembrar.php"
}

# Cada fase corre una sola vez por versión: una tarea cron olvidada no la repite.
MARCA="$APP/.fase-$FASE-$VERSION.ok"
if [[ -f "$MARCA" ]]; then
  echo "La fase $FASE de $VERSION ya se ejecutó ($(cat "$MARCA")). Borra la tarea cron."
  exit 0
fi
mkdir -p "$APP"

case "$FASE" in
  preparar) preparar "$@" ;;
  desplegar) desplegar "$@" ;;
  actualizar) actualizar "$@" ;;
  *) echo "Fase desconocida: $FASE" >&2; exit 2 ;;
esac
date -u +%FT%TZ > "$MARCA"
