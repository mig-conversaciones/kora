# Carga inicial del piloto

La producción de KORA empieza con los libros de Excel **actualizados** del piloto de Ganadería. Los datos del prototipo (lo guardado en el navegador y sus respaldos JSON) no se importan, porque su formato no es confiable para producción (RDL-011).

La carga la conduce la **coordinación administrativa** o el administrador funcional. El acta la firma la **coordinación administrativa** o el **Grupo de Recaudo**. Para importar cada fuente se necesita también el permiso de esa fuente: el catálogo, la producción y las ventas los importa el líder de producción de centro, y el hato la coordinación académica o el líder de Ganadería.

## Antes de empezar

- Ten a mano los tres libros actualizados: `BBDD_DATOS ADMON`, `BBDD_Produccion de Centro` e `INFO_Hato`.
- Acuerda la **vigencia** del presupuesto y la **fecha de corte** del hato.
- Revisa que no haya registros de prueba en la base de producción: KORA los marcará como diferencia.

## Iniciar

1. Abre **Importación de BBDD**, pestaña **Carga inicial**.
2. Escribe la vigencia y elige **Iniciar la carga**. KORA toma una copia cifrada de la base antes de empezar.

Solo puede haber una carga abierta por centro. Una carga firmada no se puede repetir: la producción empezó con ella.

## Importar las fuentes en orden

La pantalla de la carga muestra los pasos en este orden. Cada fuente se importa con el flujo normal de **Importación de BBDD**: cargar el libro, elegir la fuente, revisar la vista previa y aplicar.

| Paso | Fuente | De dónde sale |
|---|---|---|
| 1 | Catálogo de productos | `BBDD_Produccion de Centro` (catálogo) |
| 2 | Presupuesto | `BBDD_DATOS ADMON` (*Ingresos - Gastos*) |
| 3 | Cupos de autoconsumo | `BBDD_DATOS ADMON` (*Autoconsumo*) |
| 4 | Recaudos de la DG | `BBDD_DATOS ADMON` (*Ingresos_Centro Agropecuario*) |
| 5 | Gastos ejecutados (opcional) | Plantilla de gastos, si la coordinación la tiene |
| 6 | Base de producción | `BBDD_Produccion de Centro` (*Base Produccion Centro*) |
| 7 | Base de ventas | `BBDD_Produccion de Centro` (*Base Ventas*) |
| 8 | Estado reproductivo | `INFO_Hato` (*ESTADO REP*), con la fecha de corte |

> Importa y **aplica** el catálogo antes de validar la producción y las ventas: estas buscan sus productos en el catálogo ya cargado.

## Verificar las cifras de control

Cuando todas las fuentes obligatorias estén aplicadas, elige **Verificar las cifras de control**. Para cada fuente, KORA compara:

- las **filas** y la **suma** calculadas al leer el archivo, incluidas las filas con error;
- con lo que quedó **vigente en la base** de ese lote, recalculado en ese momento;
- en total y **por unidad**.

También marca como diferencia:

- una fuente obligatoria sin cargar;
- un lote más reciente que quedó sin aplicar;
- un registro de la base que no viene de la carga, como un dato de prueba o uno escrito a mano.

Si hay diferencias, la carga queda **con diferencias** y no se puede firmar. El acta dice qué fila del archivo falló y por qué. Corrige el libro, vuelve a cargar esa fuente y verifica de nuevo: para cada fuente vale el último lote aplicado.

## Firmar el acta

1. Revisa el acta (**Ver el acta**) contra los libros fuente: conteos y sumas por fuente y por unidad.
2. Marca la declaración de que revisaste las cifras, agrega observaciones si las hay y elige **Firmar el acta**.
3. KORA pide tu contraseña de nuevo si pasaron más de 5 minutos.

Antes de firmar, KORA **verifica otra vez**. Si algo cambió desde la verificación, por ejemplo un lote revertido o un registro editado, no firma y muestra el acta actualizada.

El acta firmada muestra quién la firmó, cuándo y la **huella** (SHA-256) del resultado. Se imprime o se guarda en PDF desde el navegador y forma parte del acta de salida a producción.

## Anular una carga

Mientras no esté firmada, una carga se puede anular con un motivo de al menos 10 caracteres. Anularla no revierte los lotes aplicados: revísalos en **Importación de BBDD** antes de empezar otra.
