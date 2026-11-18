# ADR-003: Cola durable en la base de datos con trabajador por cron, sin Redis

- Estado: Aceptado
- Fecha: 2026-11-17
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RF-Q-001 a RF-Q-019, D-12

## Contexto

Hostinger Cloud no ofrece Redis ni procesos persistentes. El trabajo pesado (importaciones, exportaciones, correo) no cabe en el presupuesto síncrono de 1.500 ms.

## Decisión

La tabla `trabajos` (migración 005) es el registro durable. El reclamo es atómico (`SKIP LOCKED` o actualización condicional, ver ADR-002), con reservas que vencen y un trabajador CLI cada minuto, más una ejecución desfasada de 30 s. La interfaz `ConductorCola` permite acelerar con Redis en un VPS sin reemplazar a la base como fuente durable.

## Alternativas consideradas

- Redis o RabbitMQ: no disponibles en el plan.
- Procesar dentro de la solicitud web: excede el presupuesto de tiempo y no sobrevive a fallos.

## Consecuencias

- La espera p95 es de 90 s o menos, aceptable para el negocio.
- Carga adicional sobre la base, que se mide en la PCA (I7).
- Los manejadores deben ser idempotentes.

## Evolución

Si la espera exigida baja de 30 s o la entrada supera la capacidad de vaciado (RNF-PERF-019), migrar a un VPS con trabajador permanente.
