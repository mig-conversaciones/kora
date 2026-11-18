# 5. Planificación de iteraciones y recursos humanos

Supuestos de planificación (S-15 y S-16):

- **Iteraciones** de dos semanas con demostración a la contraparte (RGO-005). La iteración 0 dura tres semanas.
- **Inicio** el lunes 30 de noviembre de 2026.
- **Pausa colectiva** del 21 de diciembre de 2026 al 8 de enero de 2027.
- **Festivos de Colombia descontados:** 8-dic-2026; 11-ene, 22-mar, 25 y 26-mar y 10-may-2027.
- **Tarifas** por defecto de la plantilla: Senior 50 USD/h, Mid 30 USD/h y Junior 18 USD/h. El diseño UX/UI se presupuesta con la tarifa Mid.
- **Cálculo** con un script sobre los días hábiles reales, a 8 h por día y según la dedicación de cada rol.

## 5.1 Cronograma detallado

| Iteración | Fechas | Días hábiles | Objetivo | Entregables principales | Requisitos clave | Hito |
|---|---|---|---|---|---|---|
| I0 Fundaciones | 30-nov-2026 a 18-dic-2026 | 14 | Base técnica, sistema de diseño y supuestos validados | Repositorio, CI, entornos local (MariaDB) y staging; verificación del plan (4.8, ítems 1 a 9); esqueleto MVC; migraciones 001 a 006; sistema de diseño y favicon; plantilla base; `retroalimentacion-ui.js`; ADR 001 a 014; fichas de proceso; registro de IA | RNF-011 a RNF-019, RUI-001 a RUI-009, RGO-001, RDOC-001 | M1: arquitectura y sistema de diseño aprobados |
| Pausa | 21-dic-2026 a 8-ene-2027 | 0 | — | — | — | — |
| I1 Núcleo de plataforma | 12-ene a 22-ene-2027 | 9 | Infraestructura transversal operativa | Cola durable, trabajador y programador por cron, seguimiento de trabajos, correo con cortacircuitos, salud, registro JSON con `X-Request-ID`, caché versionada, señal e intermediario de mantenimiento, funcionalidades configurables | RF-Q-001 a RF-Q-019, RF-MAN-005 a RF-MAN-007, RF-MAN-012, RAU-008, RAU-009, ROB-007, ROB-010, ROB-011 | Cola demostrada con dos trabajadores |
| I2 Identidad y acceso | 25-ene a 5-feb-2027 | 10 | Acceso seguro con autorización por alcance | Invitación y activación, Argon2id, política NIST, lista de bloqueadas, sesiones en BD, recuperación, TOTP y respaldo, límites de frecuencia, CSRF, CSP, reautenticación, RBAC/ABAC, `AlcanceDatos`, matriz de autorización probada, auditoría con sellado, panel de mantenimiento | RAC-001 a RAC-031, RS-002 a RS-008, RAU-001 a RAU-007, RDI-001 a RDI-005, RF-MAN-001 a RF-MAN-004 | M2: acceso seguro con 2FA demostrado |
| I3 Maestros y finanzas | 8-feb a 19-feb-2027 | 10 | Catálogos y presupuesto | Unidades, productos, homologación, derivados con factores, categorías de gasto (Materiales de formación), parámetros, asignaciones, gastos y soportes, procesos de autoconsumo, cierres de periodo, UI de usuarios y auditoría | RF-009 a RF-028, RF-036 a RF-038 | Demostración de las Obs. 1 y 5 |
| I4 Operación productiva | 22-feb a 5-mar-2027 | 10 | Trazabilidad completa | Recepciones, salidas, transformaciones con selección del derivado y recepción en el mismo flujo, hato por cortes, semáforos, lote de práctica, bandeja de calidad, consulta de recaudos con política | RF-029, RF-035, RF-039 a RF-061 | M3: Obs. 1, 2, 5 y 6 verificadas |
| I5 Importación y recaudos | 8-mar a 19-mar-2027 | 10 | Carga de las BBDD actualizadas del piloto | 8 fuentes, validación en *streaming*, vista previa, aplicación por bloques, reversión, reporte de errores, recaudos de la DG con clasificación y conciliación, contrato OpenAPI con simulador, carga del piloto en staging con acta | RF-030 a RF-034, RF-083 a RF-098 | M4: Obs. 3 atendida (acta de carga) |
| I6 Tableros e indicadores | 22-mar a 2-abr-2027 | 7 | Tableros con cifras verificadas | Tablero central (IND_001 e IND_002, filtros, navegación a la unidad, alcance del líder), tablero técnico (IND_003, IND_08, IND_12, IND_17, salud reproductiva), oráculos, caché, exportaciones XLSX y PDF; validación de S-07, S-08 y S-11 | RF-062 a RF-082, RNF-PERF-012 | M5: tableros con oráculos y Obs. 4 aceptada |
| I7 Rendimiento y operación | 5-abr a 16-abr-2027 | 10 | Capacidad demostrada y operación lista | Inventario de consultas e índices con EXPLAIN, pruebas k6 (smoke, sostenida, pico y ráfaga), optimización, copias cifradas, restauración probada, despliegue y reversión, runbooks, monitoreo, verificación de integridad, mantenimiento con drenaje | RNF-PERF-001 a RNF-PERF-020, RDL-001 a RDL-011, ROB-001 a ROB-014, RGO-004 | M6: informe de rendimiento y restauración probada |
| I8 Endurecimiento, UAT y salida | 19-abr a 30-abr-2027 | 10 | Salida a producción del piloto | ZAP autenticado completo, lista ASVS nivel 2 firmada, auditoría WCAG 2.2 AA, UAT del piloto de Ganadería, carga inicial en producción con cifras de control, capacitación por rol, guías | RS-001, RS-016, RGO-009, RF-094, RDOC-009 | M7: salida a producción (29-abr-2027) |
| E Estabilización | 3-may a 14-may-2027 | 9 | Acompañamiento posterior a la salida | Soporte, corrección de incidencias, ajustes de rendimiento, revisión de alertas, acta de cierre y plan de la v1.1 (SSO, otras unidades) | RNF-006, RGO-005 | M8: cierre del proyecto |

