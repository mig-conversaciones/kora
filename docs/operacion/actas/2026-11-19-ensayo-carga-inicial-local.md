# Acta de ensayo de la carga inicial (RF-094, RDL-011)

Ensayo local de la carga guiada con los **libros reales** del piloto. No es la carga de producción: la base de pruebas se vacía al terminar y la carga no se firmó. El acta registra solo conteos, sumas institucionales y motivos de rechazo, sin ningún valor personal de los libros.

| Campo | Valor |
|---|---|
| Fecha (UTC) | 2026-11-19 |
| Entorno | local · base `kora_pruebas` · MySQL 5.7.24 · PHP 8.3.36 |
| Versión de KORA | 0.9.0 · migración 015_crear_cargas_iniciales |
| Procedimiento | `CargaInicialTest::testEnsayoConLosLibrosRealesYLasOchoFuentes`, con el flujo real: carga del libro, validación en la cola, aplicación y verificación |
| Libros | `BBDD_DATOS ADMON.xlsx`, `BBDD_Produccion de Centro.xlsx` e `INFO_Hato.xlsx` (no se versionan) |
| Vigencia y corte | Vigencia 2026 · corte del hato 2026-11-09 |
| Resultado | **Con diferencias**: 2 de 7 fuentes obligatorias, por 21 filas que no se pueden cargar. No se puede firmar hasta corregirlas en los libros. |

## Cifras de control

| Fuente | Filas archivo | Filas base | Suma archivo | Suma base | Rechazadas | Resultado |
|---|---|---|---|---|---|---|
| Catálogo de productos | 190 | 170 | — | — | 20 | Con diferencias |
| Presupuesto | 9 | 9 | 1.024.000.000 | 1.024.000.000 | 0 | Coinciden |
| Cupos de autoconsumo | 6 | 6 | 59.204.000 | 59.204.000 | 0 | Coinciden |
| Recaudos de la DG | 106 | 106 | 3.931.100 | 3.931.100 | 0 | Coinciden |
| Gastos ejecutados (opcional) | — | — | — | — | — | Omitida |
| Base de producción | 6 | 6 | 261 (cantidad) | 261 (cantidad) | 0 | Coinciden |
| Base de ventas | 146 | 145 | 1.572.000 | 1.552.500 | 1 | Con diferencias |
| Estado reproductivo | 35 | 35 | — | — | 0 | Coinciden |

Los volúmenes coinciden con los de la base fuente en el SRS 2.10: 106 recaudos, 6 recepciones, 146 salidas, 6 procesos, 35 animales y 190 productos. Las sumas por unidad también coinciden en las fuentes sin diferencias.

## Diferencias

| Fuente | Motivo | Filas |
|---|---|---|
| Catálogo de productos | «La medida no se reconoce» (KORA acepta Litros, Kilos, Unidad, Libra y Cubeta) | 19 |
| Catálogo de productos | «El precio mínimo supera el máximo» | 1 |
| Base de ventas | «La unidad de medida no se reconoce», en una fila sin medida ni producto | 1 |

## Acciones correctivas antes de la carga de producción

1. Que la coordinación administrativa y el líder de producción de centro corrijan las 21 filas en los libros actualizados.
   - Para las medidas no reconocidas, hay dos caminos: cambiarlas por una medida admitida o pedir que se agregue la medida al catálogo de medidas con su conversión, lo que exige control de cambios.
2. Que el proveedor repita este ensayo con los libros corregidos: la carga debe quedar **verificada** antes de hacerla en producción.

## Firmas

- Ejecutó: ______________________ · Revisó (contraparte): ______________________
