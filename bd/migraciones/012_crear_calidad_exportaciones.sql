-- 012: bandeja de calidad de datos y exportaciones con caducidad.
CREATE TABLE incidencias_calidad (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id           INT UNSIGNED NOT NULL,
    unidad_id           INT UNSIGNED NULL,
    tipo                ENUM('salida_sin_recepcion','autoconsumo_sin_proceso','recaudo_sin_clasificar','concepto_sin_homologar','fecha_inconsistente','iep_diferente','derivado_sin_factor') NOT NULL,
    entidad             VARCHAR(40)  NOT NULL,
    entidad_id          BIGINT UNSIGNED NOT NULL,
    descripcion         VARCHAR(500) NOT NULL,
    estado              ENUM('abierta','resuelta','aceptada') NOT NULL DEFAULT 'abierta',
    resolucion          VARCHAR(500) NULL,
    resuelto_por        BIGINT UNSIGNED NULL,
    resuelto_en         DATETIME(3)  NULL,
    detectada_en        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    lote_importacion_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_incidencias_entidad (entidad, entidad_id, tipo),
    KEY idx_incidencias_bandeja (centro_id, unidad_id, estado, tipo),
    CONSTRAINT fk_incidencias_centro FOREIGN KEY (centro_id) REFERENCES centros (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Bandeja de calidad (RF-058)';

CREATE TABLE exportaciones (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ulid          CHAR(26)     NOT NULL,
    centro_id     INT UNSIGNED NOT NULL,
    usuario_id    BIGINT UNSIGNED NOT NULL,
    tipo          VARCHAR(40)  NOT NULL,
    formato       ENUM('xlsx','csv','pdf') NOT NULL,
    filtros       JSON NOT NULL,
    trabajo_id    BIGINT UNSIGNED NULL,
    ruta_privada  VARCHAR(255) NULL,
    expira_en     DATETIME(3)  NULL COMMENT '24 h (RF-082)',
    descargado_en DATETIME(3)  NULL,
    creado_en     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_exportaciones_ulid (ulid),
    KEY idx_exportaciones_usuario (usuario_id, creado_en),
    CONSTRAINT fk_exportaciones_centro FOREIGN KEY (centro_id) REFERENCES centros (id),
    CONSTRAINT fk_exportaciones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT fk_exportaciones_trabajo FOREIGN KEY (trabajo_id) REFERENCES trabajos (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Exportaciones asíncronas (RF-080 a RF-082)';
