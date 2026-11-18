## Anexo K. Principios de Linus Torvalds aplicados al proyecto

Son principios atribuidos a Linus Torvalds, creador de Linux y Git, adaptados como reglas de trabajo verificables. No reemplazan los estándares del proyecto: los hacen concretos en el día a día.

| Principio | Origen | Aplicación en Producción CAB | Cómo se verifica |
|---|---|---|---|
| «Hablar es barato. Muéstrame el código» (*Talk is cheap. Show me the code*) | Lista de correo del kernel de Linux, 2000 | Ningún requisito se da por cumplido sin código, pruebas y evidencia. El estado por defecto de las pruebas es PENDIENTE DE EJECUCIÓN. | Matriz de trazabilidad (3.19) con evidencia archivada |
| Los buenos programadores se preocupan por las estructuras de datos y sus relaciones | Mensaje público de 2006 | El modelo de datos va primero: claves de unidad, producto, lote y proceso, sin uniones por nombre ni fecha; alcance por centro y unidad en cada tabla | Revisión del esquema (RBD y RDI) antes de las pantallas |
| Buen gusto: eliminar los casos especiales | Charla TED, 2016 | Diseños donde el caso normal cubre los extremos: alcance vacío equivale a una condición SQL falsa; una sola vía de autorización; la misma respuesta para fragmentos y páginas | Revisión de código y complejidad ciclomática en PHPStan |
| «No rompemos el espacio de usuario» (*We do not break userspace*) | Política del kernel de Linux | No se rompen los contratos con los usuarios ni con otros sistemas: URLs, plantillas de importación, contrato de API, oráculos de indicadores. Migraciones *expand/contract* y versionado del contrato. | Pruebas de oráculos (RF-066), pruebas de contrato y despliegue N−1 |
| Commits pequeños y un historial que cuenta por qué | Práctica del desarrollo del kernel y de Git | Commits atómicos con mensajes en español que explican la razón del cambio; un cambio lógico por commit; historial legible | Plantilla de commit y revisión del PR |
| La rama principal siempre funciona | Práctica de integración del kernel | `main` siempre desplegable: la CI y las E2E críticas en verde son condición para integrar | Protección de rama (ROB-002) |
| Revisión implacable con el código y respetuosa con las personas | Lección pública de Torvalds (2018) sobre el trato en la revisión | Las revisiones discuten el código, la seguridad y los datos, nunca a las personas; todo lo generado con IA pasa por revisión humana | Plantilla de PR (RS-018, RDOC-004) |
| La simplicidad antes que la abstracción especulativa | Postura reiterada sobre el diseño del kernel | PHP nativo sin frameworks pesados; ninguna dependencia ni infraestructura (Redis, VPS, microservicios) sin evidencia de necesidad | ADR-005, RNF-PERF-019 |
| «Con suficientes ojos, todos los errores son superficiales» (ley de Linus) | Formulada por Eric S. Raymond en honor a Torvalds | Revisión por pares, demostraciones por iteración con la contraparte y pruebas automatizadas visibles para todo el equipo | Actas de iteración (RGO-005) |
