# KORA

Gestión de la producción, la trazabilidad y los tableros de mando del Centro Agropecuario de Buga (SENA). Es el proyecto **Producción CAB**: su especificación es el SRS v1.0, en `referencias/docs/srs/`. El piloto es la unidad de Ganadería, Hato El Milagroso.

**Stack:**

- PHP 8.3 con MVC nativo.
- HTMX 2 con idiomorph y Alpine 3 en versión CSP.
- Tailwind CSS 3.4.
- MySQL 5.7 en desarrollo local (MAMP) y MariaDB 10.6 o superior en producción (Hostinger Cloud); ver [ADR-002](docs/adr/002-mariadb-sql-portable.md).

**Estado:** iteración 4, «Operación productiva», terminada; sigue la I5, «Importación y recaudos». Ver el [CHANGELOG](CHANGELOG.md) y los runbooks de [cola, cron y mantenimiento](docs/runbooks/cola-cron-mantenimiento.md), [acceso y cuentas](docs/runbooks/acceso-y-cuentas.md), [maestros y finanzas](docs/runbooks/maestros-y-finanzas.md) y [operación productiva](docs/runbooks/operacion-productiva.md).

## Puesta en marcha local con MAMP

**Requisitos:**

- MAMP con Apache en el puerto 80, PHP 8.3.36 y MySQL 5.7.24.
- `mod_rewrite` activo y `AllowOverride All` en `htdocs`.
- Node.js 20 o superior, solo para construir los activos.

En PowerShell, desde `C:\MAMP\htdocs\dashboard_laila`:

```powershell
$php = 'C:\MAMP\bin\php\php8.3.36\php.exe'

# 1. Dependencias de PHP (Composer va en herramientas/, sin instalación global)
& $php herramientas\composer.phar install

# 2. Entorno: copiar la plantilla y completar BD_*, claves y TOKEN_MONITOREO (ver comentarios en el archivo)
Copy-Item .env.example .env   # luego: APP_ENTORNO=local, APP_URL=http://localhost/dashboard_laila, BD_SKIP_LOCKED=false

# 3. Base de datos (bases kora y kora_pruebas en utf8mb4_unicode_ci, con el usuario kora_app)
& $php cli\migrar.php --aplicar
& $php cli\verificar_entorno.php

# 4. Activos: CSS, JS, proveedores con huella, fuentes e iconos
npm.cmd install
npm.cmd run construir

# 5. Datos de configuración y primer superadministrador (por invitación; nunca contraseñas por consola)
& $php cli\sembrar.php
& $php cli\usuarios.php crear-superadministrador --correo=tu.correo@dominio --nombre="Tu nombre"
& $php cli\trabajador_cron.php   # en local, el correo con el enlace queda en almacenamiento\correos\*.eml
```

Si `herramientas\composer.phar` no existe, se descarga de getcomposer.org.

Abre **http://localhost/dashboard_laila/**. Para la primera cuenta:

1. Abre el enlace de activación del `.eml`.
2. Define la contraseña: al menos 15 caracteres; una frase funciona bien.
3. Configura la verificación en dos pasos con una aplicación de autenticación.

El sistema de diseño está en `/sistema-diseno`. El prototipo original sigue disponible en `/referencias/`. La operación de cuentas está en el [runbook de acceso y cuentas](docs/runbooks/acceso-y-cuentas.md), y la de catálogos, presupuesto y cierres, en el [de maestros y finanzas](docs/runbooks/maestros-y-finanzas.md).

## Comandos

| Comando | Qué hace |
|---|---|
| `php cli/migrar.php --estado` / `--aplicar` / `--revertir=N` [`--base=kora_pruebas`] | Migraciones con checksum (RBD-011) |
| `php cli/verificar_entorno.php` | Verifica PHP, extensiones, configuración, motor de base de datos y almacenamiento (RNF-018) |
| `php cli/sembrar.php` | Datos de configuración idempotentes: centro, unidades, roles, permisos y matriz; categorías de gasto, parámetros y factores de derivados (RDL-008) |
| `php cli/usuarios.php <acción>` (crear-superadministrador, invitar, restablecer-2fa) | Arranque y recuperación de cuentas por SSH (RAC-007, RAC-014) |
| `php cli/auditoria.php sellar` / `verificar` | Sella la cadena de auditoría o la verifica de punta a punta (RAU-004) |
| `php cli/cron_local.php` | Simula el cron de hPanel en MAMP: trabajador cada 30 s y programador cada minuto (Ctrl+C para detener) |
| `php cli/trabajador_cron.php [--detalle]` / `php cli/programador.php` | Una ejecución del trabajador de la cola o del programador (en Hostinger, por cron) |
| `php cli/cola.php <acción>` (estado, fallidos, reintentar, cancelar, descartar) | Diagnóstico y administración de la cola, auditada (RF-Q-014) |
| `php cli/mantenimiento.php <acción> --motivo="…"` (estado, activar, desactivar, drenaje) | Modo mantenimiento sin panel (RF-MAN-012) |
| `php cli/correo.php prueba` | Encola un correo de prueba; en local queda en `almacenamiento/correos/*.eml` |
| `php herramientas/composer.phar verificar` | PSR-12, PHPStan nivel 6 y PHPUnit (pruebas unitarias e integración contra `kora_pruebas`) |
| `npm run marca` | Deriva logos, símbolo y favicon de `referencias/KORA_kit_identidad` a `public/marca/` (ADR-016); solo cuando cambia el kit |
| `npm run construir` | Tailwind, minificado, huellas, `manifest.json`, fuentes y favicons, con presupuesto de peso |
| `npm run contraste` | Contraste AA de los pares de tokens, en modo claro y oscuro |
| `npm run lint:js` | ESLint (`no-alert`, `no-console`) |

## Salud y mantenimiento

- `GET /health/live` es público.
- `GET /health/ready` exige la cabecera `X-Token-Monitoreo` con el valor de `TOKEN_MONITOREO`; sin ella responde 404.
- **Modo mantenimiento:** se activa desde `/sistema/mantenimiento` o con `php cli/mantenimiento.php`. La señal `almacenamiento/estado/mantenimiento.json` hace que todo responda 503 con aviso, salvo las rutas exceptuadas.

## Estructura

```
app/            Nucleo (enrutador, contenedor, vista, BD, migrador), Intermediarios, Controladores, Servicios, Vistas
config/         app, base_datos, rutas, htmx, menu, seguridad, funcionalidades, mantenimiento
public/         index.php (controlador frontal), .htaccess, assets con huella, favicon y manifest
cli/            migrar, verificar_entorno, sembrar, usuarios, auditoria, trabajador_cron, programador, cola, mantenimiento, correo, cron_local
bd/migraciones/ 001 a 009 (.sql y .down.sql); bd/semillas/ 001 y 002
recursos/       Fuentes de CSS (tokens, Tailwind) y JS (retroalimentacion-ui.js, componentes-alpine.js)
herramientas/   construir-activos.mjs, verificar-contraste.mjs, composer.phar (no versionado)
almacenamiento/ Privado: caché, registros JSON, estado, adjuntos y exportaciones (bloqueado para la web)
tests/          Unitarias e Integracion (PHPUnit 11)
docs/           adr/, procesos/ (FP-01 a FP-11), ui/, seguridad/, ia/
referencias/    Prototipo HTML, Excel originales, guía y SRS v1.0, con su generador Word
```

## Repositorio

`https://github.com/mig-conversaciones/kora`, rama `main`. Las confirmaciones siguen el formato de commits convencionales en español (RNF-015). El `.env`, `vendor/`, `node_modules/` y los binarios pesados de `referencias/` no se versionan.
