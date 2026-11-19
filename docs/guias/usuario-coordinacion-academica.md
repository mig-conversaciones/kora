# Coordinación académica

Tu rol cuida el uso pedagógico de la unidad de Ganadería: la disponibilidad de animales para la práctica, la transformación láctea y los factores de equivalencia de los derivados. Consultas los tableros, el presupuesto, los gastos y la producción.

Antes de empezar, lee [Primeros pasos](primeros-pasos.md).

## Lo que puedes hacer

| Módulo | Qué haces |
|---|---|
| Tableros | Consultar y exportar |
| Hato | Consultar, importar el estado reproductivo y marcar los animales del lote de práctica académica |
| Transformación láctea | Registrar transformaciones |
| Catálogo | Configurar los factores de equivalencia en leche de los derivados |
| Presupuesto, gastos, procesos y producción | Consultar |

## Lote de práctica académica

En el **Hato**, cada animal pertenece a un lote: producción, horra o práctica académica. Puedes mover animales al lote de práctica y sacarlos de él. En el tablero técnico, el interruptor **Incluir el lote de práctica** cambia el denominador de IND_003:

- sin práctica: la tasa se calcula sobre las hembras productivas y horras;
- con práctica: se calcula sobre todas las hembras adultas.

Ejemplo de la base del piloto: 21 de 31 (67,7 %) sin práctica y 21 de 35 (60,0 %) con práctica.

## Factores de equivalencia en leche

Para que un derivado (queso, manjar blanco, cortado o yogur) aparezca en las transformaciones y cuente en IND_08, necesita su **factor de equivalencia en leche**: cuántos litros de leche representa una unidad del derivado. Se configura en **Unidades y productos**, en el producto.

## Transformación láctea

1. En **Producción y lotes**, pestaña **Transformación láctea**, registra la transformación.
2. Elige el lote de leche entregado por autoconsumo y el derivado obtenido, con su cantidad.
3. IND_08 compara la leche usada con lo producido.

## Importar el estado reproductivo

Desde **Importación de BBDD**, carga `INFO_Hato` (hoja *ESTADO REP*), elige la unidad pecuaria y la **fecha de corte**, valida y aplica. Los semáforos de intervalo entre partos y de días abiertos se recalculan con el nuevo corte.
