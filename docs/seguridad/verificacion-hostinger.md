# Verificación del plan de Hostinger (SRS 4.8, ítems 1 a 9)

Estado al cierre de la I0: el desarrollo es local (MAMP). Los ítems que dependen de la cuenta de Hostinger quedan pendientes. Los resuelve el líder técnico con el titular de la cuenta antes de la I1 (riesgo R1).

| # | Verificación | Cómo | Estado |
|---|---|---|---|
| 1 | Plan Cloud (Startup o Professional) y el centro de datos con menor latencia a Colombia | hPanel | Pendiente (titular de la cuenta) |
| 2 | Dominio o subdominio y DNS: A/AAAA, SPF, DKIM y DMARC | hPanel → DNS | Pendiente |
| 3 | SSL de Let's Encrypt con renovación automática, redirección a HTTPS y HSTS; calificación A o superior en SSL Labs | hPanel y SSL Labs | Pendiente. La aplicación ya emite HSTS y `upgrade-insecure-requests` cuando `APP_URL` usa https |
| 4 | PHP 8.3 con pdo_mysql, mbstring, intl, sodium, zip, fileinfo, openssl y opcache; `PASSWORD_ARGON2ID` disponible | `php cli/verificar_entorno.php` por SSH | **Local: hecho.** PHP 8.3.36 cumple todo salvo opcache, que no está cargado en MAMP (aviso; es obligatorio en producción). En Hostinger: pendiente |
| 5 | `.user.ini` respetado o valores equivalentes en hPanel | `phpinfo()` temporal y protegido, que se elimina después | Pendiente. `public/.user.ini` ya está listo |
| 6 | MariaDB ≥ 10.6, `max_user_connections`, soporte de SKIP LOCKED y si se pueden crear disparadores | `SELECT VERSION()`, `SHOW VARIABLES`, migración de prueba | **Local:** MySQL 5.7.24, aceptado solo en local (ADR-002). Pendiente en Hostinger; de esto depende la migración 013 |
| 7 | Bases separadas de producción y staging; usuario de la aplicación y, si es posible, otro para migraciones (RBD-012) | hPanel → Bases de datos | Pendiente. En local ya existen `kora` y `kora_pruebas` con el usuario `kora_app`, de privilegios limitados |
| 8 | SSH con llaves, rsync y Git; llaves de despliegue con permisos mínimos | hPanel → SSH | Pendiente. Repositorio: `github.com/mig-conversaciones/kora` |
| 9 | `public_html` como enlace a `current/public`, o la alternativa B | Prueba en staging | Pendiente. La alternativa B (un `.htaccess` en la raíz que reescribe a `public/`) ya está probada en MAMP |

## Diferencias entre local y producción

| Aspecto | Local (MAMP) | Producción (Hostinger) |
|---|---|---|
| Motor | MySQL 5.7.24 | MariaDB ≥ 10.6 |
| `BD_SKIP_LOCKED` / reclamo de la cola | `false` / `actualizacion_condicional` | `true` / `skip_locked` |
| URL | `http://localhost/dashboard_laila` (subdirectorio) | `https://…` (raíz) |
| HTTPS forzado | No (excepción para localhost en `public/.htaccess`) | Sí |
| opcache | No cargado | Obligatorio |
| `.user.ini` | Ignorado (mod_php) | Interpretado por LiteSpeed |
