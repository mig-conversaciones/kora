# Registro de entrega asistida por IA — Iteración 5 «Importación y recaudos»

- **Fecha:** 2026-11-18
- **Herramienta y modelo:** Claude Code (extensión de VS Code), modelo Claude Opus 5.5 (`claude-opus-5-5`)
- **Responsable humano:** líder técnico del proyecto, que revisa y aprueba
- **Requisitos:** RF-030 a RF-034, RF-083 a RF-097; criterio de la Obs. 3 (acta de carga)
- **Decisiones:** [ADR-019](../adr/019-importacion-asistida.md)

## Objetivo

- **Importación asistida de las ocho fuentes:** carga segura, detección, validación en *streaming* en la cola, vista previa, aplicación con procedencia e idempotencia, reversión, reporte de errores, plantillas, historial y acta de carga con cifras de control.
- **Recaudos de la DG:** importación sin datos personales, clasificación con sugerencias, conciliación con ventas e informe de diferencias.
- **Alta manual de recaudos** condicionada a la funcionalidad `recaudos_alta_manual`.
- **Contrato OpenAPI** de integración con el software de ventas.

## Supuestos

- **Medidas fuera del esquema.**
  - Las del catálogo (hora, hectárea, metro cuadrado…) quedan como error de fila: el esquema solo admite L, kg, ud, lb y cubeta.
  - «Manojo 250 gr» y «Panal» de la base de ventas se registran como unidad, con advertencia.
- **Respaldo previo (RDL-004).** En esta iteración se cubre con el valor anterior de cada fila, que permite revertir. La copia de la base llega con la I7.
- **Vigencia del presupuesto.** Se elige al cargar; no se contrasta con el título de la fila 1.
- **Endpoint de integración (RF-098).** Fuera de la v1. El contrato se documenta y el intercambio equivalente por archivo funciona (RF-096).

## Decisiones

- **[ADR-019](../adr/019-importacion-asistida.md):**
  - una clase por fuente y un lote por fuente, identificado por el hash;
  - token firmado entre la carga y la elección de fuentes;
  - validación y aplicación en la cola, con aplicación reanudable fila por fila;
  - reversión con valores anteriores;
  - cifras de control del archivo frente a la base.
- **Dependencia nueva:** `openspout/openspout` ^4.28 (D-35).
- **Alta manual de recaudos (RF-033):** la ruta de la I4 queda detrás de la funcionalidad y exige motivo y referencia del soporte.

## Archivos principales

| Grupo | Archivos |
|---|---|
| Importación | `app/Importacion/`: `Normalizador`, `LectorLibro`, `ServicioCargaArchivos`, `ContextoImportacion`, `FuenteImportacion`, `RegistroFuentes`, `ServicioImportaciones`; `Fuentes/`: `FuenteCatalogo`, `FuentePresupuesto`, `FuenteCupos`, `FuenteRecaudosDg`, `FuenteProduccion`, `FuenteVentas`, `FuenteHato`, `FuenteGastos` |
| Cola | Trabajos `ValidarImportacion`, `AplicarImportacion` y `RevertirImportacion` en la cola `importacion` (`config/cola.php`) |
| Recaudos | `ServicioGestionRecaudos` (clasificación y conciliación); `ServicioRecaudos` (alta manual con motivo y soporte) |
| Web | `ControladorImportaciones`, `ControladorRecaudos`; vistas `importaciones/*` y `recaudos/clasificacion`, `conciliacion`, `diferencias`; `config/rutas.php`, `config/menu.php`, `config/app.php` |
| Contrato | `docs/api/integracion-ventas.yaml` (OpenAPI 3.1) |
| Pruebas | `tests/Integracion/ImportacionesTest.php`, `tests/Unitarias/NormalizadorTest.php`; `OperacionProductivaTest` (alta manual con funcionalidad) |
| Documentación | ADR-019, `docs/runbooks/importaciones-y-recaudos.md`, `README.md`, `CHANGELOG.md` |

## Validaciones ejecutadas

Todas son resultados reales en MAMP: PHP 8.3.36 y MySQL 5.7.24.

