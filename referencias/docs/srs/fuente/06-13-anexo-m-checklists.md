## Anexo M. Checklists finales

Las listas consolidan los criterios que la plantilla exige revisar. En este SRS, cada ítem está **especificado**, con la referencia al requisito o sección que lo define. Su **evidencia** queda en estado PENDIENTE DE EJECUCIÓN hasta las iteraciones indicadas.

### M.1 Lista de la plantilla (regla 7)

| Verificación | Dónde queda definida | Evidencia esperada | Iteración |
|---|---|---|---|
| Mensajería centralizada; sin `alert()`, `confirm()`, `prompt()` ni `console.log()` como retroalimentación | RUI-009 a RUI-012; 4.3.4 | ESLint con `no-alert` y `no-console`; E2E de toasts y modales | I0 e I1 |
| Estados Loading, Empty, Error y Skeleton en cada componente asíncrono | RUI-013 a RUI-016; 4.3.3 | E2E con red lenta y errores forzados; Lighthouse con CLS de 0,1 o menos | I0 a I6 |
| Separación entre Alpine (estado visual) y HTMX (datos del servidor) | RI-001; 4.3.1 | Revisión de componentes y ausencia de datos de negocio en Alpine | I0 a I6 |
| CSRF en todas las escrituras | RS-005; 4.7.4 | PI: escrituras sin token responden 403 | I2 |
| Hash de contraseñas: Argon2id, con bcrypt solo como alternativa justificada | RAC-002; D-03 | `verificar_entorno.php` y medición del costo en el plan | I2 |
| Sentencias preparadas sin interpolación de entrada | RS-003; RBD-017; 4.2.3 | PHPStan con reglas y ZAP sin inyección | I0 a I8 |
| Cabeceras de seguridad y CSP estricta | RS-007, RS-008; 4.7.2 | Prueba de contrato de cabeceras y E2E sin violaciones de CSP | I2 |
| Accesibilidad WCAG 2.2 AA | RUI-002 a RUI-024; RNF-003 | axe sin errores y revisión manual con teclado y lector de pantalla | I8 |
| Colas compatibles con Hostinger (cron, reclamo atómico, reintentos) | RF-Q-001 a RF-Q-019; 4.4 | Pruebas de 4.4.12 | I1 e I7 |
| Modo mantenimiento administrable | RF-MAN-001 a RF-MAN-018; anexo C | Pruebas de C.7 | I2 e I7 |
| Documentación y evidencia de IA | RDOC-001 a RDOC-011; anexo F | Registros en `docs/ia/` y fichas en `docs/procesos/` | Todas |

### M.2 Rendimiento y escalabilidad

| Verificación | Definición | Estado |
|---|---|---|
| Perfil de carga, dataset, SLO por flujo y límites del hosting definidos | 2.6; RNF-PERF-001 a RNF-PERF-004; 4.6.3 | Especificado; validación de la contraparte en la I6 |
| Consultas e índices con EXPLAIN y mediciones previstas; evidencia antes y después al implementar | 4.6.1; H.1 | Especificado; PENDIENTE DE EJECUCIÓN (I7) |
| Caché con TTL, invalidación, aislamiento, respaldo y prueba fría y caliente definidos | 4.6.2; H.2 | Especificado; PENDIENTE DE EJECUCIÓN (I6 e I7) |
| Correo, reportes y demás tareas pesadas con trabajos durables, reclamo seguro y reintentos idempotentes | 4.4; RF-Q | Especificado; PENDIENTE DE EJECUCIÓN (I1) |
| Scripts de carga reproducibles, entorno autorizado y umbrales vinculados a RNF-PERF | H.3, H.4 | Especificado; PENDIENTE DE EJECUCIÓN (I7) |
| Informe de ejecución por versión con capacidad observada, integridad, cola y limitaciones | H.5; RNF-PERF-018 | PENDIENTE DE EJECUCIÓN |
| Cambios de esquema, caché y cola con recuperación documentada y monitoreo de capacidad | RDL-002; ROB-004; RNF-PERF-019; runbooks | Especificado; PENDIENTE DE EJECUCIÓN (I7) |

### M.3 Salida a producción del piloto

| Verificación | Responsable | Estado |
|---|---|---|
| Lista de migración a Hostinger completa (4.8.2, ítems 1 a 20) | Proveedor y titular | PENDIENTE |
| Copia cifrada diaria funcionando y restauración probada con acta (RDL-005) | Proveedor | PENDIENTE |
| Monitoreo externo y alertas activos (ROB-012, RF-Q-016) | Proveedor | PENDIENTE |
| Lista ASVS nivel 2 firmada sin hallazgos altos abiertos (RGO-009) | Responsable de seguridad | PENDIENTE |
| Auditoría WCAG 2.2 AA aprobada | Proveedor y UX | PENDIENTE |
| UAT del piloto de Ganadería aprobada, con las observaciones 1 a 6 verificadas por los perfiles que las reportaron | Contraparte | PENDIENTE |
| Carga inicial en producción con acta de cifras de control (RF-094) | Contraparte y Grupo de Recaudo | PENDIENTE |
| Supuestos de 2.7 validados o con decisión registrada | Contraparte y proveedor | PENDIENTE |
| Capacitación por rol realizada y guías entregadas (RDOC-009) | Proveedor | PENDIENTE |
| Runbooks ejercidos, al menos una vez, en staging (RGO-004) | Proveedor | PENDIENTE |
| Informe de rendimiento con los SLO cumplidos en la carga objetivo (H.5) | Proveedor | PENDIENTE DE EJECUCIÓN |
| Aprobación formal de la salida a producción (acta M7) | Contraparte | PENDIENTE |
