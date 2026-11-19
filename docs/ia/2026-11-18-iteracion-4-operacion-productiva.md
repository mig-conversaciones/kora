# Registro de entrega asistida por IA — Iteración 4 «Operación productiva»

- **Fecha:** 2026-11-18
- **Herramienta y modelo:** Claude Code (extensión de VS Code), modelo Claude Opus 5.5 (`claude-opus-5-5`)
- **Responsable humano:** líder técnico del proyecto, que revisa y aprueba
- **Requisitos:** RF-029, RF-035, RF-039 a RF-061; criterios de las Obs. 2, 5 y 6
- **Decisiones:** [ADR-018](../adr/018-operacion-productiva.md)

## Objetivo

Entregar la trazabilidad de la operación:

- **Producción:** recepciones con número interno sugerido y existencias, salidas de venta directa y autoconsumo, y transformación láctea con la recepción del derivado en el mismo flujo.
- **Hato:** inventario por cortes, cálculo del IEP y los días abiertos, semáforos y lote de práctica académica.
- **Calidad:** bandeja de incidencias con correcciones auditadas y el trabajo `calidad.recalcular`.
- **Recaudos:** consulta con filtros y alcance, y registro manual solo para el Grupo de Recaudo.

## Supuestos

- **Importaciones (I5).** Las salidas heredadas sin recepción, los autoconsumos sin proceso y los recaudos sin unidad llegan con la importación. En la I4 la bandeja ya los detecta y corrige; las pruebas los crean directamente en la base.
- **Conciliación (RF-032)** y la clasificación de recaudos (RF-031) son de la I5. La tabla `conciliaciones_recaudo` se crea con la migración 010.
- **Devoluciones (RF-042)** quedan reservadas en `tipo_movimiento = 'DEV'` y en el formato del número interno.
- **RF-060 (completitud por indicador)** y el aviso de RF-038 en el tablero central dependen de los tableros (I6).
- **Edición de transformaciones.** Se registran y se archivan; no se editan. Una corrección es archivar y registrar de nuevo.

## Decisiones

- **[ADR-018](../adr/018-operacion-productiva.md):** reglas puras con los mensajes del prototipo; bloqueo del lote al registrar la salida; una sola transacción para la transformación y su recepción; alcance de la transformación por el origen de la leche; rol además de permiso para escribir recaudos; recálculo idempotente de la bandeja.
- **Corrección de la CI (MariaDB 10.6).** Con el informe JUnit convertido en anotaciones de GitHub se vio la causa del fallo del commit `009d59a`: un interbloqueo (1213) entre dos trabajadores con `SKIP LOCKED`. El reclamo ahora se reintenta (commit `b0cddde`). La CI de ese commit pasa en MariaDB 10.6, MySQL 5.7 y activos.
- **Ampliación del Anexo B:** `animales.archivado_por` y `animales.motivo_archivo`.
- **Correcciones de la I3 encontradas al construir la I4.** En dos controladores el dato que debía marcar el formulario con error (`mesConError` en periodos, `editando` en destinos) quedaba anulado por el orden de la unión de arreglos (`+` conserva la izquierda). Se invirtió el orden. La lista blanca de columnas del repositorio no admitía dígitos (`identificacion_ultimos4`).

## Archivos principales

| Grupo | Archivos |
|---|---|
| Base de datos | `bd/migraciones/010` a `012` (`.sql` y `.down.sql`) |
| Núcleo | `Entrada` (`aUnidades`, `desdeUnidades`, `sumarCon`, `valorSalida`), `Vista::cantidad` |
| Políticas | `PoliticaRecaudos`; prefijos `recepciones`, `salidas`, `transformaciones`, `hato` y `calidad` con `PoliticaAlcanceUnidad` |
| Repositorios | `RepositorioRecepciones`, `RepositorioSalidas`, `RepositorioTransformaciones`, `RepositorioAnimales`, `RepositorioRecaudos` |
| Servicios | `ReglasOperacion`, `ReglasHato`, `ServicioRecepciones`, `ServicioSalidas`, `ServicioTransformaciones`, `ServicioHato`, `ServicioCalidad`, `ServicioRecaudos` |
| Cola | `ConductorColaMysql` (reintento ante interbloqueos), trabajo `RecalcularCalidad` (`calidad.recalcular`) |
| Web | `ControladorProduccion`, `ControladorHato`, `ControladorCalidad`, `ControladorRecaudos`; vistas `produccion/*`, `hato/*`, `calidad/bandeja`, `recaudos/*`; parcial `barra_filtros`; `config/rutas.php`, `config/menu.php`, `config/app.php`, `config/cola.php`; `recursos/css/app.css` |
| CI | `.github/workflows/ci.yml` (sin fail-fast, JUnit), `.github/anotar-junit.php` |
| Pruebas | `tests/Integracion/OperacionProductivaTest.php`, `tests/Unitarias/ReglasOperacionTest.php`, `tests/Unitarias/ColaReclamoTest.php`; `MigracionesTest` (12 migraciones), `PlataformaTest` (independiente del orden) |
| Documentación | ADR-018, `docs/runbooks/operacion-productiva.md`, `README.md`, `CHANGELOG.md` |