**Validaciones con la contraparte según el calendario:**

| Iteración | Supuestos que se cierran |
|---|---|
| I0 | S-01 a S-06, S-15, S-16, S-19, S-20 y S-22 |
| I1 | S-03, S-09 y S-21 |
| I2 | S-04, S-05 y S-23 |
| I3 | S-12, S-13 y S-14 |
| I5 | S-11, S-18 y S-25 |
| I6 | S-07, S-08 y S-10 |
| I7 | S-17 |

## 5.2 Asignación de recursos por iteración

**Roles del equipo:**

- **Senior:** arquitecto y líder técnico. Responde por la seguridad y la gestión del proyecto.
- **Mid:** desarrollador *full-stack* PHP con HTMX.
- **Junior:** frontend, QA y documentación.
- **UX/UI:** diseño del sistema visual y de los tableros.

Las horas resultan de multiplicar los días hábiles por 8 y por la dedicación.

<!-- tabla-densa -->
| Iteración | Días | Senior (%, h) | Mid (%, h) | Junior (%, h) | UX/UI (%, h) | Horas totales | Costo directo (USD) | Con contingencia del 15 % (USD) |
|---|---|---|---|---|---|---|---|---|
| I0 Fundaciones | 14 | 75 %, 84 h | 100 %, 112 h | 100 %, 112 h | 50 %, 56 h | 364 | 11.256 | 12.944,40 |
| I1 Núcleo de plataforma | 9 | 50 %, 36 h | 100 %, 72 h | 100 %, 72 h | 25 %, 18 h | 198 | 5.796 | 6.665,40 |
| I2 Identidad y acceso | 10 | 50 %, 40 h | 100 %, 80 h | 100 %, 80 h | 0 % | 200 | 5.840 | 6.716,00 |
| I3 Maestros y finanzas | 10 | 50 %, 40 h | 100 %, 80 h | 100 %, 80 h | 0 % | 200 | 5.840 | 6.716,00 |
| I4 Operación productiva | 10 | 50 %, 40 h | 100 %, 80 h | 100 %, 80 h | 0 % | 200 | 5.840 | 6.716,00 |
| I5 Importación y recaudos | 10 | 50 %, 40 h | 100 %, 80 h | 100 %, 80 h | 0 % | 200 | 5.840 | 6.716,00 |
| I6 Tableros e indicadores | 7 | 50 %, 28 h | 100 %, 56 h | 100 %, 56 h | 25 %, 14 h | 154 | 4.508 | 5.184,20 |
| I7 Rendimiento y operación | 10 | 50 %, 40 h | 100 %, 80 h | 100 %, 80 h | 0 % | 200 | 5.840 | 6.716,00 |
| I8 Endurecimiento, UAT y salida | 10 | 75 %, 60 h | 100 %, 80 h | 100 %, 80 h | 0 % | 220 | 6.840 | 7.866,00 |
| E Estabilización | 9 | 25 %, 18 h | 50 %, 36 h | 50 %, 36 h | 0 % | 90 | 2.628 | 3.022,20 |
| **Total** | **99** | **426 h** | **756 h** | **756 h** | **88 h** | **2.026** | **60.228** | **69.262,20** |

