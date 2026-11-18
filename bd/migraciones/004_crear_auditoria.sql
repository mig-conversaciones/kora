-- 004: auditoría funcional de solo inserción con cadena de hash y sellado diferido (RAU-004, D-39).
CREATE TABLE eventos_auditoria (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    evento_id       CHAR(26)     NOT NULL COMMENT 'ULID',
    ocurrido_en     DATETIME(3)  NOT NULL COMMENT 'UTC',
    request_id      CHAR(32)     NULL,
    actor_id        BIGINT UNSIGNED NULL,
    actor_rol       VARCHAR(40)  NULL,
    centro_id       INT UNSIGNED NULL,
    unidad_id       INT UNSIGNED NULL,
    accion          VARCHAR(80)  NOT NULL COMMENT 'recurso.verbo',
    recurso         VARCHAR(60)  NULL,
    recurso_id      VARCHAR(64)  NULL,
    resultado       ENUM('exito','denegado','error','duplicado','encolado') NOT NULL,
    origen          ENUM('web','htmx','cli','cron','api') NOT NULL,
    ip_tratada      VARCHAR(50)  NULL COMMENT 'IPv4 /24 o IPv6 /48',
    ip_hmac         CHAR(64)     NULL,
    agente          VARCHAR(255) NULL,
    valores_antes   JSON NULL,
    valores_despues JSON NULL,
    motivo          VARCHAR(500) NULL,
    metadatos       JSON NULL,
    secuencia_sello BIGINT UNSIGNED NULL COMMENT 'Orden de la cadena asignado al sellar',
    hash_anterior   CHAR(64) NULL,
    hash_evento     CHAR(64) NULL COMMENT 'NULL hasta el sellado (máximo 3 min)',
    PRIMARY KEY (id),
    UNIQUE KEY uq_auditoria_evento (evento_id),
    UNIQUE KEY uq_auditoria_secuencia (secuencia_sello),
    UNIQUE KEY uq_auditoria_hash (hash_evento),
    KEY idx_auditoria_fecha (ocurrido_en, id),
    KEY idx_auditoria_actor (actor_id, ocurrido_en),
    KEY idx_auditoria_recurso (recurso, recurso_id),
    KEY idx_auditoria_request (request_id),
    KEY idx_auditoria_accion (accion, ocurrido_en)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Auditoría funcional de solo inserción (RAU-001)';

CREATE TABLE cadena_auditoria (
    id               TINYINT UNSIGNED NOT NULL,
    ultima_secuencia BIGINT UNSIGNED  NOT NULL,
    ultimo_hash      CHAR(64)         NOT NULL,
    PRIMARY KEY (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Estado del sellado de la cadena';

INSERT INTO cadena_auditoria (id, ultima_secuencia, ultimo_hash) VALUES (1, 0, REPEAT('0', 64));
