# Runbooks de KORA

Inventario de RGO-004. La plantilla está en el anexo D (D.6). Cada runbook se ejerce al menos una vez antes de la salida a producción. La columna «Ejercicio» registra el último.

## Incidentes y operaciones (RGO-004)

| Runbook | Disparador | Ejercicio |
|---|---|---|
| [Cola atascada](cola-atascada.md) | Pendiente de más de 5 min o latido de más de 3 min (RF-Q-016) | Pruebas de integración de la cola (I1). En staging: pendiente |
| [Base de datos lenta](bd-lenta.md) | p95 por encima del SLO o alertas de recursos | Inventario de consultas local con 3 años de datos (I7) |
| [SSL por vencer o expirado](ssl-expirado.md) | Aviso a 15 días (programador) o error TLS | Prueba del aviso con un certificado de 14 días (I7). En hPanel: pendiente |
| [Copias, backup corrupto y restauración](backup-corrupto.md) | Copia fallida o que no verifica; prueba trimestral | Simulacro local (2026-11-19) y en la CI en cada push (I7). Trimestral en staging: pendiente |
| [SMTP caído](smtp-caido.md) | Circuito `smtp` abierto o fallos de correo | Cortacircuitos probado en la integración (I1). Con el SMTP real: pendiente |
| [Importación fallida](importacion-fallida.md) | Lote `fallido` o `con_errores` | Pruebas de importación (I5) |
| [Mantenimiento](mantenimiento.md) | Ventana planificada o incidente | Despliegue con mantenimiento en la CI (I7) |
| [Incidente de seguridad](incidente-seguridad.md) | Alertas de seguridad o de integridad | Alerta de integridad simulada en la CI (I7). Simulacro de mesa: pendiente (I8) |
| [Rotación de claves](rotacion-claves.md) | Cada 90 días o ante sospecha | Pendiente (primera rotación en producción) |
| [Despliegue y reversión](despliegue-reversion.md) | Cada versión | Dos despliegues y una reversión en la CI en cada push (I7). En staging: pendiente |
| [IA caída](ia-caida.md) | NO APLICA en la v1 (D-27) | — |

## Por módulo (I1 a I6)

- [Cola, cron y mantenimiento](cola-cron-mantenimiento.md)
- [Acceso y cuentas](acceso-y-cuentas.md)
- [Maestros y finanzas](maestros-y-finanzas.md)
- [Operación productiva](operacion-productiva.md)
- [Importaciones y recaudos](importaciones-y-recaudos.md)
- [Tableros y exportaciones](tableros-y-exportaciones.md)

## Monitoreo externo (ROB-012)

Pendiente de configurar con el titular, porque necesita el dominio de staging o de producción:

- **Servicio:** UptimeRobot, Better Stack o similar.
- **Consultas:**
  - `GET https://DOMINIO/health/live` cada 5 min, que espera 200 y `"vivo"`;
  - `GET https://DOMINIO/health/ready` con la cabecera `X-Token-Monitoreo: <TOKEN_MONITOREO>` cada 5 min, que espera 200.
- **Vencimiento del certificado:** con aviso a 15 días.
- **Avisos:** por correo a `CORREO_ALERTAS` y a los responsables.
- **Criterio de ROB-012:** al simular una caída en staging (mantenimiento activo), el aviso llega en 10 min o menos.
