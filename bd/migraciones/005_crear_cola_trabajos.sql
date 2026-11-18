-- 005: cola durable de trabajos, trabajos fallidos y estado de cortacircuitos (RF-Q-001, RNF-008).
-- Compatible con MariaDB 10.6+, MySQL 8.0+ y MySQL 5.7.24 (local, ADR-002): los CHECK se ignoran en 5.7 y se validan también en PHP. Las fechas son UTC (la sesión fija time_zone = '+00:00').

CREATE TABLE trabajos (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Identificador interno',
    ulid               CHAR(26)        NOT NULL COMMENT 'Identificador público usado en URL (RBD-004)',
    cola               VARCHAR(30)     NOT NULL DEFAULT 'predeterminada' COMMENT 'Cola lógica',
    tipo               VARCHAR(60)     NOT NULL COMMENT 'Tipo registrado en config/cola.php',
    carga              JSON            NOT NULL COMMENT 'Datos mínimos del trabajo, sin secretos ni PII',
    estado             ENUM('pendiente','reservado','completado','fallido','cancelado') NOT NULL DEFAULT 'pendiente',
    prioridad          TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1 = más alta',
    intentos           SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Se incrementa al reservar',
    max_intentos       SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    disponible_en      DATETIME(3)     NOT NULL COMMENT 'No se reclama antes de esta fecha (espera de reintento)',
    bloqueado_hasta    DATETIME(3)     NULL COMMENT 'Vencimiento de la reserva',
    reservado_por      CHAR(32)        NULL COMMENT 'Token de reserva del trabajador',
    clave_idempotencia VARCHAR(120)    NULL COMMENT 'X-Idempotency-Key u operación equivalente',
    actor_id           BIGINT UNSIGNED NULL COMMENT 'Usuario que originó el trabajo',
    centro_id          INT UNSIGNED    NULL,
    unidad_id          INT UNSIGNED    NULL,
    alcance            JSON            NULL COMMENT 'Alcance del actor al encolar; se reevalúa al ejecutar (RAC-024)',
    progreso           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    mensaje_progreso   VARCHAR(255)    NULL,
    punto_control      JSON            NULL COMMENT 'Avance de trabajos por bloques o efectos ya realizados',
    resultado          JSON            NULL,
    mensaje_error      VARCHAR(1000)   NULL COMMENT 'Error sanitizado: sin rutas, SQL ni secretos',
    correlacion_id     CHAR(32)        NULL COMMENT 'X-Request-ID de la solicitud de origen',
    creado_en          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actualizado_en     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    iniciado_en        DATETIME(3)     NULL,
    finalizado_en      DATETIME(3)     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trabajos_ulid (ulid),
    UNIQUE KEY uq_trabajos_idempotencia (centro_id, tipo, clave_idempotencia),
    KEY idx_trabajos_reclamo (cola, estado, prioridad, disponible_en, id),
    KEY idx_trabajos_vencidos (estado, bloqueado_hasta),
    KEY idx_trabajos_actor (actor_id, creado_en),
    KEY idx_trabajos_limpieza (estado, finalizado_en),
    CONSTRAINT fk_trabajos_actor FOREIGN KEY (actor_id) REFERENCES usuarios (id),
    CONSTRAINT fk_trabajos_centro FOREIGN KEY (centro_id) REFERENCES centros (id),
    CONSTRAINT chk_trabajos_progreso CHECK (progreso <= 100),
    CONSTRAINT chk_trabajos_intentos CHECK (max_intentos BETWEEN 1 AND 20)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Cola durable de trabajos asíncronos (RF-Q-001)';

CREATE TABLE trabajos_fallidos (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    trabajo_id     BIGINT UNSIGNED NOT NULL COMMENT 'Trabajo original (permanece en estado fallido)',
    ulid           CHAR(26)        NOT NULL,
    cola           VARCHAR(30)     NOT NULL,
    tipo           VARCHAR(60)     NOT NULL,
    carga          JSON            NOT NULL,
    intentos       SMALLINT UNSIGNED NOT NULL,
    actor_id       BIGINT UNSIGNED NULL,
    centro_id      INT UNSIGNED    NULL,
    mensaje_error  VARCHAR(1000)   NOT NULL,
    clase_error    ENUM('definitivo','transitorio_agotado','reserva_perdida') NOT NULL,
    correlacion_id CHAR(32)        NULL,
    fallido_en     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    revisado_por   BIGINT UNSIGNED NULL,
    revisado_en    DATETIME(3)     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fallidos_trabajo (trabajo_id),
    KEY idx_fallidos_fecha (fallido_en),
    CONSTRAINT fk_fallidos_trabajo FOREIGN KEY (trabajo_id) REFERENCES trabajos (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Trabajos con fallo definitivo para revisión (RF-Q-009)';

CREATE TABLE estado_circuitos (
    servicio            VARCHAR(40)  NOT NULL COMMENT 'smtp, integracion_ventas, ...',
    estado              ENUM('cerrado','abierto','semiabierto') NOT NULL DEFAULT 'cerrado',
    fallos_consecutivos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    abierto_hasta       DATETIME(3)  NULL,
    actualizado_en      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (servicio)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Estado compartido de cortacircuitos por servicio externo (RNF-008, D-29)';
