# ADR-013: El escenario demostrativo se convierte en entorno de capacitación

- Estado: Aceptado
- Fecha: 2026-11-17
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RF-099, D-19

## Contexto

El prototipo mezclaba datos sintéticos con los reales, y la plantilla prohíbe datos de ejemplo en producción.

## Decisión

Los datos sintéticos viven en un entorno de capacitación separado, con un generador reproducible. Producción nunca contiene datos de ejemplo. La plantilla base muestra una franja de entorno cuando `APP_ENTORNO` no es `produccion`.

## Alternativas consideradas

- Bandera por registro: riesgo de mezclar cifras.

## Consecuencias

- La franja de entorno ya está implementada en la I0.

## Evolución

Si capacitación necesita datos reales anonimizados, se define un proceso de anonimización.
