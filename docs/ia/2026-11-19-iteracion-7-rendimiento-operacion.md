# Registro de entrega asistida por IA — Iteración 7 «Rendimiento y operación»

- **Fecha:** 2026-11-19
- **Herramienta y modelo:** Claude Code (extensión de VS Code), modelo Claude Opus 5.5 (`claude-opus-5-5`)
- **Responsable humano:** líder técnico del proyecto, que revisa y aprueba
- **Requisitos:** RNF-PERF-001 a RNF-PERF-020, RDL-001 a RDL-011 (los de esta iteración), ROB-001 a ROB-014, RS-012, RS-019, RS-022, RGO-004
- **Decisiones:** [ADR-021](../adr/021-operacion-respaldos-despliegue.md)

## Objetivo

Dejar demostrada la capacidad que se puede medir sin staging, y lista la operación:

- inventario de consultas con EXPLAIN y su optimización;
- dataset de 3 años;
- copias cifradas con restauración probada;
- despliegue y reversión;
- monitoreo de integridad y del certificado;
- precalentamiento;
- runbooks;
- scripts k6.

## Supuestos

- **Cifrado de las copias.** Se usa un sobre de libsodium, con XChaCha20-Poly1305 por bloques y la clave sellada para la llave pública del custodio, en lugar de `age` o `gpg`: libsodium ya es obligatorio (RNF-018) y no requiere binarios adicionales en Hostinger.
- **Umbral de «importación masiva»** (RDL-004): 500 filas (`RESPALDO_IMPORTACION_FILAS`), el tamaño de un bloque de aplicación.
- **Retención.** «12 mensuales» se interpreta como la última copia diaria de cada uno de los 12 meses anteriores.
- **Consultas por solicitud** (RNF-PERF-009): se cuentan **todas** las consultas de la solicitud, incluidas la sesión y la identidad.
- **Caché de permisos.** Una entrada de 5 minutos es aceptable porque la fila del usuario, con su estado y su versión de permisos, se lee en cada solicitud (4.6.2).

## Decisiones

- [ADR-021](../adr/021-operacion-respaldos-despliegue.md): copias en PHP con sobre de libsodium; despliegue por versiones ensayado en la CI; presupuesto de consultas medido sobre el dataset sintético.
- **Migración 014:** índices de los listados.
  - El índice por unidad de `salidas` empieza por `unidad_id` para no reemplazar el índice automático de la llave foránea `fk_salidas_unidad`.
  - La primera versión, con `(centro_id, unidad_id, …)`, hacía que la ida y vuelta fallara según cómo se hubiera creado ese índice. Se detectó al repetir la ida y vuelta.

## Archivos principales

| Grupo | Archivos |
|---|---|
| Operación | `app/Operacion/`: `SobreCifrado`, `ServicioRespaldo`, `CifrasControl`, `GeneradorDatosCarga`, `VerificadorIntegridad`, `MonitorCertificado`; trabajos `PrecalentarCache` y `VerificarIntegridad` |
| CLI y scripts | `cli/respaldo.php`, `generar_dataset.php`, `humo.php`, `limpiar_cache.php`, `registrar_despliegue.php`, `verificar_integridad.php`; `scripts/empaquetar.sh`, `desplegar.sh`, `rollback.sh`, `backup.sh`, `restaurar.sh`, `verificar_integridad.sh` |
| Rendimiento | `BaseDatos::observar`; caché de permisos en `RepositorioUsuarios`; `ServicioIndicadores` (rotación con `IN`, consultas unidas, memoria por solicitud); `ServicioGestionRecaudos` (sin N+1); `ServicioConsultaAuditoria`; `ServicioVersionDatos`; `ServicioCatalogos`; `Cache` (endurecida) |
| Esquema | `bd/migraciones/013_crear_respaldos_despliegues`, `014_indices_listados` |
| Configuración | `config/app.php` (`ServicioRespaldo`, `VerificadorIntegridad`, programador, salud), `config/cola.php` (`cache.precalentar`, `seguridad.verificar_integridad`), `.env.example` (`RESPALDO_*`, `SSL_AVISO_DIAS`), `.gitattributes` |
| Pruebas y carga | `tests/Integracion/RendimientoOperacionTest.php` (13 pruebas); `tests/carga/`: `inventario_consultas.php`, `medir_local.php`, `escenarios.js`, `validar_resultados.php`, `README.md`, `resultados/0.8.0-local/` |
| CI | Trabajo `operacion` en `.github/workflows/ci.yml` |
| Documentación | ADR-021; `docs/rendimiento/consultas-indices.md`, `estrategia-cache.md`, `plan-carga.md`; 11 runbooks nuevos con su inventario; acta del ensayo de restauración; `docs/seguridad/verificacion-hostinger.md` (ítems 13 a 18); README; CHANGELOG 0.8.0 |