- **`composer verificar`:** PSR-12 sin cambios, PHPStan nivel 6 sin errores y PHPUnit con 118 pruebas y 948 aserciones, dos veces seguidas en verde.
- **`ImportacionesTest`** (libros XLSX sintéticos, nunca los reales):
  - **Carga:** se rechazan un PDF renombrado, un `.xlsm`, un archivo de 11 MB y una bomba ZIP de 501 partes, sin crear lotes.
  - **Catálogo:** 4 filas, 3 válidas y 1 con error. Nada cambia antes de aplicar. El reporte tiene exactamente la fila con error. Con errores, aplicar exige «solo las válidas».
  - **Procedencia:** se guardan el lote y la fila; la familia «Animales vivos» queda con rotación animal. El mismo archivo se informa como ya aplicado.
  - **Ventas:**
    - Dos ventas idénticas son dos salidas, por el número de ocurrencia.
    - La venta encuentra su lote al aplicar aunque se validó antes; la que no tiene lote toma la unidad del prefijo.
    - La identificación del cliente queda como «Cliente ****5432».
    - Las cifras de control quedan verificadas.
  - **Reversión:** la producción queda bloqueada por las ventas que dependen de ella. Las ventas se revierten con reautenticación y un motivo auditado; uno corto da 422.
  - **Líder de GAN:** una fila de AGR da el error «Unidad fuera de su alcance». El archivo actualizado inserta 1, actualiza 1 y deja 2 sin cambios.
  - **Recaudos de la DG:**
    - Ningún nombre ni identificación aparece en `recaudos`, `filas_importacion` ni la auditoría.
    - El ambiguo no se prorratea. Clasificarlo crea un evento por recaudo.
    - La conciliación con diferencia (19.000 − 17.600) aparece en el informe; reimportar con una fila nueva solo inserta esa.
  - **Hato:** sin fecha elegida, dos fechas en «Fecha actual» dan error. Con la fecha elegida, se crea el corte vigente con 1 animal por lote, el IEP calculado y una incidencia por IEP importado distinto.
  - **Permisos por fuente:** la Coordinación académica no importa recaudos; la fuente aparece deshabilitada y el POST da 403.
- **Unitarias:** unidades `UP_*` y ambiguas, medidas, seriales de Excel, números locales, encabezados, mapeo exacto antes que parcial, detección de fuentes y columnas del contrato (RF-096).
- **Libros reales (validación en seco, sin escribir en la base, imprimiendo solo conteos):**
  - Se reconocen las 7 fuentes de los 3 libros.
  - **Recaudos:** 106 de producción, 61 de Ganadería por 3.265.000 COP y 1 ambiguo; coincide con RF-030 y RF-058.
  - **Hato:** 35 hembras, 21/10/4, sin errores, con la fecha del corte elegida; coincide con RF-052.
  - **Ventas:** 17 autoconsumos sin proceso; coincide con RF-038.
- **Activos:** CSS 8,0 KB y JS 39,9 KB con gzip; ESLint y contraste AA en verde. Las rutas nuevas redirigen al ingreso sin sesión y las vistas nuevas no tienen estilos ni manejadores en línea.

## Riesgos

- **Sin medición de RNF-PERF-003** (carga en menos de 400 ms en p95) ni de la memoria con 20.000 filas (RF-086); la lectura es en streaming, pero no se midió.
- **El contrato OpenAPI no se ejecutó con Schemathesis** ni hay simulador (RF-095): pendiente para la I8, con el entorno de staging.
- **La carga de los libros reales en la base de staging** con el acta firmada (Obs. 3) queda para hacerla con la contraparte.
- **Sin prueba en navegador** de la subida con HTMX y la consulta periódica del progreso.

## Pendientes

- **Contraparte:** medidas de servicio en el catálogo, significado de la columna «Asignado» de la DG (S-18) y fecha oficial del corte del hato.
- **I6:** tableros que usan los datos cargados; completitud por indicador (RF-060).
- **I7:** copia de la base antes de aplicar (RDL-004) y limpieza de los libros con más de 1 año.

## Aprobación humana

Pendiente: revisión del líder técnico y carga del piloto con acta (Obs. 3).
