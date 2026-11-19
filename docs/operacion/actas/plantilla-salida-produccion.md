# Acta de salida a producción del piloto (hito M7)

Plantilla del acta que cierra la I8 (SRS 4.8.2, ítem 20; anexo M.3). Se copia con la fecha en el nombre (`AAAA-MM-DD-salida-produccion.md`), se completa el día de la salida y se firma. Ninguna casilla se marca sin su evidencia.

| Campo | Valor |
|---|---|
| Fecha y hora (Colombia) | |
| Versión desplegada | `vX.Y.Z` · commit · migración |
| URL de producción | |
| Responsable del despliegue | |

## Lista de verificación (anexo M.3)

| # | Verificación | Evidencia | Estado |
|---|---|---|---|
| 1 | Lista de migración a Hostinger completa (4.8.2, ítems 1 a 20) | `docs/seguridad/verificacion-hostinger.md` | ☐ |
| 2 | Copia cifrada diaria funcionando y restauración probada con acta (RDL-005) | Acta de restauración en staging; `respaldo.php listar` | ☐ |
| 3 | Monitoreo externo y alertas activos (ROB-012, RF-Q-016) | Captura del monitor y prueba de caída | ☐ |
| 4 | Lista ASVS nivel 2 firmada, sin hallazgos altos abiertos (RGO-009) | `docs/seguridad/asvs.md` firmada; informe de ZAP de staging | ☐ |
| 5 | Auditoría WCAG 2.2 AA aprobada | `docs/ui/auditoria-wcag.md` con la revisión manual | ☐ |
| 6 | UAT del piloto aprobada, con las observaciones 1 a 6 verificadas | Acta de `docs/uat/plan-uat.md` | ☐ |
| 7 | Carga inicial en producción con acta de cifras de control (RF-094) | Acta de la carga inicial **firmada**, con su huella | ☐ |
| 8 | Supuestos de 2.7 validados o con decisión registrada | `docs/uat/supuestos.md` | ☐ |
| 9 | Capacitación por rol hecha y guías entregadas (RDOC-009) | Registro de `docs/uat/capacitacion.md` | ☐ |
| 10 | Runbooks ejercidos al menos una vez en staging (RGO-004) | Registro de ejercicios | ☐ |
| 11 | Informe de rendimiento con los SLO cumplidos en la carga objetivo (H.5) | Informe de k6 en staging | ☐ |

## Pruebas de humo en producción

| Prueba | Resultado |
|---|---|
| `php current/cli/humo.php --url=https://…` | ☐ |
| Ingreso de un usuario de cada rol del piloto | ☐ |
| Tablero central y tablero de Ganadería con los datos de la carga inicial | ☐ |
| Cron del trabajador y del programador con latido reciente | ☐ |
| Primera copia diaria completada y enviada fuera de Hostinger | ☐ |

## Riesgos aceptados

Riesgos con decisión de la contraparte, por ejemplo las decisiones pendientes de la lista ASVS: requisito, decisión, quién la tomó y fecha de revisión.

## Decisión

☐ Se autoriza la salida a producción · ☐ No se autoriza (motivo: …)

| Firma | Nombre | Rol | Fecha |
|---|---|---|---|
| Contraparte del CAB | | | |
| Titular de la cuenta | | | |
| Proveedor | | | |
