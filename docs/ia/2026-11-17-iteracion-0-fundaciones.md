# Registro de entrega asistida por IA — Iteración 0 «Fundaciones»

- **Fecha:** 2026-11-17
- **Herramienta y modelo:** Claude Code (extensión de VS Code), modelo Claude Opus 5.5 (`claude-opus-5-5`)
- **Responsable humano:** líder técnico del proyecto, que revisa y aprueba
- **Requisitos y fichas relacionados:** RNF-011 a RNF-019, RUI-001 a RUI-009, RGO-001, RDOC-001, RBD-011, ROB-011, RS-007, RS-008, RF-MAN-005 a RF-MAN-007 (parcial), FP-01 a FP-11 (materializadas)

## Objetivo

Iniciar el desarrollo en local con el motor MAMP, empezando por la iteración 0 del SRS v1.0:

- Mover todo lo existente a `referencias/`.
- Construir la base técnica compatible con el PHP 8.3.36 y el MySQL 5.7.24 de MAMP.
- Bautizar la aplicación como **KORA**, con repositorio en `github.com/mig-conversaciones/kora`.

## Supuestos

- **No hay sesiones ni datos de negocio en la I0.** Todas las rutas son públicas y sin datos institucionales; las de negocio exigen permiso desde la I2.
- **La URL local vive en un subdirectorio** (`http://localhost/dashboard_laila`). Producción vive en la raíz del dominio; `App\Nucleo\Url` resuelve ambos casos.
- **Las fechas del cronograma del SRS (I0 del 30-nov al 18-dic-2026) son de referencia.** El trabajo se adelanta en local.

## Decisiones

- **Desarrollo local en MySQL 5.7.24 y no en MariaDB,** por instrucción del responsable. Contradice D-02 y ROB-008, y queda registrado en [ADR-002](../adr/002-mariadb-sql-portable.md):
  - el SQL se limita al mínimo común entre MySQL 5.7.24 y MariaDB 10.6;
  - la cola usa la estrategia de reclamo condicional;
  - la CI prueba con MariaDB 10.6 y MySQL 5.7.
- **Nombre KORA y prefijos técnicos** (`kora`, `kora_app`, `__Host-kora_sesion`): [ADR-014](../adr/014-nombres-espanol.md).
- **Cadena de intermediarios de la I0:** solo IdSolicitud, CabecerasSeguridad y Mantenimiento. Las posiciones de los demás quedan documentadas en `Aplicacion::CADENA`.
- **Errores del enrutador dentro de la cadena.** Los errores del enrutador y de los controladores se convierten dentro de la cadena, para que los 404 y 500 también lleven CSP y X-Request-ID. Esto cambia el código de referencia del 4.2.4, que los capturaba fuera.
- **`ControladorBase` sin `Autorizador` ni `ServicioAuditoria`** hasta la I2, porque esas clases aún no existen.
- **`/health/ready` en la I0** solo exige el token, sin sesión, y comprueba base de datos, esquema, disco y almacenamiento. Las comprobaciones de cola y trabajador llegan en la I1.
- **HSTS y `upgrade-insecure-requests`** solo se emiten si `APP_URL` usa https. `public/.htaccess` exceptúa localhost de la redirección a HTTPS.
- **Ajustes de tokens:** cinco pares no alcanzaban el contraste AA (los extremos claros de los botones primario y de peligro, el primario en modo oscuro y los fondos de las insignias de éxito y error) y se corrigieron. Además, el bloque oscuro se aplica también con `data-tema="oscuro"`, que el Anexo L no cubría.
- Los ADR 001 a 014 se redactaron a partir de la sección 4.1.3 y de las decisiones D-xx del SRS.

## Archivos creados o modificados

