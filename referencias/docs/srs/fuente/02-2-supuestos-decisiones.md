## 2.7 Supuestos

Los supuestos resuelven vacíos no críticos. Cada uno indica con quién debe validarse y antes de qué iteración. Si un supuesto resulta falso, se actualizan los requisitos afectados mediante el control de cambios (RGO-006).

| ID | Supuesto | Requisitos afectados | Validar con | Antes de |
|---|---|---|---|---|
| S-01 | El sistema se llama «Producción CAB», el nombre que usó el prototipo. Puede cambiar sin impacto técnico. | RUI-007 | Contraparte | I0 |
| S-02 | El contenedor general es un sidebar y los tableros y tablas siguen la matriz de layout (D-18). | RI-002 | Contraparte | I0 |
| S-03 | Se usa la paleta del prototipo y de la guía. No se usa el logotipo del SENA hasta contar con autorización del área de comunicaciones. | RUI-003, RUI-007 | Comunicaciones SENA | I1 |
| S-04 | Hay siete roles (2.3). Los perfiles del prototipo equivalen a Coordinación administrativa, Líder de unidad y Coordinación académica. | RAC-023, RAC-025 | Contraparte | I2 |
| S-05 | Solo el Grupo de Recaudo registra, importa, clasifica y concilia recaudos. La Coordinación administrativa los consulta (observación 2). | RF-029 | Coordinación administrativa | I2 |
| S-06 | El plan es Hostinger Cloud Startup o Professional, con MariaDB 10.6 o superior (por verificar con `SELECT VERSION()`). | RBD-001, RF-Q-006 | Titular de la cuenta | I0 |
| S-07 | El perfil de carga es el de la tabla 2.6. | RNF-PERF-001 | Contraparte | I6 |
| S-08 | Los objetivos de rendimiento son los de la tabla 2.6. | RNF-PERF-003, RNF-PERF-004 | Contraparte | I6 |
| S-09 | El presupuesto de respuesta síncrona es de 1.500 ms en p95. | RNF-PERF-002 | Equipo técnico | I1 |
| S-10 | Habrá un entorno de pruebas equivalente a producción y autorización para las pruebas de carga. | RNF-PERF-015 | Titular de la cuenta | I6 |
| S-11 | IND_17 se calcula con el gasto ejecutado. El costo presupuestado por litro se muestra solo como referencia (D-20). | RF-077 | Líder de Ganadería, Coordinación administrativa | I5 |
| S-12 | Los factores iniciales de equivalencia en leche son el límite inferior del rango de cada derivado en la guía: queso cuajada 8,0 L/kg, yogurt 0,95 L/L, manjar blanco 3,2 L/kg y cortado 3,8 L/kg. | RF-018 | Taller de lácteos, Coordinación académica | I3 |
| S-13 | El componente académico de cada gasto se registra de forma explícita y sustentada. El prorrateo automático por días-animal del lote de práctica queda como evolución (prioridad Futuro). | RF-025, RF-077 | Coordinación administrativa | I3 |
| S-14 | La categoría «Materiales de formación» se registra y se compara con su línea presupuestal, pero no entra en el numerador de IND_17. | RF-020, RF-077 | Contraparte | I3 |
| S-15 | Equipo: Senior al 50 %, Mid al 100 %, Junior al 100 % y diseño UX con tarifa Mid de forma parcial. Tarifas por defecto de la plantilla. | Capítulo 5 | Proveedor | I0 |
| S-16 | El inicio es el lunes 30 de noviembre de 2026, con pausa colectiva del 21 de diciembre de 2026 al 8 de enero de 2027 y los festivos de Colombia descontados. | Capítulo 5 | Contraparte y proveedor | I0 |
| S-17 | Retención: auditoría 5 años y datos financieros y operativos según la TRD del SENA. Se archivan después de 2 años y no se eliminan dentro del plazo de retención. | RAU-006, RDL-006 | Gestión documental SENA | I7 |
| S-18 | La columna F («PrimerApellido») del archivo de la DG contiene valores con forma de referencia de transacción. Se trata como dato personal y no se almacena hasta confirmar su naturaleza. | RF-034 | Grupo de Recaudo | I5 |
| S-19 | La versión 1.0 opera un solo centro, aunque el modelo de datos es multicentro desde el inicio. | RDI-001 | Contraparte | I0 |
| S-20 | «PoD», término no estándar de la plantilla, se define como Protección desde el Diseño: privacidad y seguridad por diseño y por defecto. | RS-023 | Proveedor (autor de la plantilla) | I0 |
| S-21 | El correo saliente usa el SMTP de Hostinger con una cuenta del dominio; sus límites diarios deben verificarse. | RI-009 | Titular de la cuenta | I1 |
| S-22 | El sistema se publica en un dominio o subdominio definido por el centro, con certificado de Let's Encrypt. | ROB-014 | Contraparte | I0 |
| S-23 | Cada usuario tiene un correo institucional que se usa para invitación, recuperación y notificaciones. | RAC-006, RAC-007 | Contraparte | I2 |
| S-24 | Se elegirá el centro de datos de Hostinger con menor latencia hacia Colombia (por medir desde el centro). | RNF-PERF-003 | Equipo técnico | I0 |
| S-25 | El `localStorage` del prototipo no se migra. La carga inicial se hace con los Excel actualizados. | RDL-011 | Contraparte | I5 |

