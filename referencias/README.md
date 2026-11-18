# Producción CAB

Mockup HTML funcional del sistema de producción del Centro Agropecuario de Buga. Incluye el tablero central y la vista técnica de Ganadería definidos en `Guia_Tableros_Mando.docx`, además de administración local de la información.

La **guía de uso ilustrada en PDF, versión 2 (14 páginas)**, con capturas reales actualizadas de los tres espacios de trabajo, roles, menús y matriz de permisos, está en [Guia_de_uso_Produccion_CAB.pdf](output/pdf/Guia_de_uso_Produccion_CAB.pdf). También se puede descargar desde **Guía del prototipo → Descargar guía PDF** dentro de la aplicación.

## Abrir el prototipo

**Con doble clic:** abre `index.html` directamente en el navegador. Conserva las carpetas `assets`, `output` y el archivo `styles.css` junto al HTML para disponer de la aplicación, su diseño y la guía PDF.

**Con MAMP:** inicia Apache y abre `http://localhost/dashboard_laila/`. Si MAMP usa otro puerto, inclúyelo en la dirección, por ejemplo `http://localhost:8888/dashboard_laila/`.

**Con Node.js:** desde esta carpeta ejecuta:

```powershell
npm.cmd start
```

Luego abre **http://127.0.0.1:4173/**. No requiere instalar paquetes ni compilar. Para detenerlo, presiona `Ctrl+C` en esa terminal. También puedes ejecutar `node scripts/serve.mjs`.

La aplicación carga un archivo JavaScript clásico con los datos iniciales incluidos; no necesita leer JSON por red ni cargar módulos del navegador. Funciona directamente desde disco y por HTTP, sin servicios externos ni conexión a Internet. Si el navegador bloquea el almacenamiento, se muestra un aviso para exportar los cambios antes de cerrar. La apertura directa y la apertura por servidor tienen almacenamientos distintos.

## Recorrido recomendado

1. En **Tablero central**, filtra por unidad, periodo, producto y canal. Pasa por las barras para consultar importes y desglose presupuestal.
2. Activa **Explorar demo**. La franja ámbar identifica las operaciones simuladas de abril de 2026. Presupuesto y hato parten de la información suministrada.
3. Abre **Explorar Ganadería** para consultar composición del hato, transformación láctea, rotación y costo por litro. El interruptor de práctica modifica la población operativa; el anillo conserva el inventario completo.
4. En los módulos administrativos, busca y usa los iconos de detalle, edición y archivo. Los formularios validan referencias, cantidades, fechas y existencias. Los registros archivados se consultan y restauran con el filtro de estado.
5. En **Catálogos y parámetros**, ajusta metas y factores de conversión, o exporta/importa el respaldo JSON en **Fuentes y respaldos**.

Los perfiles son una demostración: Administración edita todos los módulos; Líder de unidad edita producción, salidas, hato, gastos y transformaciones; Coordinación académica edita hato y transformaciones. Las demás secciones quedan en consulta. No hay inicio de sesión ni autorización de servidor.

Al cambiar de rol también cambia el espacio de trabajo: Administración abre el tablero central; Líder de unidad abre Ganadería con práctica excluida y el filtro de alertas seleccionado; Coordinación académica abre Ganadería con práctica incluida y su tabla didáctica antes de los gráficos. Cada perfil muestra sus accesos rápidos y un menú separado entre gestión y consulta. Los accesos a Gastos y Transformaciones abren directamente la pestaña editable del rol. Cambiar de perfil no modifica registros ni mezcla escenarios.

## Datos y persistencia

- **Datos de los archivos:** 9 asignaciones presupuestales, 106 recaudos, 6 recepciones con datos, 146 salidas, 6 procesos, 35 animales y 190 productos. Se conservan archivo, hoja y fila de procedencia.
- **Escenario demostrativo:** operaciones sintéticas, vinculadas, que permiten probar todos los indicadores. Las cifras simuladas no representan resultados institucionales.
- Cada escenario tiene una clave independiente en `localStorage`. Crear, editar, archivar o restaurar actualiza los indicadores al volver a los tableros.
- Los cambios permanecen en el mismo navegador y origen web. `localhost`, `127.0.0.1` y puertos diferentes tienen almacenamientos separados.
- Exportar produce un respaldo del escenario completo. Importar requiere el mismo escenario y pide confirmar la sustitución. Restaurar elimina sus ediciones locales y vuelve a la base inicial; el otro escenario permanece intacto.
- Los Excel y el documento originales no se modifican. La aplicación no vuelve a leerlos automáticamente.

