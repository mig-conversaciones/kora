# Runbook: importaciones, carga inicial del piloto y recaudos

Se aplica a la plataforma de la I5. Requisitos: RF-030 a RF-034 y RF-083 a RF-097. Decisión: [ADR-019](../adr/019-importacion-asistida.md).

## 1. Requisitos

- `composer install` instala `openspout/openspout` (lectura en streaming de XLSX). Extensiones de PHP: `zip`, `xmlreader` y `dom`.
- La cola debe estar corriendo. En local, `php cli/cron_local.php`; en producción, el cron de hPanel (Anexo D). Validar, aplicar y revertir son trabajos de la cola `importacion`.
- Los libros se guardan en `almacenamiento/importaciones/AAAA/MM/` con un nombre aleatorio, y se retienen 1 año (RF-093).

## 2. Quién importa qué (RF-092)

| Fuente | Hoja del libro original | Rol |
|---|---|---|
| Catálogo de productos | `CatalogoProductos_CAB` | Líder de producción de centro, Administrador |
| Presupuesto | `Ingresos - Gastos` | Coordinación administrativa |
| Cupos de autoconsumo | `Autoconsumo_AAAA` | Coordinación administrativa |
| Gastos ejecutados | Plantilla `Gastos` | Coordinación administrativa |
| Recaudos de la DG | `Ingresos_Centro…` | Grupo de Recaudo |
| Base de producción | `Base Produccion Centro` | Líder de producción de centro; líder de unidad, solo sus unidades |
| Base de ventas | `Base Ventas` | Ídem |
| Estado reproductivo | `ESTADO REP` | Líder de la unidad pecuaria, Coordinación académica |

Una fila de otra unidad en el archivo de un líder es un error de fila: «Unidad fuera de su alcance».

## 3. Carga inicial del piloto (RF-094)

Orden recomendado:

1. **Catálogo** (`BBDD_Produccion de Centro.xlsx`, hoja CatalogoProductos_CAB). Los 20 productos con medidas de servicio (hora, hectárea, metro cuadrado, día, mililitro, análisis de ensayo) salen como error: se aplican solo las filas válidas.
2. **Presupuesto y cupos** (`BBDD_DATOS ADMON.xlsx`), con la vigencia que se elige al cargar.
3. **Base de producción** y después **base de ventas**, para que las ventas encuentren su lote.
4. **Recaudos de la DG** (`BBDD_DATOS ADMON.xlsx`, hoja Ingresos_Centro…).
5. **Estado reproductivo** (`INFO_Hato.xlsx`): elige la unidad del hato y la **fecha del corte** (el archivo trae dos fechas distintas en «Fecha actual»).
6. Revisa el **acta de carga** (`/importaciones/acta`). Cada lote aplicado debe decir «Verificada». «Con diferencias» suele deberse a filas con error que no se aplicaron: descarga el reporte, corrige y vuelve a cargar.

Cifras de control esperadas con los archivos fuente: 106 recaudos de producción, 61 de Ganadería por 3.265.000 COP; 35 hembras (21 en producción, 10 horras, 4 de práctica); 17 autoconsumos sin proceso.

## 4. Flujo de un lote

1. **Cargar el libro** en `/importaciones`. Se rechazan:
   - los `.xlsm` y `.xls`;
   - los archivos de más de 10 MB;
   - los que no tienen firma de Excel o llevan macros;
   - los que descomprimidos pasan de 100 MB o 500 partes;
   - las hojas de más de 20.000 filas.
2. **Elegir las fuentes.** Solo se pueden marcar las que el rol importa. Si el mismo archivo ya se aplicó para una fuente, se avisa y se enlaza ese lote.
3. **Vista previa.** Muestra filas nuevas, actualizadas, sin cambios y con error, la muestra de cambios (valor actual y nuevo) y los errores y advertencias por fila. El **reporte de errores** se descarga en XLSX.
4. **Aplicar.** Con errores, hay que confirmar «aplicar solo las válidas». La aplicación corre en segundo plano y avisa con una notificación. Después se recalcula la bandeja de calidad.
5. **Revertir.** Exige un motivo y la confirmación de la identidad. Se bloquea si hay registros creados después que dependen del lote, por ejemplo salidas manuales sobre recepciones importadas; la pantalla los lista.

| Situación | Qué hacer |
|---|---|
| «No se reconoció ninguna fuente en el libro» | Usar el libro original o descargar la plantilla desde `/importaciones` |
| «Faltan columnas obligatorias» | Revisar que la fila de encabezados conserve los nombres de las columnas |
| Lote «Fallido: el usuario ya no tiene permiso» | Los permisos se releen al empezar el trabajo (RAC-024); lo carga otra persona con el rol |
| «Este mismo archivo ya se aplicó» | No se vuelve a aplicar. Para corregir: revertir el lote o cargar un archivo actualizado, que solo inserta lo nuevo |

## 5. Recaudos

- **Clasificar** (`/recaudos/clasificar`, Grupo de Recaudo):
  - Las sugerencias se ven, pero solo se aplican al confirmar: producto homologado, unidad declarada en el archivo o venta con la misma prefactura.
  - Se pueden elegir varios recaudos y asignarles la unidad de una vez; queda un evento de auditoría por recaudo.
  - Un recaudo **ambiguo** («Ganadería y Granja») se asigna entero a la unidad que se decida. No se reparte.
- **Conciliar** (desde la consulta, «Conciliar»):
  - Vincula el recaudo con una o varias ventas directas por prefactura, factura o referencia.
  - Si los valores difieren, queda «con diferencia» y aparece en `/recaudos/diferencias`.
- **Alta manual** (RF-033):
  - Solo existe con `FUNCIONALIDAD_RECAUDOS_ALTA_MANUAL=true` en el `.env`; si no, la ruta responde 404.
  - Exige motivo y referencia del soporte. Queda marcada como manual y auditada.
- **Datos personales** (RF-034): los nombres del pagador nunca se leen. La identificación queda solo como huella (HMAC) y sus 4 últimos dígitos. En ventas, una identificación en «ID Cliente» queda como «Cliente ****1234».

## 6. Integración con el software de ventas

El contrato está en [docs/api/integracion-ventas.yaml](../api/integracion-ventas.yaml) (OpenAPI 3.1). Mientras no exista el API (RF-098, fuera de la v1), el software de ventas entrega un XLSX con esas columnas en hojas llamadas `Recepciones` y `Salidas`, que se importan como las fuentes 5 y 6. Una salida sin `id_externo` se rechaza (RF-097).