## 2.8 Inconsistencias detectadas y decisiones

La plantilla pide detectar inconsistencias, contradicciones y variables faltantes antes de generar el SRS. Estas son las encontradas al cruzarla con el entorno real, la guía y el prototipo. Cada decisión que tiene peso arquitectónico se formaliza como ADR en `docs/adr/` (RGO-001).

| ID | Hallazgo (fuente) | Decisión adoptada | Efecto en el SRS |
|---|---|---|---|
| D-01 | La plantilla exige MySQL 8.x, pero los planes Web y Cloud de Hostinger usan **MariaDB** (soporte de Hostinger). | SQL portable entre MariaDB 10.6+ y MySQL 8.0+. Se evitan funciones exclusivas: índices funcionales, colaciones `utf8mb4_0900_*`, `EXPLAIN ANALYZE` y `UUID_TO_BIN`. Se verifica la versión en la iteración 0. | RBD-001, RNF-016, ADR-002 |
| D-02 | El MAMP local trae MySQL 5.7.24, que no soporta SKIP LOCKED, CTE ni funciones de ventana como MariaDB 10.6. | El entorno de desarrollo y las pruebas de integración usan la misma versión de MariaDB que producción. | ROB-008, RNF-013 |
| D-03 | La lista de verificación final de la plantilla menciona «bcrypt», mientras su sección de seguridad prefiere Argon2id. | Argon2id es el algoritmo principal, y está disponible en el PHP 8.3 local. bcrypt solo se usa como alternativa justificada. | RAC-002 |
| D-04 | Una CSP estricta (sin `unsafe-eval`) es incompatible con `hx-on`, los filtros de `hx-trigger` y la versión estándar de Alpine, que usan `Function`/eval. | Se configura `htmx.config.allowEval = false` y se usa Alpine en su versión CSP con componentes `Alpine.data`. Los comportamientos van en `retroalimentacion-ui.js` mediante delegación de eventos y atributos `data-*`. Los fragmentos de la plantilla que usan `hx-on::after-request` se reescriben de forma declarativa. | RS-007, ADR-004 |
| D-05 | Por defecto, HTMX 2 no intercambia las respuestas 4xx y 5xx, de modo que el patrón de error de la plantilla no se aplicaría. | Se configura `htmx.config.responseHandling` para intercambiar 422 en el formulario y 4xx/5xx en `#contenedor-errores`, junto con `HX-Retarget` y `HX-Reswap`. | RUI-015, sección 4.3 |
| D-06 | `hx-swap="morph"` no forma parte de HTMX: requiere la extensión idiomorph. | Se incluye la extensión idiomorph (versión fijada). Si no está disponible, se usa `innerHTML` con View Transitions. | RUI-019 |
| D-07 | El patrón de skeleton de la plantilla (`<template>` con `hx-swap="beforeend"`) mezcla la inserción del esqueleto con el intercambio de la respuesta. | El esqueleto se inserta en `htmx:beforeRequest` y se retira en `htmx:afterSwap` o `htmx:afterRequest`, con `aria-busy`. | RUI-016 |
| D-08 | El estado vacío de la plantilla se basa en una respuesta 204 y un JavaScript en línea. | El servidor devuelve un fragmento 200 con un estado vacío contextual. La respuesta 204 se reserva para acciones sin contenido nuevo. | RUI-014 |
| D-09 | Cerrar automáticamente los toasts a los 3–5 s puede impedir leer mensajes importantes (WCAG 2.2.1 y 4.1.3). | Los toasts de éxito e información duran 5 s y se pausan al pasar el puntero o enfocarlos. Los errores persisten hasta cerrarlos. Hay un historial en el centro de notificaciones. | RUI-010 |
| D-10 | La plantilla cita GDPR y LOPD, que no aplican en Colombia. | Se aplican la Ley 1581 de 2012, el Decreto 1377 de 2013 y la Ley 1712 de 2014. | RS-014, RDL-007 |
| D-11 | La plantilla dice «datos de más de 2 años a `_archive`». Una entidad pública debe retener según las TRD. | Los datos se archivan en tablas `_archivo` de solo lectura y nunca se eliminan dentro del plazo de retención (S-17). | RDL-006 |
| D-12 | Redis es opcional en la plantilla, pero no está disponible en Hostinger Cloud. | La cola es durable solo en MariaDB. La interfaz `ConductorCola` deja preparada la aceleración con Redis si se migra a un VPS. | RF-Q-018, ADR-003 |
| D-13 | La «auditoría de accesos a `.env`» requiere auditoría del sistema operativo, es decir, root o VPS. | En Cloud se aplica un control compensatorio: `.env` fuera de `public_html` con permisos 600, verificación periódica de integridad y alerta. | RS-012, RS-022 |
| D-14 | El rollback con «snapshot de BD» de la plantilla pierde los datos escritos después del despliegue. | Las migraciones siguen la estrategia expand/contract y son compatibles hacia atrás. La restauración de la base es excepcional, con aceptación explícita de la pérdida. | ROB-004, RDL-002 |
| D-15 | Rotar las claves de cifrado cada 90 días obliga a recifrar los datos. | Se usa un llavero versionado (`v1`, `v2`…) y un trabajo de recifrado. | RS-012, RS-013 |
| D-16 | Las pruebas de carga sobre un hosting administrado pueden afectar a terceros o violar sus términos. | Se requiere autorización, se aplican límites de tráfico, condiciones de parada y aviso al proveedor si corresponde. | RNF-PERF-015 |
| D-17 | La plantilla nombra los archivos en inglés (QueueDispatcher, cron_worker, BaseController, JobController, ui-feedback) y a la vez exige nombres en español. | Se usan nombres en español con una tabla de equivalencias (sección 4.1). Por ejemplo: `QueueDispatcher.php` es `DespachadorCola.php` y `ui-feedback.js` es `retroalimentacion-ui.js`. | ADR-014 |
| D-18 | La plantilla pide elegir una sola opción de layout, mientras su matriz recomienda combinaciones. | Se combinan la Opción 2 como contenedor, la Opción 1 en los tableros y la Opción 8 en la administración. Es coherente con el prototipo validado. | RI-002 |
| D-19 | El escenario demostrativo del prototipo mezclaba datos sintéticos con la aplicación, y la plantilla prohíbe los datos de ejemplo en producción. | Los datos sintéticos van a un entorno de capacitación separado, con un generador reproducible. | RF-099, ADR-013 |
| D-20 | La guía calcula IND_17 con los rubros presupuestados (45 M + 80 M) / litros; el prototipo, con el gasto ejecutado. | Se usa el gasto ejecutado (el costo real), y el costo presupuestado por litro se muestra como referencia identificada (S-11). | RF-077, ADR-012 |
| D-21 | El respaldo y la importación JSON del prototipo dependían del navegador. | Se reemplazan por la importación Excel asistida, las exportaciones y los respaldos del servidor. | RF-083, RDL-003 |
| D-22 | La plantilla permite cookies `SameSite=Strict` o `Lax`. Strict rompe la navegación desde los enlaces de los correos de notificación. | Se usa `Lax`, junto con un token CSRF sincronizador y la verificación de Origin y Sec-Fetch-Site. | RAC-016, RS-005 |
| D-23 | La plantilla usa `X-Idempotency-Key`; el borrador IETF usa `Idempotency-Key`. | Se aceptan ambas cabeceras; `X-Idempotency-Key` es la canónica en este proyecto. | RNF-010 |
| D-24 | La UI optimista es arriesgada en registros financieros e inventario. | Se limita a preferencias reversibles. | RUI-020 |
| D-25 | Schemathesis solo prueba APIs descritas con OpenAPI, no fragmentos HTML. | Schemathesis se aplica a los endpoints JSON (salud, trabajos, contrato). El contrato de los fragmentos HTMX se prueba con PHPUnit. | RDOC-008, sección 3.1 |
| D-26 | La plantilla pide manejar webhooks de pago en mantenimiento. En la v1 no hay webhooks: el recaudo llega por archivo de la DG. | NO APLICA en la v1. La política queda definida para la futura API de integración. | RF-MAN-015 |
| D-27 | El runbook «IA caída» y las métricas de tokens de IA no aplican: la v1 no usa servicios de IA. | NO APLICA, con justificación documentada; se conserva la plantilla para el futuro. | RGO-004 |
| D-28 | El endpoint `/health/ready` debe estar «restringido a infraestructura», pero Hostinger Cloud no tiene red privada. | Se protege con un token de monitoreo en una cabecera, comparado en tiempo constante, o con una sesión de superadministrador. | ROB-011 |
| D-29 | El cortacircuitos necesita un estado compartido, y PHP no conserva estado entre procesos. | El estado se guarda en la tabla `estado_circuitos`. | RNF-008 |
| D-30 | Tailwind por CDN no es apto para producción ni para la CSP. | Se compila con la CLI independiente en la integración continua, y el CSS se despliega con huella en el nombre. | RNF-019 |
| D-31 | Las librerías de gráficos en JavaScript aumentan el peso de la página y complican la CSP. | Los gráficos son SVG renderizados en el servidor, como en el prototipo, con una alternativa tabular accesible. | RUI-017, ADR-009 |
| D-32 | La revocación de sesiones y la opción «cerrar todas» requieren un registro de sesiones. | Las sesiones se guardan en MariaDB con el ID cifrado por hash (manejador propio). | RAC-017, ADR-006 |
| D-33 | En un plan administrado puede no haber privilegios para crear disparadores, por ejemplo si el registro binario está activo. | La auditoría es inmutable mediante una cadena de hash y una exportación firmada. Los disparadores se agregan si el plan los permite. | RAU-004, ADR-008 |
| D-34 | Los mensajes de toast en cabeceras HTTP pueden incluir tildes, que no son seguras en una cabecera. | Se usa `HX-Trigger` con JSON escapado en ASCII (`ó`). `X-Toast-Type` solo indica el tipo. | RI-004 |
| D-35 | Leer un XLSX completo en memoria puede superar los límites del plan. | Se usa OpenSpout en modo *streaming*, procesando por bloques. | RF-086, ADR-005 |

