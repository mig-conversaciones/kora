# ADR-020: Tableros con indicadores calculados en SQL sobre el alcance, caché versionada, SVG del servidor y exportaciones en la cola

- Estado: Aceptado
- Fecha: 2026-11-19
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RF-062 a RF-082, RNF-PERF-012, RUI-017, RUI-022, Obs. 4, D-20, D-31, ADR-009, ADR-010

## Contexto

La I6 entrega el tablero central y el tablero técnico de Ganadería. Hay tres condiciones:

- Las cifras deben ser exactamente las del prototipo (2.10). Sus fórmulas en `js/metrics.js` son la referencia funcional.
- Un líder de unidad nunca debe ver datos de otra unidad, ni siquiera en el HTML (Obs. 4).
- El plan de Hostinger no tiene Redis y la CSP prohíbe los estilos en línea.

## Decisión

- **Un servicio de cálculo, `ServicioIndicadores`.**
  - Cada indicador es una consulta agregada en SQL que lleva la condición de `AlcanceDatos`.
  - Los importes se suman sobre DECIMAL y se operan como enteros de centavos; los floats solo aparecen en los porcentajes.
  - Se conservan las reglas del prototipo:
    - el presupuesto es anual y no se prorratea;
    - los canales son excluyentes;
    - las ventas por transferencia no se suman;
    - el recaudo sin unidad no se reparte;
    - no se suman medidas distintas;
    - una división por cero da «—».
- **`FiltrosTablero`.** Valida en el servidor el rango y la unidad (solo las del alcance) y los serializa en la URL. El periodo es la intersección de la vigencia con «desde» y «hasta».
- **Caché `cache-aside` en archivos** (`ServicioTableros`, ADR-010).
  - Clave: centro, huella del alcance, huella de los filtros, versión de datos y versión del cálculo (`CALCULO`).
  - La versión de datos sube en la misma transacción de cada escritura, así que la invalidación es inmediata.
  - Dos alcances nunca comparten una entrada.
- **Gráficos SVG generados en el servidor** (`GraficoBarrasSvg`, `GraficoBulletSvg`, `GraficoAnilloSvg` y `GraficoVelocimetroSvg`).
  - Los colores salen de clases que leen los tokens, así que se adaptan al modo oscuro y no usan estilos en línea.
  - Cada columna de IND_001 es un enlace enfocable con `aria-label`. Su *tooltip*, solo CSS, se abre con `:hover` y `:focus-within`, y hay una tabla equivalente.
- **Exportaciones** (`ServicioExportacion`) en la cola `reportes`, con los trabajos `exportacion.xlsx` (XLSX o CSV) y `exportacion.pdf` (dompdf 3).
  - La identidad y el alcance del autor se releen al ejecutar (`IdentidadActor`, RAC-024).
  - El archivo queda en `almacenamiento/exportaciones` con un nombre aleatorio y lo descarga solo su autor, durante 24 horas.
  - La limpieza periódica borra los vencidos.
  - Las exportaciones no llevan identificaciones de pagadores.
  - El CSV va en UTF-8 con BOM, con los números sin comillas y las celdas protegidas contra fórmulas.

## Consecuencias

- **Oráculos de 2.10 con los libros reales.** Se reproducen: 1.024 M, 185 M, 3.265.000 en 61 pagos, 176.000, 3.441.000, Leche 3.045.000, 21/31 = 67,7 % y 21/35 = 60 %. El escenario de capacitación reproduce 80 %, 97 % y 1.337,5 COP/L.
- **Dos diferencias con el SRS,** que se documentan y no se ocultan:
  - RF-069 dice «recaudos al 24/04/2026», pero el libro y la extracción del prototipo terminan el 19/04/2026. Es una errata del SRS.
  - 2.10 cuenta 146 salidas, 145 de ellas sin recepción. Una fila de ventas no tiene medida ni producto y la importación la rechaza: quedan 145 salidas, 144 sin recepción, más 1 en el reporte de errores. La contraparte debe decidir si esa fila se corrige en la fuente.
- **Corrección de la I5.** En el presupuesto, una celda vacía de mantenimiento, alimentación o materiales vale 0. Agrícola y Hortalizas no tienen semovientes, dejan en blanco «Alimentación» y antes se rechazaban, con lo que faltaban 213 M.
- **La rotación (IND_12) depende de «hoy»:** el corte es la menor entre «hasta» y hoy, así que con una vigencia futura es hoy. Hasta 10 minutos de caché es aceptable.
- **El precalentado de la caché** (`cache.precalentar`, RF-Q-019) no se registra todavía. La caché fría se mide en la I7.

## Alternativas consideradas

- **Librería de gráficos en el navegador** (Chart.js, ECharts). Suma unos 60 KB de JS, sale del presupuesto de RNF-019 y necesita datos en JSON: es más superficie para filtrar datos fuera del alcance.
- **Agregados en PHP sobre filas.** Leer todas las salidas y recaudos no escala con 12.000 salidas al año y duplica la lógica de alcance.
- **Generar el PDF en la solicitud.** dompdf tarda segundos y gasta memoria: rompería RNF-PERF-002.