## Validaciones ejecutadas

Todas son resultados reales en MAMP: PHP 8.3.36 y MySQL 5.7.24.

- **`composer verificar`:** PSR-12, PHPStan nivel 6 sin errores y PHPUnit en verde (ver el cierre de la iteración).
- **Dataset 3a**, generado en unos 62 s:
  - 39.611 salidas, 9.900 recaudos, 9.900 recepciones, 2.640 gastos y 825.000 eventos de auditoría;
  - dos centros;
  - cadena de auditoría íntegra.
- **Inventario de consultas.** Antes de las correcciones:
  - tablero técnico, 1.019 consultas en frío;
  - bandeja de clasificación, 210;
  - tablero central, 22;
  - listados, 11 y 12;
  - formulario, 8.

  Después, todas dentro del máximo:
  - tablero central, 14 en frío y 5 en caliente;
  - tablero técnico, 13 y 5;
  - listados, de 6 a 8;
  - bandeja, 8;
  - formulario, 5.

  Planes antes y después de la migración 014:
  - listado de salidas: de 43 a 0,4 ms;
  - recepciones: de 47 a 0,5 ms;
  - auditoría: de 51 a 4 ms.

  El costo de escribir 1.000 salidas queda dentro del ruido (33,7 a 40,4 ms).
- **Tiempos locales de servidor**, en proceso y con 30 repeticiones: tableros con p95 de 104 a 172 ms en frío y de 29 a 46 ms en caliente; listados con p95 por debajo de 44 ms.
- **Simulacro de restauración** sobre el dataset 3a: 66 MB cifrados, restauración en unos 40 s y 62.207 filas comparadas; cadena de 825.014 eventos íntegra; conforme.
- **`RendimientoOperacionTest`:**
  - el generador nunca corre en producción;
  - máximo de consultas por pantalla y aislamiento entre unidades y centros;
  - revocación con caché de permisos;
  - precalentamiento;
  - caché: vencimiento, estampida de 20 procesos y almacenamiento caído;
  - sobre cifrado contra una llave ajena, una alteración y un truncamiento;
  - copia, restauración sobre la base de pruebas y archivo dañado detectado;
  - retención de 30 más 10 copias en 400 días;
  - copia previa;
  - integridad;
  - certificado de 14 días;
  - tareas del programador;
  - registro del despliegue y pruebas de humo.
- **Scripts bash:** sintaxis verificada (`bash -n`). La ejecución real la hace el trabajo `operacion` de la CI en Linux: en Windows no hay enlaces simbólicos.

## Riesgos

- **Sin pruebas de carga.** Sin staging ni autorización del titular (S-10) no hay k6. RNF-PERF-003 a RNF-PERF-006 siguen sin demostrar; los tiempos locales no se extrapolan.
- **Planes de ejecución.** MySQL 5.7 estima los planes; falta `ANALYZE FORMAT=JSON` en MariaDB 10.6.
- **Trabajo `operacion` de la CI.** Es nuevo: puede fallar en su primera ejecución por diferencias del runner (cliente de MariaDB, permisos o rutas). Se revisa tras el push.
- **Prueba de copias en la CI.** La del trabajo PHP usa `mysqldump` 8 del runner contra MySQL 5.7 y MariaDB 10.6 (con `--column-statistics=0` detectado automáticamente).
- **Recifrado.** `seguridad.recifrar` no existe todavía: la clave anterior debe quedarse en el llavero.
- **Caché de permisos.** Un cambio de permisos por SQL de soporte sin subir `version_permisos` tarda hasta 5 minutos en verse (documentado en los runbooks).
- **Sin ensayar en Hostinger:** la copia externa por rsync, el monitoreo externo, el enlace de `public_html` y OPcache con el cambio de `current`.

## Pendientes

- **Titular y contraparte:**
  - staging y autorización de carga (ítem 18 del 4.8);
  - custodio de la llave de las copias y destino externo;
  - servicio de monitoreo externo;
  - validación del perfil de carga (S-07, S-08).
- **Staging:**
  - k6 en los escenarios smoke, sostenida, pico y ráfaga, y `validar_resultados.php`;
  - prueba trimestral de restauración;
  - `ANALYZE FORMAT=JSON`;
  - cambio de `current` con LiteSpeed y OPcache;
  - simulación de caída del monitoreo.
- **I8:** auditoría WCAG con axe, E2E en navegador, ZAP, UAT con la Obs. 4 y acta de salida.

## Aprobación humana

Pendiente: revisión del líder técnico y aceptación del hito M6 («informe de rendimiento y restauración probada»), parcial hasta la ejecución en staging.
