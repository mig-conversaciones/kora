# Runbook: tableros, indicadores y exportaciones

Se aplica a la plataforma de la I6. Requisitos: RF-062 a RF-082. Decisión: [ADR-020](../adr/020-tableros-e-indicadores.md).

## 1. Rutas y permisos

| Ruta | Permiso | Qué muestra |
|---|---|---|
| `/tableros/central` | `tablero_central.consultar` | KPI, IND_001, IND_002, estado de la información y conciliación |
| `/tableros/central/procesos?modo=unidades` | ídem | Solo la sección IND_002 (fragmento) |
| `/tableros/unidad/GAN` | `tablero_unidad.consultar` | Tablero técnico: hato, IND_08, IND_12 e IND_17 |
| `/tableros/unidad/GAN/hato?practica=1&salud=alertas` | ídem | Solo la sección del hato (fragmento) |
| `POST /exportaciones` | `exportaciones.solicitar` y el permiso del tablero | 202 con el seguimiento del trabajo |
| `/exportaciones/{ulid}/descargar` | ídem; solo el autor, durante 24 h | El archivo, con `Cache-Control: no-store` |

- **Líder de unidad:** sus tableros se calculan solo con sus unidades. Pedir otra unidad en la URL muestra un aviso y no trae datos de esa unidad.
- **Otras unidades:** el tablero técnico de una unidad distinta de Ganadería muestra «Tablero técnico en preparación», salvo con `FUNCIONALIDAD_TABLERO_OTRAS_UNIDADES=true`.

## 2. Cómo leer las cifras

- **Asignación:** anual; no cambia al filtrar por fechas.
- **Venta directa:** recaudos de tipo «Producción» del periodo.
- **Autoconsumo:** salidas por autoconsumo del periodo.
- **Ventas por transferencia:** salidas de venta directa. Aparecen solo en el aviso de conciliación y no se suman.
- **Recaudo sin unidad inequívoca:** cuenta en el total del centro y no se reparte entre unidades.
- **IND_002:** solo cuentan las entregas con el proceso, su unidad de origen y su medida. Las entregas sin proceso aparecen como pendientes en la bandeja de calidad.
- **Hato:** es el corte vigente, independiente de las fechas. El interruptor de práctica está apagado por defecto para el líder y encendido para la Coordinación académica.
- **IND_08:** requiere transformaciones con la salida de leche y el lote del derivado vinculados, y un factor mayor que 0 en el derivado. Sin eso, muestra «Pendiente de trazabilidad».
- **IND_17:** gasto de Mantenimiento y Alimentación, menos el componente académico, entre los litros de leche recibidos. Con la vigencia completa también muestra el costo presupuestado como referencia.

## 3. Caché

- La caché está en `almacenamiento/cache/`, con un TTL de 10 minutos ± 10 %.
- Una escritura de la aplicación sube la versión de datos del centro y la siguiente consulta recalcula.
- **Cambio directo en la base** (soporte):

  ```sql
  UPDATE versiones_datos SET version = version + 1 WHERE centro_id = <id>;
  ```

- **Cambio de una fórmula:** sube `ServicioTableros::CALCULO` en el mismo despliegue.
- **Limpieza:** la hace el trabajo `mantenimiento.limpieza` diario, con un máximo de 200 MB.

## 4. Exportaciones

| Situación | Qué hacer |
|---|---|
| «No se pudo generar el archivo: el usuario ya no tiene permiso» | Los permisos se releen al ejecutar. Lo pide alguien con el rol vigente |
| El enlace responde 404 | Pasaron 24 h, otra persona usó el enlace o el autor perdió el permiso. Pedir una nueva exportación |
| El trabajo sigue «En cola» | Revisar `/sistema/colas` (cola `reportes`) y el cron del trabajador |
| El CSV se abre con un separador equivocado | El CSV usa coma y punto decimal (RFC 4180). En Excel: Datos → Desde texto/CSV, o usar el XLSX |

Los archivos viven en `almacenamiento/exportaciones/`, fuera de `public/`, con un nombre aleatorio. La limpieza horaria borra los vencidos y olvida su ruta.

## 5. Verificación con los libros reales

`OraculosIndicadoresTest::testLibrosRealesReproducenLosOraculosDe210` importa los tres libros de `referencias/` con el flujo real y compara las cifras con 2.10. Solo corre donde estén los libros, porque no se versionan.

Las dos diferencias conocidas con el SRS están en el ADR-020:

- los recaudos terminan el 19/04/2026, no el 24/04;
- una fila de ventas sin medida ni producto se rechaza.