| Grupo | Archivos | Propósito |
|---|---|---|
| Material anterior | `referencias/` | Prototipo, Excel, guías y SRS movidos sin cambios |
| Núcleo | `app/Nucleo/*` | Aplicacion, Enrutador, Contenedor, Solicitud, Respuesta, Vista, BaseDatos, Entorno, Url, Registro, ManejadorErrores, Migrador, GeneradorUlid, Funcionalidades, ErrorHttp, ErrorValidacion |
| Intermediarios | `app/Intermediarios/*` | Cadena de la I0 |
| Controladores | `app/Controladores/*` | ControladorBase, Inicio, SistemaDiseno, Salud, Csp |
| Servicios | `app/Servicios/ServicioMantenimiento.php` | Señal y aviso de mantenimiento |
| Vistas | `app/Vistas/**` | Plantilla base, parciales, errores, inicio y vitrina |
| Configuración | `config/*` | app, base_datos, rutas, htmx, menu, seguridad, funcionalidades, mantenimiento |
| Web | `public/*`, `.htaccess` | Controlador frontal, reglas de Apache/LiteSpeed, `.user.ini`, favicon y manifest |
| Datos | `bd/migraciones/001…006` | Esquema base, con reversión |
| CLI | `cli/*` | Migraciones y verificación del entorno |
| Interfaz | `recursos/css/*`, `recursos/js/*`, `tailwind.config.cjs` | Tokens, componentes, retroalimentación y Alpine |
| Herramientas | `herramientas/*` | Construcción de activos, contraste y arranque de PHPStan |
| Calidad | `tests/*`, `phpunit.xml`, `phpstan.neon`, `.php-cs-fixer.dist.php`, `eslint.config.js`, `.github/workflows/ci.yml` | Pruebas y CI |
| Documentación | `docs/adr`, `docs/procesos`, `docs/ui`, `docs/seguridad`, `README.md`, `CHANGELOG.md` | Documentación viva |

## Validaciones ejecutadas

Todas se ejecutaron en el equipo local con MAMP. Son resultados reales:

- **`php cli/verificar_entorno.php`:** «entorno apto (3 avisos)». Los avisos son opcache no cargado, HTTP en local y MySQL 5.7 aceptado solo en local.
- **`php cli/migrar.php --aplicar`:** 6 migraciones aplicadas en `kora` sin modificar el SQL del Anexo B.
- **`php herramientas/composer.phar verificar`:** PSR-12 sin cambios pendientes, PHPStan nivel 6 sin errores y PHPUnit con 27 pruebas y 132 aserciones en verde.
  - Incluye la ida y vuelta de las migraciones sobre `kora_pruebas`, el rechazo de una migración alterada, la CSP sin `unsafe-*` ni scripts sin nonce, los fragmentos HTMX, el 422, el 500 sin detalles, la salud con token y el mantenimiento.
- **`npm run lint:js`:** sin errores.
- **`npm run contraste`:** los 20 pares cumplen en modo claro y oscuro, después de ajustar los tokens.
- **`npm run construir`:** JS de 39,9 KB y CSS de 6,6 KB comprimidos con gzip, dentro del presupuesto de 80 y 60 KB.
- **Pruebas con curl contra Apache de MAMP:**
  - `/`, `/sistema-diseno` y `/health/live` responden 200, y `/no-existe` responde el 404 propio.
  - `/.env` y `/bd/…sql` responden 403; `/vendor/autoload.php` responde 404.
  - Las cabeceras CSP y X-Request-ID están presentes.
  - `/health/ready` responde 404 sin token y `listo` con token.
  - El mantenimiento devuelve 503 con `X-Mantenimiento: activo`.
- **Capturas con Edge en modo headless:**
  - Inicio y vitrina en escritorio y a 520 px. Se detectaron y corrigieron las muestras de color purgadas por Tailwind, el desbordamiento del menú y el botón móvil visible en escritorio.
  - Alpine CSP inicializa los componentes con la CSP activa (`aria-pressed` correcto en el selector de tema).

## Riesgos

- **Paridad de motor.** Una diferencia entre MySQL 5.7 y MariaDB solo aparecería en la CI o en staging; mitigación en ADR-002.
- **Modo claro sin revisión visual.** Solo se capturó la interfaz en modo oscuro (el del sistema); el claro está cubierto por la verificación de contraste, pero no por capturas.
- **Pruebas en el navegador.** No hay pruebas E2E en navegador real (Playwright): el SRS las planea para la I1 en adelante. El comportamiento de toasts, diálogo y esqueleto se verificó por contrato (cabeceras y fragmentos), no por interacción.
- **Demostraciones públicas sin CSRF.** `/sistema-diseno/demostracion/*` es público y sin CSRF porque solo usa GET y no escribe. Debe retirarse o protegerse antes de producción (I8).

## Pendientes

- Ítems de Hostinger que dependen de la cuenta: 1 a 3 y 5 a 9 ([verificación](../seguridad/verificacion-hostinger.md)).
- Validar los supuestos S-01 a S-06, S-15, S-16, S-19, S-20 y S-22 con la contraparte (hito M1).
- Primer commit y push a `github.com/mig-conversaciones/kora`: el repositorio quedó inicializado con el remoto, pero sin commits, a la espera de la orden del responsable.
- Pruebas E2E y de accesibilidad con Playwright y axe, y ADR-015 (fuera de la v1).

## Aprobación humana

Pendiente: revisión del líder técnico y aceptación del hito M1 por la contraparte.
