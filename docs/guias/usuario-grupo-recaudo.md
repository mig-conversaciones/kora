# Grupo de Recaudo

Tu rol administra los recaudos: los importas del archivo de la Dirección General, clasificas los que no tienen unidad o producto claros y los concilias con las ventas registradas por las unidades. También firmas el acta de la carga inicial.

Antes de empezar, lee [Primeros pasos](primeros-pasos.md).

## Lo que puedes hacer

| Módulo | Qué haces |
|---|---|
| Recaudos | Consultar, importar, clasificar y conciliar |
| Tablero central | Consultar y exportar |
| Producción y lotes, catálogos | Consultar |
| Calidad de datos | Revisar y resolver incidencias |
| Importación de BBDD | Importar el archivo de recaudos de la DG; firmar el acta de la carga inicial |

## Importar los recaudos de la DG

1. En **Importación de BBDD**, carga el libro con la hoja *Ingresos_Centro Agropecuario*.
2. Elige la fuente **Recaudos de la DG** y espera la validación.
3. Revisa la vista previa y aplica el lote.

KORA no guarda en claro la identificación ni el nombre del pagador. Solo conserva lo necesario para detectar duplicados sin exponer los datos personales (Ley 1581 de 2012).

## Clasificar recaudos

Algunos recaudos llegan con una unidad o un producto ambiguos, por ejemplo «Ganadería y Granja» o «Queso».

1. Abre **Recaudos**, pestaña **Por clasificar**.
2. Para cada recaudo, elige la unidad y, si corresponde, el producto.
3. Guarda. El recaudo entra en los tableros con su clasificación.

> Los conceptos genéricos no se homologan solos: un recaudo de «Queso» no se asigna a un queso del catálogo sin tu decisión.

## Conciliar con las ventas

Conciliar es vincular cada recaudo con las ventas que registraron las unidades.

1. En **Recaudos**, abre el recaudo y elige **Conciliar**.
2. KORA propone como candidatas las ventas directas con la misma prefactura o factura, o con la referencia de la transacción igual al cupón de pago. Nunca las busca por nombre ni por fecha. Elige una o varias.
3. Indica el criterio con que las vinculas: prefactura, factura, referencia o manual.
4. Guarda. La conciliación queda en la auditoría con tu nombre y puedes rehacerla si te equivocaste.

La pestaña **Diferencias** es el informe del mes: lista los recaudos conciliados cuyo valor no coincide con el de sus ventas, para revisarlos con la unidad.

## Firmar el acta de la carga inicial

Cuando la coordinación termina la carga inicial y sus cifras de control coinciden, tú o la coordinación firman el acta. Revisa antes las cifras contra los libros fuente, sobre todo los recaudos por unidad. Los pasos están en [Carga inicial del piloto](carga-inicial.md#firmar-el-acta).

## Preguntas frecuentes

- **Importé el mismo archivo dos veces.** KORA lo detecta por su huella y no lo aplica de nuevo.
- **Un recaudo quedó en la unidad equivocada.** Cámbialo en la clasificación: el tablero se actualiza y el cambio queda auditado.
