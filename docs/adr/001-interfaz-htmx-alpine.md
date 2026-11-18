# ADR-001: Interfaz dirigida por el servidor con HTMX y Alpine en lugar de una SPA

- Estado: Aceptado
- Fecha: 2026-11-17
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RI-001, RI-003, RNF-011

## Contexto

El equipo es pequeño, el hosting es compartido (Hostinger Cloud, sin Node en el servidor) y la mayoría de las pantallas son listados, formularios y tableros que dependen de reglas de negocio y de autorización por alcance. Una SPA duplicaría validaciones y permisos en el cliente y aumentaría el peso de JavaScript (RNF-PERF-020).

## Decisión

El servidor PHP renderiza HTML completo o fragmentos según `HX-Request`. HTMX 2 hace los intercambios parciales y Alpine (versión CSP) solo maneja estado visual: menú, pestañas y foco. Ningún dato de negocio vive en Alpine.

## Alternativas consideradas

- SPA con React o Vue y API JSON: duplica reglas y permisos, exige más JavaScript y aumenta la superficie de ataque.
- Páginas completas sin HTMX: más simple, pero con recargas completas y peor experiencia en filtros y tableros.

## Consecuencias

- Una sola fuente de verdad para reglas y permisos: el servidor.
- JavaScript propio pequeño (unos 12 KB minificados en la I0) dentro del presupuesto total de 80 KB gzip.
- Cada fragmento debe funcionar como página completa y como intercambio (`ControladorBase::renderizar`).
- Verificación: pruebas de integración que piden la misma ruta con y sin `HX-Request`.

## Evolución

Si aparecen pantallas con edición sin conexión o colaboración en tiempo real, evaluar islas de componentes ricos sin abandonar el modelo dirigido por el servidor.
