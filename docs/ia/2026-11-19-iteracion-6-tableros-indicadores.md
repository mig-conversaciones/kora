# Registro de entrega asistida por IA — Iteración 6 «Tableros e indicadores»

- **Fecha:** 2026-11-19
- **Herramienta y modelo:** Claude Code (extensión de VS Code), modelo Claude Opus 5.5 (`claude-opus-5-5`)
- **Responsable humano:** líder técnico del proyecto, que revisa y aprueba
- **Requisitos:** RF-062 a RF-082, RNF-PERF-001 (borrador), RNF-PERF-012; Obs. 4
- **Decisiones:** [ADR-020](../adr/020-tableros-e-indicadores.md)

## Objetivo

- **Tablero central:** filtros en la URL, seis KPI, IND_001 con tabla equivalente, IND_002 en pesos o unidades, estado de la información y aviso de conciliación. Para el líder de unidad, se calcula solo con sus unidades.
- **Tablero técnico de Ganadería:**
  - interruptor de práctica;
  - IND_003 con su anillo y la salud reproductiva con filtros rápidos;
  - IND_08 con velocímetro y selector de derivado;
  - IND_12 e IND_17.
  Las demás unidades muestran «en preparación».
- **Caché de tableros** con claves versionadas.
- **Exportaciones** XLSX, CSV y PDF en la cola, con descarga del autor durante 24 horas.
- **Oráculos de no regresión:** con los libros reales y con el escenario de capacitación.

## Supuestos

- **Recaudo externo del aviso de conciliación:** todos los recaudos del periodo, de cualquier tipo de ingreso.
- **Semáforo de IND_003:** verde si alcanza la meta; ámbar si no.
- **Escala del velocímetro:** para el global, de 0 a 120 %; para un derivado, de 0 al doble del máximo del rango meta.
- **CSV:** coma como separador y punto decimal (RFC 4180), números sin comillas.
- **Datos personales en las exportaciones:** ninguna lleva identificaciones de pagadores, así que no se exige reautenticación (RAC-026 aplica solo a exportaciones con datos sensibles).

## Decisiones

- [ADR-020](../adr/020-tableros-e-indicadores.md): cálculo en SQL sobre el alcance, caché versionada, SVG del servidor y exportaciones en la cola.
- **Dependencia nueva:** `dompdf/dompdf` ^3.0 (instalada la 3.1.6), solo en el trabajador (04-01, tabla de dependencias).
- **Corrección de la I5:** en el presupuesto, los componentes vacíos valen 0.

## Archivos principales

| Grupo | Archivos |
|---|---|
| Cálculo | `app/Tableros/`: `FiltrosTablero`, `ServicioIndicadores`, `ServicioTableros`, `Formato`, `ServicioExportacion`; `Graficos/`: `Svg`, `GraficoBarrasSvg`, `GraficoBulletSvg`, `GraficoAnilloSvg`, `GraficoVelocimetroSvg` |
| Identidad en la cola | `app/Politicas/IdentidadActor.php`; trabajos `ExportarXlsx` y `ExportarPdf` en la cola `reportes` (`config/cola.php`); limpieza de vencidas en `LimpiezaPeriodica` |
| Web | `ControladorTableros`, `ControladorExportaciones`; vistas `tableros/*` y `exportaciones/*`; `config/rutas.php`, `config/menu.php`, `config/app.php`; estilos en `recursos/css/app.css`; contenido de Tailwind ampliado a `app/Tableros` |
| Pruebas | `tests/Integracion/OraculosIndicadoresTest.php` |
| Documentación | ADR-020, `docs/runbooks/tableros-y-exportaciones.md`, `docs/rendimiento/plan-carga.md`, `README.md`, `CHANGELOG.md` |

## Validaciones ejecutadas

Todas son resultados reales en MAMP: PHP 8.3.36 y MySQL 5.7.24.

