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

## Ítems 13 a 18 del 4.8 (I7, «Rendimiento y operación»)

| # | Verificación | Qué quedó listo en la I7 | Estado en Hostinger |
|---|---|---|---|
| 13 | Copias diarias del plan, más la copia propia cifrada fuera de Hostinger (RDL-003) | Se agregaron estas piezas: `cli/respaldo.php` con sobre de libsodium y llave pública del custodio, `scripts/backup.sh` con rsync externo y retención 30+12, el registro en la tabla `respaldos` y la copia previa en despliegues e importaciones masivas | Pendiente: el cron de las 02:30, el destino externo (`RESPALDO_DESTINO_EXTERNO`), el par del custodio y comprobar que `mysqldump` esté en el PATH |
| 14 | Monitoreo externo de `/health/live` y `/health/ready` con token (ROB-012) | Comprobaciones de salud y avisos del certificado a 15 días en el programador. La configuración del monitor está en [runbooks/README](../runbooks/README.md#monitoreo-externo-rob-012) | Pendiente: elegir el servicio y simular una caída en staging |
| 15 | OPcache activo y prueba del cambio de versión con la caché de rutas reales (RNF-PERF-014) | Con sesión de superadministrador, `/health/ready` informa si OPcache está activo. `verificar_entorno` lo exige fuera de local. El cambio atómico de `current` está ensayado en la CI | Pendiente: probar el cambio de `current` con LiteSpeed y OPcache |
| 16 | Firewall de aplicaciones y protección contra bots; IP real del cliente detrás del proxy | Sin cambios en la I7 | Pendiente |
| 17 | Prueba de restauración completa en staging (RDL-005) | `cli/respaldo.php simulacro` con acta, ensayado en local (2026-11-19, conforme) y en la CI en cada push | Pendiente: la prueba trimestral en staging con la llave del custodio |
| 18 | Autorización escrita para las pruebas de carga en staging (RNF-PERF-015) | Scripts k6, dataset, validación de negocio y plantilla del informe (`tests/carga/`) | **Pendiente: bloquea la medición de RNF-PERF-003 a RNF-PERF-006** |

## Ítems 19 y 20 del 4.8 (I8, «Endurecimiento, UAT y salida»)

| # | Verificación | Qué quedó listo en la I8 | Estado en Hostinger |
|---|---|---|---|
| 19 | Sin `phpinfo` ni archivos de diagnóstico; ZAP *baseline* sin hallazgos altos | ZAP baseline y escaneo completo autenticado en cada push (trabajo «Navegador, accesibilidad y ZAP»); un riesgo alto bloquea. El paquete no lleva pruebas, herramientas ni documentación (salvo las guías). Las demostraciones de la vitrina responden 404 en producción | Pendiente: ZAP autenticado contra staging antes de la versión de producción (RS-016) |
| 20 | Acta de salida con pruebas de humo, carga inicial verificada y lista ASVS firmada | Plantilla del acta (`docs/operacion/actas/plantilla-salida-produccion.md`), carga inicial con acta y huella (RF-094), lista ASVS generada con sección de firma | Pendiente: carga inicial en producción con los libros corregidos, firmas de la contraparte y del responsable de seguridad |