## Lectura correcta de los indicadores

| Indicador | Criterio aplicado |
| --- | --- |
| IND_001 | Asignación anual frente a recaudos externos más salidas por autoconsumo del periodo. Las ventas por transferencia se muestran para conciliación y no se vuelven a sumar. El saldo es comercial, no saldo de caja ni ejecución CDP/RP. |
| IND_002 | Solo entregas vinculadas por proceso, unidad y medida. Las cantidades físicas se presentan individualmente por medida. Cupos anuales frente a entregas filtradas. |
| IND_003 | Con práctica: 21/35 = 60 %. Sin práctica: 21/31 = 67,7 %. El anillo siempre conserva las 35 hembras. El hato es una fotografía al 9 de noviembre de 2026, sin historia reconstruida. |
| IND_08 | Litros asignados divididos entre cantidad recibida del derivado; global mediante factores de equivalencia en leche. Requiere entrega, transformación y recepción enlazadas. |
| IND_12 | Días hasta agotar cada lote y producto. Se usa el historial de salidas hasta la fecha de corte; abiertos muestran antigüedad y no entran al promedio. |
| IND_17 | Gastos ejecutados de mantenimiento y alimentación/vacunas menos su componente asignado a vacas de práctica, divididos entre litros recibidos de Ganadería en el periodo. Los $125 millones de la fuente son presupuesto, no gasto ejecutado. Los cupos no se suman a la producción. |

Las fechas de las fuentes difieren: recaudos de abril de 2026, salidas de octubre de 2025 a enero de 2026, presupuesto de vigencia 2026 y hato de noviembre de 2026. Los filtros pueden producir resultados vacíos; esto no se rellena con cifras inventadas.

145 salidas no encuentran recepción en los insumos; las 17 entregas de autoconsumo carecen de un identificador de proceso válido. Un recaudo está rotulado «Ganadería y Granja» y permanece sin distribuir. Los conceptos genéricos como «Queso» no se homologan automáticamente a una variedad específica. Se pueden filtrar como conceptos de fuente. Los indicadores incompletos quedan pendientes o muestran únicamente la parte verificable.

## Estructura y verificación

- `index.html` y `styles.css`: estructura y diseño adaptable.
- `js/data.js`: escenarios, respaldo y almacenamiento.
- `js/metrics.js`: fórmulas sin dependencia de la interfaz.
- `js/schemas.js`: formularios, referencias y reglas de edición.
- `js/app.js`: navegación, gráficos SVG, tablas y acciones; fuente de desarrollo.
- `assets/app.bundle.js`: aplicación preparada para apertura directa. Después de editar el código o los datos, ejecuta `npm.cmd run build` para regenerarla (instala las herramientas con `npm.cmd install` la primera vez). Para abrir el mockup ya preparado no se necesita Node.js.
- `data/source.json`: base normalizada.
- `scripts/prepare-data.mjs` y `scripts/read-workbooks.ps1`: extracción reproducible de los Excel mediante ZIP/XML, sin paquetes externos. Ejecuta `npm.cmd run data` para regenerar el JSON; los respaldos locales existentes no se reemplazan automáticamente.
- `tests/metrics.test.mjs`: conciliación, fórmulas, escenarios y reglas de datos. Ejecuta `npm.cmd test`.

## Siguiente etapa de servidor

Sustituir el repositorio local por una API y base de datos compartida; conservar las fórmulas como reglas revisables. Implementar usuarios y permisos reales, auditoría de movimientos, conciliación de pagos por referencia, importación asistida y corrección de lotes históricos. Separar las tablas operativas de los agregados analíticos; usar claves de unidad, producto, lote y proceso, sin unir tablas transaccionales solo por nombre o fecha.

Antes de publicar resultados oficiales se requieren registros de gasto ejecutado, asignación sustentada de costos académicos, vínculos de transformación, referencias válidas de procesos y cobertura histórica de recepciones. La guía se usa como especificación de interfaz; este prototipo no certifica cumplimiento normativo.
