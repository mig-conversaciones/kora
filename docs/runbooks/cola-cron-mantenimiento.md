# Runbook: cola, cron y modo mantenimiento

Se aplica a la plataforma de la I1. Requisitos: RF-Q-001 a RF-Q-019, RF-MAN-005 a RF-MAN-016, ROB-010 y ROB-011. Decisiones: ADR-002 y ADR-003.

## 1. Cron en Hostinger (hPanel → Avanzado → Tareas cron)

Cada entrada redirige su salida a `almacenamiento/registros/cron.log`. Ajusta la ruta del binario de PHP 8.3 a la que indique hPanel.

```text
* * * * * /usr/bin/php /home/USUARIO/domains/DOMINIO/kora/current/cli/trabajador_cron.php >> .../almacenamiento/registros/cron.log 2>&1
* * * * * sleep 30; /usr/bin/php /home/USUARIO/domains/DOMINIO/kora/current/cli/trabajador_cron.php >> .../almacenamiento/registros/cron.log 2>&1
* * * * * /usr/bin/php /home/USUARIO/domains/DOMINIO/kora/current/cli/programador.php >> .../almacenamiento/registros/cron.log 2>&1
```

| Proceso | Qué hace | Latido |
|---|---|---|
| `trabajador_cron.php` | Recupera reservas vencidas, reclama lotes y ejecuta trabajos durante 50 s como máximo. Admite como mucho `COLA_MAX_TRABAJADORES` instancias simultáneas | `almacenamiento/latidos/trabajador.txt` |
| `programador.php` | Vuelca a la auditoría los eventos que quedaron pendientes en la CLI, vigila la cola y alerta, encola la limpieza horaria y la diaria (01:00, hora de Colombia) y rota el registro técnico | `almacenamiento/latidos/programador.txt` |

**En MAMP (Windows, sin cron):** `php cli/cron_local.php` simula la tabla anterior (trabajador cada 30 s y programador cada minuto) hasta que se detiene con Ctrl+C.

## 2. Estrategia de reclamo según el motor

| Motor | `BD_SKIP_LOCKED` | `COLA_ESTRATEGIA_RECLAMO` |
|---|---|---|
| MariaDB ≥ 10.6 (Hostinger), MySQL ≥ 8.0 | `true` | `skip_locked` |
| MySQL 5.7 (MAMP local) | `false` | `actualizacion_condicional` |

`php cli/verificar_entorno.php` falla si se pide SKIP LOCKED en un motor que no lo soporta. La prueba `ColaTest` verifica ambas estrategias en la CI. En local se demostró la cola con dos trabajadores concurrentes y 24 trabajos: cada trabajo se procesó una sola vez y con un solo intento.

## 3. Diagnóstico de la cola

```bash
php cli/cola.php estado                                  # conteos, antigüedad del pendiente más viejo, latidos
php cli/cola.php fallidos --limite=20                    # últimos fallos con su clase y mensaje sanitizado
php cli/cola.php reintentar ULID --motivo="..."          # fallido → pendiente con intentos en 0 (auditado)
php cli/cola.php cancelar ULID --motivo="..."            # pendiente → cancelado (auditado)
php cli/cola.php descartar ULID --motivo="..."           # fallido revisado, no se reintenta (auditado)
```

| Alerta (RF-Q-016) | Umbral | Primeras acciones |
|---|---|---|
| `cola_atascada` | El pendiente más antiguo supera 5 min | ¿Corre el cron? Revisa `cron.log` y `cola.php estado`. ¿Hay un trabajo que agota el presupuesto? |
| `fallidos_excesivos` | Más de 5 fallidos en 1 h | `cola.php fallidos`. Si es el SMTP, revisa `estado_circuitos` y las credenciales |
| `trabajador_sin_latido` | Más de 3 min sin latido | El cron del trabajador no corre o el proceso muere: ejecútalo a mano y revisa `cron.log` |

Las alertas quedan en el registro técnico (`alerta.*`), en las notificaciones de los superadministradores y en el buzón `CORREO_ALERTAS`. Se silencian 30 min por condición.

**Mensajes de error de los trabajos.** Nunca muestran detalles internos. Un error no clasificado guarda «Código de soporte: …» con el `correlacion_id`; el detalle técnico se busca en `almacenamiento/registros/app-AAAA-MM-DD.log` por ese código.

## 4. Correo

- **Transportes:** `CORREO_TRANSPORTE=smtp` en Hostinger (465 con SSL o 587 con STARTTLS) y `archivo` en local, que deja los `.eml` en `almacenamiento/correos/` y los conserva 7 días.
- **Prueba:** `php cli/correo.php prueba` (al buzón de operaciones) o `php cli/correo.php prueba --usuario=ID`. Después ejecuta el trabajador.
- **Cortacircuitos:** 3 fallos SMTP consecutivos abren el circuito 60 s; luego pasa una sola prueba. El estado está en la tabla `estado_circuitos`.

## 5. Modo mantenimiento sin panel (RF-MAN-012)

```bash
php cli/mantenimiento.php estado
php cli/mantenimiento.php activar --motivo="Migración 007" --titulo="Actualizando KORA" --hasta="2027-01-20T22:00:00-05:00"
php cli/mantenimiento.php drenaje          # 0 = listo para migrar (sin trabajos de negocio en curso)
php cli/mantenimiento.php desactivar --motivo="Fin de la migración"
```

- **Activar:** se escribe primero la señal (`almacenamiento/estado/mantenimiento.json`) y después la base. La web responde 503 con aviso y el trabajador solo ejecuta tipos técnicos (correo del sistema y limpieza).
- **Desactivar:** exige que el esquema esté en la versión esperada, que la base responda y que haya un latido del trabajador de menos de 3 min. Si una comprobación falla, el mantenimiento sigue activo. Tras desactivar, durante 10 min el lote se reduce a 3.
- **Sin base de datos:** la CLI opera solo sobre la señal y deja el evento en `almacenamiento/estado/eventos_pendientes.jsonl`. El programador lo vuelca a la auditoría cuando la base vuelve. La desactivación sin base exige `--forzar-sin-bd`.

## 6. Salud

- `GET /health/live`: siempre 200 si PHP responde.
- `GET /health/ready` con `X-Token-Monitoreo`: base de datos, esquema, cola (pendiente de menos de 5 min), latidos del trabajador y del programador (menos de 3 min), disco y almacenamiento. Responde 503 si algo falla o si hay mantenimiento.
