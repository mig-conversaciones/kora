# ADR-004: CSP estricta: allowEval=false en htmx, Alpine CSP y retroalimentación centralizada

- Estado: Aceptado
- Fecha: 2026-11-17
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RS-007, RUI-009, D-04 a D-08

## Contexto

Una CSP sin `unsafe-inline` ni `unsafe-eval` es incompatible con `hx-on`, los filtros `[...]` de `hx-trigger`, `hx-vals="js:"` y la versión estándar de Alpine, que evalúan código.

## Decisión

Cada respuesta lleva un nonce nuevo (`CabecerasSeguridad`). htmx se configura con `allowEval:false` y `allowScriptTags:false` en `<meta name="htmx-config">`. Alpine se usa en su versión CSP y los componentes se registran con `Alpine.data`. Los comportamientos se declaran con atributos `data-*` que interpreta `retroalimentacion-ui.js` (toasts, confirmación, esqueletos, estados vacíos y errores). No hay estilos ni scripts en línea.

## Alternativas consideradas

- Permitir `unsafe-eval`: anula la principal defensa contra XSS.
- Prescindir de Alpine: más JavaScript propio para el estado visual.

## Consecuencias

- Una prueba de integración verifica que no haya scripts sin nonce, estilos en línea ni `hx-on`.
- Las clases que se arman dinámicamente en las plantillas deben declararse fuera de la capa purgable de Tailwind (lección de la I0 con las muestras de color).
- ESLint prohíbe `alert`, `confirm`, `prompt`, `console` y `eval`.
- Verificado en la I0: Alpine CSP inicializa sus componentes con la política activa.

## Evolución

Si una librería necesaria exige evaluación de código, se aísla en una página propia con una CSP específica y un ADR nuevo.