**Matriz RACI** (R = responsable, A = aprueba, C = consultado, I = informado) (RGO-007):

| Entregable | Contraparte CAB (Líder de producción) | Coord. administrativa y Grupo de Recaudo | TI y seguridad del centro | Senior | Mid | Junior | UX/UI |
|---|---|---|---|---|---|---|---|
| Requisitos, supuestos y cambios | A | C | C | R | I | I | I |
| Arquitectura y ADR | I | I | C | A/R | C | I | I |
| Sistema de diseño y favicon | C | C | I | A | I | C | R |
| Desarrollo de funcionalidades | I | I | I | A | R | R | C |
| Pruebas (PU, PI, PE, PC, PA) | I | I | I | A | R | R | C |
| Seguridad (ASVS, SAST y DAST) | I | I | C | A/R | R | C | I |
| Rendimiento (PCA) | I | I | C | A | R | R | I |
| Despliegue, copias y runbooks | I | I | C | A | R | C | I |
| UAT y aceptación de cada iteración | A | R | C | C | I | I | I |
| Capacitación y guías | A | C | I | R | C | R | C |

## 5.3 Cálculo de costos totales, desglose y métricas

**Costo por rol:**

| Rol | Horas | Tarifa (USD/h) | Costo (USD) | % de las horas | % del costo |
|---|---|---|---|---|---|
| Senior (arquitecto y líder técnico) | 426 | 50 | 21.300 | 21,0 % | 35,4 % |
| Mid (desarrollador *full-stack*) | 756 | 30 | 22.680 | 37,3 % | 37,7 % |
| Junior (frontend, QA y documentación) | 756 | 18 | 13.608 | 37,3 % | 22,6 % |
| UX/UI (tarifa Mid) | 88 | 30 | 2.640 | 4,3 % | 4,4 % |
| **Subtotal directo** | **2.026** | **29,73 (ponderada)** | **60.228** | **100 %** | **100 %** |
| Contingencia (15 %) | — | — | 9.034,20 | — | — |
| **Total del proyecto** | — | — | **69.262,20** | — | — |

**Distribución estimada del esfuerzo por disciplina** (orientativa, para controlar la ejecución):

| Disciplina | Horas estimadas | % |
|---|---|---|
| Desarrollo backend y frontend | 1.050 | 51,8 % |
| Pruebas y aseguramiento de la calidad | 365 | 18,0 % |
| Seguridad (diseño, pruebas y revisión ASVS) | 180 | 8,9 % |
| Rendimiento, operación y despliegue | 140 | 6,9 % |
| Documentación y evidencia de IA | 102 | 5,0 % |
| Gestión, coordinación y demostraciones | 101 | 5,0 % |
| Diseño UX/UI | 88 | 4,3 % |
| **Total** | **2.026** | **100 %** |

**Métricas del plan:**