- **`composer verificar`:** PSR-12, PHPStan nivel 6 sin errores y PHPUnit con 126 pruebas y 1.072 aserciones en verde.
- **`OraculosIndicadoresTest`** (8 pruebas):
  - **Centro 2026 (sintético):**
    - Asignación de 185 M, con su desglose de 45, 80 y 60 M.
    - Venta directa de 3.265.000 en 61 pagos, autoconsumo de 176.000 y total de 3.441.000; Leche, 3.045.000.
    - Centro: 1.024 M en 9 asignaciones.
    - Enero: asignación de 185 M y venta directa de 0.
    - Canales excluyentes.
    - AUTO_001 al 0 % con 2 entregas sin proceso.
  - **Hato:** 21/31 = 67,7 % y 21/35 = 60 %, con el anillo de 35.
  - **Capacitación 2027:**
    - AUTO_001: 3.200 L, 80 % y 4 lotes.
    - IND_08: 97 %; sin el factor del queso quedan 3 lotes.
    - IND_17: 1.337,5 COP/L con meta de 1.650. No cambia con el cupo y queda pendiente sin gastos.
    - Medida incompatible: entregado de 2.400.
    - Rotación: el lote cierra al primer día; un lote abierto queda con 200 L y 25 días; cuenta la salida anterior al filtro.
  - **Extremo a extremo:**
    - El líder de GAN ve 185 M, 3,27 M, 176 mil y 3,44 M, y en el HTML no hay «Granja» ni 1,02 mil M.
    - La unidad manipulada se descarta con un aviso.
    - Un rango invertido da 422 sin cifras.
    - La caché se invalida al subir la versión de datos.
    - El técnico conserva «volver», incluye la práctica para la Coordinación académica, ordena las secciones por rol y responde 404 a un líder de otra unidad.
  - **Exportaciones:**
    - Responden 202. El CSV lleva BOM y números como números, y solo GAN.
    - El PDF empieza con `%PDF` y su HTML trae las cifras de la pantalla.
    - Los recaudos salen sin columnas de datos personales.
    - Otro usuario recibe 404; vencido, también 404, y la limpieza borra el archivo.
  - **Libros reales** (importados con el flujo real, imprimiendo solo conteos):
    - 1.024 M, 185 M, 3.265.000 en 61 pagos, 176.000, 3.441.000 y Leche 3.045.000.
    - Hato: 35, 21/10/4 y 67,7 %.
    - Corte del 09/11/2026; 17 entregas sin proceso y 1 recaudo sin unidad.
- **Activos:** CSS de 8,9 KB y JS de 39,9 KB con gzip. ESLint y el contraste AA en verde. Las vistas nuevas no tienen estilos ni manejadores en línea.
- **MAMP:** `/tableros/central`, `/tableros/unidad/GAN` y `/exportaciones/{ulid}` redirigen al ingreso sin sesión.

## Hallazgos sobre el SRS (para errata)

- **RF-069:** indica recaudos «del 01/04/2026 al 24/04/2026», pero el libro y la extracción del prototipo terminan el **19/04/2026**.
- **2.10, salidas:** cuenta 146 salidas y 145 sin recepción. Una fila de la base de ventas no tiene medida ni producto y la importación la rechaza (I5), así que quedan 144 sin recepción más 1 rechazada. Decide la contraparte.
- **I5, presupuesto:** rechazaba Agrícola y Hortalizas porque tienen «Alimentación» vacía. Corregido: un vacío vale 0.

## Riesgos

- **Sin medición de RNF-PERF-003** (tablero en 1.200 o 500 ms): el plan de carga queda en borrador para la I7.
- **Sin prueba en navegador:**
  - *tooltips* con teclado y lector de pantalla;
  - modo oscuro de los gráficos;
  - columnas del gráfico en móvil;
  - axe.
- **El *tooltip* no se cierra con Esc** (WCAG 1.4.13): se oculta al quitar el foco o el puntero.
- **`cache.precalentar` no está registrado;** la primera consulta tras cada escritura paga la caché fría.
- **Los PDF de dompdf no llevan gráficos,** solo tablas y KPI.

## Pendientes

- **Contraparte:**
  - validar el perfil de carga (S-07, S-08) y autorizar las pruebas de carga (S-10);
  - errata de RF-069;
  - la fila de ventas sin medida;
  - la Obs. 4 en una sesión con el líder.
- **I7:** k6 en el entorno de pruebas (smoke, sostenida, pico y ráfaga), EXPLAIN de las consultas de los tableros, precalentado de la caché y respaldo previo a importar (RDL-004).
- **I8:** auditoría WCAG 2.2 AA con axe, E2E en navegador y UAT del piloto con la Obs. 4.

## Aprobación humana

Pendiente: revisión del líder técnico y aceptación de la Obs. 4 (hito M5).
