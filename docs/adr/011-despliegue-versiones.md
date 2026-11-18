# ADR-011: Despliegue por versiones con enlace atómico y migraciones expand/contract

- Estado: Aceptado
- Fecha: 2026-11-17
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: ROB-004, RDL-002, D-14

## Contexto

El rollback con copia de la base pierde los datos escritos después del despliegue.

## Decisión

Cada versión se publica en `releases/vX.Y.Z` y `current` se cambia de forma atómica. Las migraciones son compatibles hacia atrás (expand/contract); restaurar la base es excepcional. Si el plan no permite enlazar `public_html`, se usa la alternativa B: un `.htaccess` en la raíz que reescribe a `public/`, el mismo mecanismo que usa la I0 en MAMP.

## Alternativas consideradas

- Copiar archivos sobre la versión activa: estados intermedios inconsistentes.

## Consecuencias

- Las migraciones nunca se editan después de aplicadas: el ejecutor rechaza un checksum distinto.

## Evolución

Con un VPS, el despliegue puede pasar a contenedores sin cambiar la estrategia de migraciones.