## Validaciones ejecutadas

Todas son resultados reales en MAMP: PHP 8.3.36 y MySQL 5.7.24.

- **Migraciones:** 010 a 012 aplicadas en `kora` y `kora_pruebas`; revertidas y reaplicadas. `MigracionesTest` aplica, revierte y reaplica las 12 y compara las tablas presentes.
- **`composer verificar`:** PSR-12 sin cambios, PHPStan nivel 6 sin errores y PHPUnit con 110 pruebas y 811 aserciones. En 14 corridas completas, una tuvo un fallo aislado que no se volvió a reproducir en las 11 siguientes (ver Riesgos).
- **Flujos en `OperacionProductivaTest`:**
  - **Lotes y salidas:** consecutivo sugerido que avanza; medida distinta y producto repetido en el lote rechazados con los mensajes del prototipo. Salida anterior a la recepción, saldo excedido («(100 L)») y autoconsumo sin proceso, rechazados. Venta directa sin recaudo nuevo y con el aviso. Valor 3,333 × 1.000,55 = 3.334,83. Proceso de otra medida rechazado y «Leche» aceptado como el 5013170101. Lote agotado y cerrado. Con salidas, la cantidad no baja de lo entregado, el producto no cambia y el lote no se archiva (409).
  - **Obs. 5 y RF-051:** recepción en otra unidad 403; filtro por IND 403 auditado; lote ajeno 404.
  - **Transformación (Obs. 6):** sin derivados, «No hay derivados lácteos configurados»; sin entregas, «No hay entregas de leche disponibles». Litros excedidos: 422 y la recepción del derivado no queda. Fecha anterior rechazada. Registro válido con recepción en IND; el líder de GAN ve «Queso cuajada», «10,00 L/kg» y «En meta», pero no las recepciones de IND. La entrega no baja de lo asignado ni cambia de canal.
  - **Hato:** parto anterior al nacimiento y partos invertidos rechazados; IEP 440 y días abiertos 127 calculados; «Inventario biológico al 09/11/2026»; semáforos «Fuera de meta» y «Vigilar». La Coordinación académica recibe 403 en la ficha completa y cambia el lote de práctica con motivo auditado. Un corte nuevo copia el estado; la fecha repetida da 422.
  - **Calidad:** detección de salidas sin recepción, autoconsumo sin proceso y recaudo sin unidad; recálculo idempotente; el líder no ve la incidencia sin unidad. Vincular con saldo insuficiente da 422; la vinculación válida resuelve la incidencia, audita antes y después y sube la versión de datos. La aceptación sin corrección exige motivo y sobrevive al recálculo. `calidad.recalcular` se encola (202) y el trabajador real lo completa.
  - **Recaudos (Obs. 2):** la Coordinación administrativa no ve «Nuevo registro» y su POST da 403 auditado. El Grupo de Recaudo registra «3.045.000» como `3045000.00`, con HMAC y últimos 4 dígitos, sin la identificación en la auditoría; el duplicado da 422. El líder de GAN solo ve GAN y el filtro por AGR devuelve vacío.
- **Unitarias:** valor de la salida y su cota, todas las reglas del lote, la salida y la transformación con sus mensajes, rendimiento, IEP, días abiertos y los límites exactos de los semáforos (365/366/400/401 y 90/91/120/121/135/136).
- **Activos:** CSS de 8,0 KB y JS de 39,9 KB comprimidos con gzip; ESLint y el contraste AA pasan.
- **Apache:** las seis pantallas nuevas redirigen al ingreso sin sesión (303) y ninguna vista nueva tiene `style=`, `on*=` ni `hx-on`.
- **CI en GitHub** del commit `b0cddde` (I3 + corrección de la cola): MariaDB 10.6, MySQL 5.7 y activos en verde.

## Riesgos

- **Fallo intermitente sin identificar.** Una de 14 corridas de la suite tuvo un fallo que no quedó registrado y no se reprodujo. Candidatas: el código TOTP calculado justo en el cambio de ventana de 30 s al enrolar las cuentas de prueba, o los tiempos de la prueba de trabajadores concurrentes. Si reaparece en la CI, la anotación JUnit dirá cuál es.
- **Sin prueba de humo con sesión por Apache** de las pantallas de la I4: las pruebas de integración recorren la aplicación completa, pero no la reescritura de Apache ni el navegador (Playwright, pendiente).
- **La corrección de la bandeja pide el id del lote o del proceso** escrito a mano; un selector con búsqueda queda para la I5, cuando haya datos heredados reales.
- **Validaciones pendientes con la contraparte:** S-12 (factores de derivados) y los umbrales de 2.10 con el hato real.

## Pendientes

- **I5:** importación de las bases de producción, ventas, recaudos y estado reproductivo, que alimenta la bandeja (`calidad.recalcular` tras cada lote); clasificación y conciliación de recaudos (RF-031, RF-032).
- **I6:** tableros con IND_003, IND_08, IND_12 e IND_17, completitud por indicador (RF-060) y el aviso de entregas pendientes de vinculación (RF-038).

## Aprobación humana

Pendiente: revisión del líder técnico y demostración de las Obs. 2, 5 y 6 a la contraparte.
