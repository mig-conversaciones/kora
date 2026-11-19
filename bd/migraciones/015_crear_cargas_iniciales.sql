-- 015: carga inicial del piloto con cifras de control y acta firmada (RF-094, RDL-011, 4.8.2 ítem 20).
CREATE TABLE cargas_iniciales (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ulid           CHAR(26)     NOT NULL,
    centro_id      INT UNSIGNED NOT NULL,
    estado         ENUM('en_curso','con_diferencias','verificada','firmada','anulada') NOT NULL DEFAULT 'en_curso',
    vigencia       SMALLINT UNSIGNED NOT NULL,
    respaldo_previo VARCHAR(120) NULL COMMENT 'Copia cifrada tomada al iniciar (RDL-004)',
    resultado      JSON NULL COMMENT 'Última verificación: fuentes, lotes y cifras archivo frente a base (sin datos personales)',
    huella         CHAR(64)     NULL COMMENT 'SHA-256 del resultado verificado; el acta la muestra para detectar cambios',
    iniciada_por   BIGINT UNSIGNED NOT NULL,
    iniciada_en    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    verificada_en  DATETIME(3)  NULL,
    firmada_por    BIGINT UNSIGNED NULL,
    firmada_en     DATETIME(3)  NULL,
    observaciones  VARCHAR(1000) NULL,
    anulada_motivo VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cargas_iniciales_ulid (ulid),
    KEY idx_cargas_iniciales_centro (centro_id, estado),
    CONSTRAINT fk_cargas_iniciales_centro FOREIGN KEY (centro_id) REFERENCES centros (id),
    CONSTRAINT fk_cargas_iniciales_inicia FOREIGN KEY (iniciada_por) REFERENCES usuarios (id),
    CONSTRAINT fk_cargas_iniciales_firma FOREIGN KEY (firmada_por) REFERENCES usuarios (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Carga inicial guiada del piloto y su acta (RF-094)';
