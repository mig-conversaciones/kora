# Mockup funcional del sistema de gestión de producción del CAB

## Resumen y diagnóstico

Construir una aplicación HTML de apariencia ejecutiva moderna, con dos tableros conectados y pantallas para administrar sus registros. El piloto será Ganadería, con navegación preparada para las demás unidades productivas.

La revisión de los cuatro archivos muestra:

- **Presupuesto:** $1.024 millones del Centro; $185 millones corresponden a Ganadería.
- **Recaudo de Ganadería:** 61 pagos por $3.265.000.
- **Hato:** 35 hembras; 21 en producción, 10 horras y 4 de práctica, después de normalizar etiquetas.
- **Trazabilidad incompleta:** 145 de las 146 salidas no encuentran lote coincidente en las recepciones suministradas. Los autoconsumos tampoco contienen identificadores válidos que permitan enlazarlos directamente con AUTO_001–AUTO_006.
- **Periodos distintos:** presupuesto, recaudo, producción e inventario biológico representan cortes diferentes. No deben presentarse como información sincronizada.

La ruta más acertada es **normalizar los insumos, separar datos verificables y demostrativos, construir los flujos administrativos y conectar después los indicadores**.

## Diseño y navegación

- Interfaz en español, fondo claro, menú lateral, cabecera con contexto y tarjetas de indicadores. Verde para venta directa, ámbar para autoconsumo y azul o gris para presupuesto.
- Diseño prioritario para computador, adaptable a tableta y móvil; las tablas extensas tendrán desplazamiento horizontal.
- Inicio en el **Tablero central**, con acceso al **Tablero de Ganadería — Hato El Milagroso** y regreso conservando filtros.
- Menú administrativo: **Presupuesto y unidades**, **Recaudos**, **Producción y lotes**, **Ventas y autoconsumos**, **Hato**, **Catálogos y parámetros**.
- Selector de perfil demostrativo: coordinación administrativa, líder de unidad y coordinación académica. Cambiará la presentación y las acciones disponibles; no constituirá autenticación.
- Selector visible entre **Datos de los archivos** y **Escenario demostrativo**, con almacenamiento independiente y distintivo permanente del escenario activo.

## Comportamiento e indicadores

**Tablero central**

- Filtros por vigencia, fechas, unidad, producto y canal.
- Las seis tarjetas exigidas por la guía, comparación de presupuesto frente a ventas y seguimiento de los seis procesos de autoconsumo.
- IND_001 utilizará recaudos como fuente de venta directa; las ventas registradas servirán para conciliación, sin sumar ambos importes.
- IND_002 permitirá alternar COP y cantidades físicas, conservando cada unidad de medida. Las entregas sin proceso identificado aparecerán como pendientes de vinculación.
- El presupuesto anual permanecerá identificado como tal al filtrar meses. Los importes sin clasificación inequívoca de unidad no se distribuirán automáticamente.

**Tablero técnico**

- IND_003: distribución del hato, tasa productiva, preñez y meta del 75%. El interruptor académico estará desactivado inicialmente: excluirá las cuatro vacas de práctica del denominador operativo y mostrará la población utilizada. La referencia completa de 21/35 permanecerá disponible.
- IND_08: selector de derivados, indicador de rendimiento y tendencia. Su cálculo requerirá vinculación entre leche entregada, lote de transformación, derivados recibidos y factores de conversión.
- IND_12: calcular la rotación hasta la salida total de cada lote y producto. Los lotes abiertos mostrarán antigüedad; los registros sin recepción quedarán pendientes.
- IND_17: separar presupuesto operativo de gasto ejecutado. No presentar $125 millones presupuestados como costo real; tampoco sumar metas de autoconsumo a litros producidos. El escenario demo aportará gastos, producción y asignación de costos académicos para demostrar el cálculo.
- Tabla reproductiva con filtros y semáforos de la guía. Valores faltantes y días abiertos inferiores a 91 tendrán estado neutral, sin inventar umbrales.
- El hato mostrará su fecha de corte y no simulará historial al cambiar filtros de fechas.

**Administración**

- Listados con búsqueda, filtros, detalle y formularios para crear, editar y archivar registros.
- Validaciones de campos obligatorios, fechas, importes, cantidades y referencias entre registros.
- Relaciones explícitas entre proceso y entrega, lote y producto, y transformación y derivados.
- Cambios reflejados inmediatamente en tablas e indicadores. Estados vacíos, errores y confirmaciones tendrán mensajes concretos.

## Implementación y validación

- HTML, CSS y JavaScript modular, sin backend ni compilación obligatoria; ejecutable mediante el servidor local de MAMP.
- Datos iniciales normalizados en JSON, conservando archivo, hoja y fila de procedencia. Los Excel originales permanecerán intactos.
- Persistencia en `localStorage`, exportación e importación de respaldo JSON y restauración del escenario inicial con confirmación.
- Cálculos y acceso a datos separados de los componentes visuales para facilitar una futura API y base de datos centralizada.
- Entregar el prototipo y una guía breve de apertura, navegación, fuentes y limitaciones.

Verificar conciliación con las cifras originales, aislamiento de escenarios, navegación entre tableros, filtros, edición persistente, restauración, exportación e importación. Comprobar también indicadores sin datos, divisiones por cero, unidades incompatibles, lotes abiertos y presentación adaptable.

## Supuestos acordados

- Edición y persistencia local en el navegador.
- Diseño ejecutivo moderno con los colores exigidos por la guía.
- Escenario demostrativo separado para completar los componentes sin evidencia suficiente.
- Vigencia inicial 2026; cada fuente mostrará su cobertura y fecha de corte.
- Sin despliegue público, autenticación real, escritura en Excel ni base de datos compartida en esta etapa.
