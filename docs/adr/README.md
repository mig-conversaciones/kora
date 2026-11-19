# Registro de decisiones de arquitectura (ADR)

Formato del Anexo D (D.5) del SRS. Un ADR aceptado no se edita: se reemplaza por otro nuevo que lo indique (RGO-001).

| ADR | Título | Estado |
|---|---|---|
| [ADR-001](001-interfaz-htmx-alpine.md) | Interfaz dirigida por el servidor con HTMX y Alpine en lugar de una SPA | Aceptado |
| [ADR-002](002-mariadb-sql-portable.md) | MariaDB con SQL portable a MySQL 8 y desarrollo local en el MySQL 5.7 de MAMP | Aceptado |
| [ADR-003](003-cola-durable-cron.md) | Cola durable en la base de datos con trabajador por cron, sin Redis | Aceptado |
| [ADR-004](004-csp-estricta.md) | CSP estricta: allowEval=false en htmx, Alpine CSP y retroalimentación centralizada | Aceptado |
| [ADR-005](005-dependencias-composer.md) | Dependencias de Composer permitidas | Aceptado |
| [ADR-006](006-sesiones-en-bd.md) | Sesiones en la base de datos con el ID convertido a hash | Aceptado |
| [ADR-007](007-autorizacion-hibrida-alcance.md) | Autorización híbrida y repositorios con alcance obligatorio (sin RLS nativo) | Aceptado |
| [ADR-008](008-auditoria-encadenada.md) | Auditoría encadenada por hash y exportación firmada | Aceptado |
| [ADR-009](009-graficos-svg-servidor.md) | Gráficos SVG generados en el servidor | Aceptado |
| [ADR-010](010-cache-archivos-version.md) | Caché de aplicación en archivos con versión de datos por centro | Aceptado |
| [ADR-011](011-despliegue-versiones.md) | Despliegue por versiones con enlace atómico y migraciones expand/contract | Aceptado |
| [ADR-012](012-ind17-gasto-ejecutado.md) | IND_17 con gasto ejecutado y referencia presupuestada | Propuesto (validar S-11) |
| [ADR-013](013-escenario-capacitacion.md) | El escenario demostrativo se convierte en entorno de capacitación | Aceptado |
| [ADR-014](014-nombres-espanol.md) | Nombres en español, equivalencias con la plantilla y nombre de la aplicación | Aceptado |
| ADR-015 | Inicio de sesión único con Entra ID y llaves WebAuthn | Propuesto (fuera de la v1) |
| [ADR-016](016-identidad-visual-kora.md) | Identidad visual KORA (logo, íconos y paleta del sistema) | Aceptado |
| [ADR-017](017-modulos-de-gestion.md) | Módulos de gestión con montos decimales exactos y reglas de bloqueo que se activan con el esquema | Aceptado |
| [ADR-018](018-operacion-productiva.md) | Operación productiva con reglas puras, bloqueo del lote y transacción única para la transformación | Aceptado |
| [ADR-019](019-importacion-asistida.md) | Importación asistida por fuentes, con lotes validados en la cola y aplicación reanudable | Aceptado |
| [ADR-020](020-tableros-e-indicadores.md) | Tableros con indicadores calculados en SQL sobre el alcance, caché versionada, SVG del servidor y exportaciones en la cola | Aceptado |
| [ADR-021](021-operacion-respaldos-despliegue.md) | Copias cifradas en sobre desde PHP, despliegue por versiones verificado en la CI y presupuesto de consultas medido sobre un dataset sintético | Aceptado |
| [ADR-022](022-salida-carga-inicial-verificacion.md) | Carga inicial con verificación repetida al firmar, lista ASVS generada desde el texto oficial y verificación de navegador y DAST en la CI | Aceptado |
| [ADR-023](023-contrato-integracion-simulador.md) | Contrato de integración con la firma del anexo E, simulador propio y pruebas de contrato con Schemathesis | Aceptado |