## 2.9 Observaciones de la revisión del mockup

En noviembre de 2026 la contraparte revisó el prototipo con los perfiles de Coordinación administrativa y Líder de unidad e hizo seis observaciones con capturas. Cada observación genera requisitos explícitos y se cubre con pruebas de extremo a extremo.

| Obs. | Perfil | Observación (texto de la revisión) | Pantalla y causa en el prototipo | Requisitos que la atienden |
|---|---|---|---|---|
| 1 | Coordinación administrativa | «Incluir en la lista desplegable: Materiales de Formación.» | En el formulario «Crear gasto», el campo Categoría solo ofrecía Mantenimiento y Alimentación y vacunas, aunque el presupuesto tiene la línea «GASTOS: Materiales Formación». | RF-020, RF-025, RF-026 y RF-077 (exclusión de IND_17) |
| 2 | Coordinación administrativa | «No debería registrar recaudos.» | La pantalla Recaudos mostraba «Nuevo registro», editar y archivar, porque el perfil administrativo podía editar todos los módulos. | RF-029, RF-033, RAC-023 y RAC-025 |
| 3 | Coordinación administrativa | «¿Por acá puedo cargar las BBDD actualizadas para mi prueba piloto?» | «Fuentes y respaldos» solo importaba respaldos JSON del mismo navegador; no podía cargar los Excel actualizados. | RF-083 a RF-094 y RF-Q-011 |
| 4 | Líder de unidad | «El tablero central solo debería mostrar la información específica de la unidad.» | El tablero central mostraba todas las unidades del centro al líder de Ganadería. | RF-072, RDI-003 y RDI-006 |
| 5 | Líder de unidad | «Pasa lo mismo con respecto a estas pestañas (solo debe verse lo correspondiente a cada unidad).» | Las pestañas Asignaciones, Unidades y Gastos mostraban los registros de todo el centro. | RF-002, RF-024, RF-025 y RDI-006 |
| 6 | Líder de unidad | «En la transformación, en lote derivado recibido no puedo seleccionar nada (queso, manjar blanco, cortado o yogurt).» | El desplegable estaba vacío por dos causas: en la base fuente ningún producto tenía configurado el factor de equivalencia en leche, y el flujo exigía que la recepción del derivado existiera antes. | RF-018, RF-047, RF-048 y RF-050 |