| Métrica | Valor |
|---|---|
| Duración en calendario | Del 30-nov-2026 al 14-may-2027: 24 semanas, de las cuales 3 son de pausa |
| Días hábiles y semanas laborables | 99 días; 19,8 semanas |
| Horas totales del equipo | 2.026 h |
| Costo directo / con contingencia | USD 60.228 / USD 69.262,20 |
| Costo medio por iteración de 2 semanas (I1 a I8) | USD 5.793 directo |
| Costo y horas por requisito (357 requisitos) | USD 168,71 (194,01 con contingencia); 5,68 h por requisito |
| Gasto acumulado al hito M4 (fin de la I5) | 67,1 % del costo directo |
| Gasto acumulado a la salida a producción (fin de la I8) | 95,6 % del costo directo |
| Indicadores de control por iteración | Horas reales frente a las planeadas, requisitos aceptados frente a los comprometidos, defectos abiertos por severidad, cobertura de pruebas y deuda técnica registrada |

**Costos no laborales** (referenciales; no se incluyen en el total):

- Plan Hostinger Cloud y dominio: los cotiza el titular de la cuenta.
- Certificados SSL: incluidos en el plan (Let's Encrypt).
- Monitoreo externo: plan gratuito o básico.
- Herramientas: software libre (k6, OWASP ZAP, Playwright, PHPStan y PHPUnit).
- Almacenamiento externo de las copias: por definir con el centro.
- Prueba de penetración externa: opcional, a cotizar.

**Conversión a pesos colombianos:** costo en COP = costo en USD × TRM vigente en la fecha de la propuesta. Este SRS no fija la TRM.

**Registro de riesgos (RGO-008):**

| # | Riesgo | Probabilidad e impacto | Mitigación | Responsable |
|---|---|---|---|---|
| R1 | El plan Cloud trae una versión de MariaDB anterior a la 10.6 o límites menores de los supuestos | Media / Alta | Verificación en la I0; estrategia alternativa de reclamo; ajuste del presupuesto de la cola; VPS como evolución | Senior |
| R2 | La contraparte no está disponible para validar los supuestos | Media / Alta | Calendario de validaciones (5.1), actas por iteración y supuestos con fecha límite | Senior y contraparte |
| R3 | Las BBDD del piloto tienen vínculos incompletos | Alta / Media | Bandeja de calidad, cifras de control y aceptación explícita de las brechas | Mid y Grupo de Recaudo |
| R4 | Cambia el formato del archivo de la DG | Media / Media | Mapeo por nombres normalizados, plantilla versionada y reporte de errores | Mid |
| R5 | El software de ventas no estabiliza su contrato | Media / Media | Contrato OpenAPI y archivo equivalente (RF-096) | Senior |
| R6 | El cron o los procesos del plan tienen restricciones | Media / Alta | Prueba de 55 s, ajuste de parámetros y alternativa VPS documentada | Mid |
| R7 | El proveedor restringe las pruebas de carga | Media / Media | Autorización previa, límites de tráfico y entorno equivalente | Senior |
| R8 | Hay dependencia de pocas personas del equipo | Baja / Alta | ADR, fichas de proceso, revisión cruzada y documentación viva | Senior |
| R9 | El código generado con IA tiene vulnerabilidades | Media / Alta | Revisión humana obligatoria (RS-018), SAST y DAST, y lista ASVS | Senior |
| R10 | Los plazos de retención (TRD) no se definen a tiempo | Media / Media | Supuesto S-17 y validación con gestión documental antes de la I7 | Contraparte |
| R11 | Festivos y Semana Santa reducen la capacidad de la I6 | Alta / Baja | Capacidad ya descontada (7 días) y priorización MoSCoW | Senior |
| R12 | Falta autorización para usar la marca SENA | Media / Baja | Paleta neutra sin logotipo hasta tener la autorización (S-03) | UX/UI |

<!-- horizontal -->

## 5.4 Gantt simplificado

Cada columna es una semana; la semana S1 empieza el 30-nov-2026 y la S24 el 10-may-2027. El rombo rojo (◆) marca los hitos.

```gantt-tabla
{
  "semanas": ["S1","S2","S3","S4","S5","S6","S7","S8","S9","S10","S11","S12","S13","S14","S15","S16","S17","S18","S19","S20","S21","S22","S23","S24"],
  "filas": [
    {"tarea": "I0 Fundaciones", "inicio": 1, "duracion": 3, "color": "15803D", "responsable": "Equipo completo"},
    {"tarea": "Pausa de fin de año", "inicio": 4, "duracion": 3, "color": "BFC7CE", "responsable": "—"},
    {"tarea": "I1 Núcleo de plataforma", "inicio": 7, "duracion": 2, "color": "15803D", "responsable": "Mid y Senior"},
    {"tarea": "I2 Identidad y acceso", "inicio": 9, "duracion": 2, "color": "15803D", "responsable": "Senior y Mid"},
    {"tarea": "I3 Maestros y finanzas", "inicio": 11, "duracion": 2, "color": "15803D", "responsable": "Mid y Junior"},
    {"tarea": "I4 Operación productiva", "inicio": 13, "duracion": 2, "color": "15803D", "responsable": "Mid y Junior"},
    {"tarea": "I5 Importación y recaudos", "inicio": 15, "duracion": 2, "color": "15803D", "responsable": "Mid y Senior"},
    {"tarea": "I6 Tableros e indicadores", "inicio": 17, "duracion": 2, "color": "15803D", "responsable": "Mid, Junior y UX"},
    {"tarea": "I7 Rendimiento y operación", "inicio": 19, "duracion": 2, "color": "15803D", "responsable": "Senior y Mid"},
    {"tarea": "I8 Endurecimiento, UAT y salida", "inicio": 21, "duracion": 2, "color": "15803D", "responsable": "Equipo y contraparte"},
    {"tarea": "E Estabilización", "inicio": 23, "duracion": 2, "color": "7896A9", "responsable": "Equipo (parcial)"},
    {"tarea": "Pruebas E2E y accesibilidad continuas", "inicio": 7, "duracion": 16, "color": "4A718F", "responsable": "Junior"},
    {"tarea": "Seguridad: SAST continuo y DAST", "inicio": 9, "duracion": 14, "color": "4A718F", "responsable": "Senior"},
    {"tarea": "Carga del piloto (staging y producción)", "inicio": 15, "duracion": 2, "color": "D97706", "responsable": "Mid y Grupo de Recaudo"},
    {"tarea": "Pruebas de carga k6", "inicio": 18, "duracion": 3, "color": "D97706", "responsable": "Mid y Senior"},
    {"tarea": "UAT del piloto y capacitación", "inicio": 21, "duracion": 2, "color": "D97706", "responsable": "Contraparte"},
    {"tarea": "M1 Arquitectura aprobada", "hito": true, "semana": 3, "responsable": "Contraparte"},
    {"tarea": "M2 Acceso seguro (2FA)", "hito": true, "semana": 10, "responsable": "Senior"},
    {"tarea": "M3 Obs. 1, 2, 5 y 6 verificadas", "hito": true, "semana": 14, "responsable": "Contraparte"},
    {"tarea": "M4 BBDD del piloto cargadas (Obs. 3)", "hito": true, "semana": 16, "responsable": "Contraparte"},
    {"tarea": "M5 Tableros con oráculos (Obs. 4)", "hito": true, "semana": 18, "responsable": "Contraparte"},
    {"tarea": "M6 Rendimiento y restauración", "hito": true, "semana": 20, "responsable": "Senior"},
    {"tarea": "M7 Salida a producción", "hito": true, "semana": 22, "responsable": "Contraparte y Senior"},
    {"tarea": "M8 Cierre del proyecto", "hito": true, "semana": 24, "responsable": "Contraparte"}
  ]
}
```

| Semana | Inicia | Semana | Inicia | Semana | Inicia | Semana | Inicia |
|---|---|---|---|---|---|---|---|
| S1 | 30-nov-2026 | S7 | 11-ene-2027 | S13 | 22-feb-2027 | S19 | 5-abr-2027 |
| S2 | 7-dic-2026 | S8 | 18-ene-2027 | S14 | 1-mar-2027 | S20 | 12-abr-2027 |
| S3 | 14-dic-2026 | S9 | 25-ene-2027 | S15 | 8-mar-2027 | S21 | 19-abr-2027 |
| S4 | 21-dic-2026 | S10 | 1-feb-2027 | S16 | 15-mar-2027 | S22 | 26-abr-2027 |
| S5 | 28-dic-2026 | S11 | 8-feb-2027 | S17 | 22-mar-2027 | S23 | 3-may-2027 |
| S6 | 4-ene-2027 | S12 | 15-feb-2027 | S18 | 29-mar-2027 | S24 | 10-may-2027 |

<!-- vertical -->
