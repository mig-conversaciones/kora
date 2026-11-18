-- 006: configuración del sistema (mantenimiento), versión de datos para la caché, notificaciones y adjuntos.
CREATE TABLE configuracion_sistema (
    clave           VARCHAR(80) NOT NULL COMMENT 'mantenimiento, funcionalidades.*',
    valor           JSON        NOT NULL,
    version         INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Control de concurrencia optimista (RF-MAN-005)',
    actualizado_por BIGINT UNSIGNED NULL,
    actualizado_en  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (clave)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE versiones_datos (
    centro_id      INT UNSIGNED NOT NULL,
    version        BIGINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Se incrementa en cada escritura relevante (ADR-010)',
    actualizado_en DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (centro_id),
    CONSTRAINT fk_versiones_centro FOREIGN KEY (centro_id) REFERENCES centros (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE notificaciones (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NOT NULL,
    tipo       VARCHAR(40)  NOT NULL,
    titulo     VARCHAR(150) NOT NULL,
    mensaje    VARCHAR(500) NOT NULL,
    enlace     VARCHAR(255) NULL,
    leida_en   DATETIME(3)  NULL,
    creado_en  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_notificaciones_usuario (usuario_id, leida_en, creado_en),
    CONSTRAINT fk_notificaciones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Centro de notificaciones (RF-005)';

CREATE TABLE archivos_adjuntos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id       INT UNSIGNED NOT NULL,
    unidad_id       INT UNSIGNED NULL,
    entidad         VARCHAR(40)  NOT NULL,
    entidad_id      BIGINT UNSIGNED NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    ruta_privada    VARCHAR(255) NOT NULL COMMENT 'Nombre aleatorio fuera de public (RS-011)',
    tipo_mime       VARCHAR(80)  NOT NULL,
    tamano          INT UNSIGNED NOT NULL,
    sha256          CHAR(64)     NOT NULL,
    creado_por      BIGINT UNSIGNED NOT NULL,
    creado_en       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    archivado_en    DATETIME(3)  NULL,
    PRIMARY KEY (id),
    KEY idx_adjuntos_entidad (entidad, entidad_id),
    CONSTRAINT fk_adjuntos_centro FOREIGN KEY (centro_id) REFERENCES centros (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Soportes documentales (RF-027)';