> **Decisión D-36.** Las seis observaciones son criterios de aceptación de la prueba piloto. Se verifican en la demostración de las iteraciones 3, 4 y 5 con los mismos perfiles que las reportaron. La aceptación queda registrada en el acta de revisión de cada iteración (RGO-005).

## 2.10 Reglas y oráculos heredados del prototipo

Las cifras de la tabla fueron verificadas por las pruebas del prototipo contra los Excel originales. El sistema debe reproducirlas exactamente al cargar las mismas fuentes; son la prueba de no regresión de las fórmulas (RF-066).

| Oráculo | Valor esperado | Fuente |
|---|---|---|
| Asignación presupuestal del centro, vigencia 2026 | 1.024.000.000 COP en 9 asignaciones | Ingresos - Gastos |
| Asignación de Ganadería | 185.000.000 COP: mantenimiento 45 M, alimentación 80 M y materiales de formación 60 M | Ingresos - Gastos |
| Recaudo de venta directa de Ganadería | 3.265.000 COP en 61 pagos | Ingresos_Centro Agropecuario |
| Recaudo de Ganadería del producto Leche (homologado) | 3.045.000 COP | Ingresos_Centro Agropecuario |
| Autoconsumo de Ganadería (salidas) | 176.000 COP; total de ventas de Ganadería: 3.441.000 COP | Base Ventas |
| Volumen de la base fuente | 106 recaudos, 6 recepciones, 146 salidas, 6 procesos, 35 animales y 190 productos | Tres libros Excel |
| Calidad de la base fuente | 145 salidas sin recepción, 17 autoconsumos sin proceso y 1 recaudo sin unidad inequívoca | Tres libros Excel |
| IND_003 sin el lote de práctica | 21 / 31 = 67,7 % | ESTADO REP |
| IND_003 con el lote de práctica | 21 / 35 = 60,0 %; 10 horras y 4 de práctica | ESTADO REP |
| Límites del semáforo IEP | 365 verde, 366 ámbar, 401 rojo | Guía 3.2 |
| Límites del semáforo de días abiertos | 90 neutral, 91 y 120 verdes, 121 y 135 ámbar, 136 rojo | Guía 3.2 y prototipo |
| Escenario sintético de capacitación | AUTO_001: 3.200 L entregados (80 %); IND_08 global de 97 %; IND_17 de 1.337,5 COP/L (16.000 L, gasto de 24.400.000 COP y componente académico de 3.000.000 COP); meta de 1.650 COP/L | Generador de capacitación (RF-100) |

