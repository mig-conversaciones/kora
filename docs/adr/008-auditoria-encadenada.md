# ADR-008: Auditoría encadenada por hash y exportación firmada

- Estado: Aceptado
- Fecha: 2026-11-17
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RAU-001 a RAU-007, D-33

## Contexto

En un plan administrado puede no haber privilegios para crear disparadores (por ejemplo, con el registro binario activo).

## Decisión

La auditoría funcional es de solo inserción (`eventos_auditoria`, migración 004). Un sellado diferido (máximo 3 min) asigna secuencia y hash encadenado (`cadena_auditoria`). Los disparadores de inmutabilidad (migración 013) se agregan solo si el plan los permite (ítem 6 de la verificación de Hostinger).

## Alternativas consideradas

- Solo disparadores: pueden no estar disponibles.
- Registro en archivos: no consultable ni verificable.

## Consecuencias

- La integridad se verifica con un trabajo programado y con la exportación firmada.

## Evolución

Si el plan permite disparadores, se aplica la migración 013 como defensa adicional.
