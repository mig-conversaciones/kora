# ADR-014: Nombres en español, equivalencias con la plantilla y nombre de la aplicación

- Estado: Aceptado
- Fecha: 2026-11-17
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RNF-014, D-17

## Contexto

La plantilla nombra archivos y clases en inglés, pero el proyecto exige identificadores en español. El 2026-11-17 la aplicación recibió el nombre **KORA**; el SRS la llama «Producción CAB».

## Decisión

Identificadores, tablas y columnas en español ASCII (sin tildes ni ñ): camelCase, PascalCase y snake_case. Excepciones: cabeceras HTTP, atributos `hx-*`, librerías y contratos como `/health/live`. Equivalencias principales: `BaseController` → `ControladorBase`, `ui-feedback.js` → `retroalimentacion-ui.js`, `config/database.php` → `config/base_datos.php`, `QueueDispatcher` → `DespachadorCola`.

**Nombre:** la aplicación se llama KORA; «Producción CAB» queda como nombre del proyecto institucional y del SRS. Prefijos técnicos: bases `kora` y `kora_pruebas`, usuario `kora_app`, cookie `__Host-kora_sesion` (reemplaza a `__Host-pcab_sesion` del SRS), paquete `mig-conversaciones/kora` y repositorio `github.com/mig-conversaciones/kora`.

## Alternativas consideradas

- Inglés en el código y español en la interfaz: contradice RNF-014.

## Consecuencias

- La regla de lint para identificadores no ASCII se agrega con las reglas propias de PHPStan (I2).

## Evolución

Si se incorporan desarrolladores externos, la tabla de equivalencias del 4.1 del SRS sirve de guía.