**Reglas de negocio validadas que se conservan sin cambios:**

- El presupuesto es anual y no se prorratea al filtrar por meses.
- Las ventas por transferencia se muestran solo para conciliar y no se suman al recaudo.
- No se suman cantidades con medidas distintas.
- Las entregas sin proceso cuentan en IND_001 pero no en las metas de IND_002.
- Los conceptos genéricos («Queso») no se homologan automáticamente.
- Los lotes abiertos muestran su antigüedad y no entran en el promedio de IND_12.
- El hato es un corte fechado y no se reconstruye su historia.
- Las divisiones por cero muestran «—» en lugar de valores infinitos.

## 2.11 Fuera de alcance de la versión 1.0

- Implementación del API de integración con el software de ventas. Se especifica el contrato y se prueba contra un simulador (RF-095, RF-098).
- Ejecución presupuestal oficial (CDP/RP), contabilidad, tesorería y facturación electrónica.
- Tableros técnicos de las unidades distintas a Ganadería (RF-079).
- Registro histórico de eventos reproductivos individuales (partos, servicios y palpaciones como eventos). La v1 trabaja con cortes fechados.
- Devoluciones de producto como movimiento (tipo DEV) (RF-042).
- Inicio de sesión único con Microsoft Entra ID y llaves de acceso WebAuthn, documentados como evolución (RAC-015).
- Modo mantenimiento por unidad o por centro (RF-MAN-004).
- Aplicación móvil nativa y modo sin conexión.
