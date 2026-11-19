# Líder de producción de centro

Tu rol sigue la producción de todas las unidades del centro: recepciones de lotes, salidas por venta o autoconsumo, transformaciones, calidad de los datos y el catálogo de productos. Consultas los tableros, el presupuesto y los recaudos.

Antes de empezar, lee [Primeros pasos](primeros-pasos.md).

## Lo que puedes hacer

| Módulo | Qué haces |
|---|---|
| Tablero central y tablero de Ganadería | Consultar y exportar |
| Producción y lotes | Registrar e importar recepciones y salidas; consultar y registrar transformaciones |
| Calidad de datos | Revisar y resolver incidencias |
| Unidades y productos | Mantener el catálogo e importarlo |
| Presupuesto, gastos, procesos y recaudos | Consultar |

## Recepciones y lotes

Cada recepción es un lote que entra a una unidad, por ejemplo `GAN-REC-210126-001`.

1. Abre **Producción y lotes**, pestaña **Recepciones y lotes**.
2. Crea la recepción: unidad, fecha, producto, cantidad y medida. KORA sugiere el número interno con el prefijo de la unidad.
3. El detalle del lote muestra su saldo: lo recibido menos lo vendido o entregado.

## Salidas

Las salidas descuentan del saldo del lote. Tienen dos canales:

- **Venta directa:** la venta a un cliente. El dinero que entra al centro lo muestra el recaudo de la DG; las ventas pagadas por transferencia se muestran en el tablero para conciliar con ese recaudo, pero no se suman a él.
- **Autoconsumo:** entrega a otra unidad o a un destino interno. Si pertenece a un proceso (AUTO_001…), suma a su avance.

KORA no deja vender más que el saldo del lote, ni registrar en un periodo cerrado.

## Importar la producción y las ventas

Con el libro `BBDD_Produccion de Centro` puedes importar el catálogo, la base de producción y la base de ventas desde **Importación de BBDD**. Importa primero el **catálogo**, después la **producción** y por último las **ventas**: cada fuente necesita los productos y los lotes de la anterior.

## Calidad de datos

La **bandeja de calidad** lista lo que impide un cálculo correcto. Por ejemplo:

- una salida sin recepción: vincúlala al lote correcto;
- una entrega de autoconsumo sin proceso: asígnala a su proceso, si lo tiene;
- un caso que ya revisaste y que es correcto: acéptalo con una observación.

Cada resolución recalcula los indicadores y queda en la auditoría.

## Catálogo de productos

En **Unidades y productos** mantienes los productos con su código SGAC, su medida, sus precios y su unidad. La pestaña **Homologación** relaciona los nombres de los archivos con los productos del catálogo.

## Preguntas frecuentes

- **Una salida no aparece en el tablero.** Revisa que no esté archivada y que el periodo no esté filtrado fuera.
- **No puedo archivar una recepción.** Tiene salidas que dependen de ella; archívalas o corrígelas primero.
