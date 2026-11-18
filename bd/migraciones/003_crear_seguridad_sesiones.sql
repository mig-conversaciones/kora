-- 003: sesiones en servidor, tokens de cuenta, códigos de respaldo, límites de frecuencia e idempotencia.
CREATE TABLE sesiones (
    id                  CHAR(64)    NOT NULL COMMENT 'SHA-256 del ID de sesión; nunca el ID (ADR-006)',
    usuario_id          BIGINT UNSIGNED NULL,
    datos               MEDIUMBLOB  NOT NULL,
    nivel_autenticacion ENUM('anonimo','contrasena','2fa') NOT NULL DEFAULT 'anonimo',
    creada_en           DATETIME(3) NOT NULL,
    ultima_actividad_en DATETIME(3) NOT NULL,
    expira_absoluta_en  DATETIME(3) NOT NULL,
    reautenticada_en    DATETIME(3) NULL COMMENT 'Reautenticación con 2FA (RAC-026)',
    ip_tratada          VARCHAR(50) NULL,
    ip_hmac             CHAR(64)    NULL,
    agente              VARCHAR(255) NULL,
    revocada_en         DATETIME(3) NULL,
    PRIMARY KEY (id),
    KEY idx_sesiones_usuario (usuario_id, revocada_en),
    KEY idx_sesiones_actividad (ultima_actividad_en),
    CONSTRAINT fk_sesiones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Sesiones del servidor (RAC-017)';

CREATE TABLE tokens_cuenta (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NOT NULL,
    tipo       ENUM('invitacion','recuperacion') NOT NULL,
    hash_token CHAR(64)    NOT NULL COMMENT 'SHA-256 del token; el token solo viaja en el enlace',
    expira_en  DATETIME(3) NOT NULL,
    usado_en   DATETIME(3) NULL,
    ip_hmac    CHAR(64)    NULL,
    creado_en  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_tokens_hash (hash_token),
    KEY idx_tokens_usuario (usuario_id, tipo, usado_en),
    CONSTRAINT fk_tokens_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Invitación y recuperación (RAC-006, RAC-007)';

CREATE TABLE codigos_respaldo_2fa (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id  BIGINT UNSIGNED NOT NULL,
    hmac_codigo CHAR(64)    NOT NULL,
    usado_en    DATETIME(3) NULL,
    generado_en DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_codigos_respaldo (usuario_id, hmac_codigo),
    CONSTRAINT fk_codigos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Códigos de respaldo de 2FA (RAC-011)';

CREATE TABLE limites_tasa (
    clave           VARCHAR(190) NOT NULL COMMENT 'tipo:dimension:hmac (cuenta, ip, sesión)',
    ventana_inicio  DATETIME(3)  NOT NULL,
    contador        INT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_hasta DATETIME(3)  NULL,
    actualizado_en  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (clave),
    KEY idx_limites_actualizado (actualizado_en)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Límites de frecuencia (RAC-029)';

CREATE TABLE claves_idempotencia (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id          BIGINT UNSIGNED NOT NULL,
    clave               VARCHAR(120) NOT NULL,
    metodo              VARCHAR(10)  NOT NULL,
    ruta                VARCHAR(255) NOT NULL,
    hash_solicitud      CHAR(64)     NOT NULL COMMENT 'Distingue reintento idéntico de reutilización indebida',
    estado              ENUM('en_curso','completada') NOT NULL DEFAULT 'en_curso',
    codigo_respuesta    SMALLINT UNSIGNED NULL,
    cabeceras_respuesta JSON NULL,
    cuerpo_respuesta    MEDIUMTEXT NULL,
    creado_en           DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    expira_en           DATETIME(3)  NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idempotencia_usuario_clave (usuario_id, clave),
    KEY idx_idempotencia_expira (expira_en),
    CONSTRAINT fk_idempotencia_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Respuestas por X-Idempotency-Key (RNF-010)';
