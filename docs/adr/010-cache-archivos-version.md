# ADR-010: Caché de aplicación en archivos con versión de datos por centro

- Estado: Aceptado
- Fecha: 2026-11-17
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RNF-PERF-012, RDI-008

## Contexto

Los tableros agregan muchos registros y el plan no ofrece Redis ni Memcached.

## Decisión

Caché en `almacenamiento/cache` con claves que incluyen la versión de datos del centro (`versiones_datos`, migración 006) y la huella del alcance. Cada escritura relevante incrementa la versión, lo que invalida sin borrar.

## Alternativas consideradas

- APCu: no se comparte entre procesos en todos los planes.

## Consecuencias

- Limpieza por tamaño máximo (`CACHE_TAMANO_MAXIMO_MB`).
- Se implementa en la I1.

## Evolución

En un VPS, Redis puede reemplazar el almacenamiento de la caché sin cambiar las claves.
