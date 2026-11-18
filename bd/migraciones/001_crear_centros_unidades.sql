-- 001: centros (tenant) y unidades productivas (alcance de fila).
CREATE TABLE centros (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(10)  NOT NULL COMMENT 'Código SENA del centro (9124)',
    nombre          VARCHAR(150) NOT NULL,
    regional        VARCHAR(80)  NOT NULL,
    codigo_regional VARCHAR(10)  NOT NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actualizado_en  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_centros_codigo (codigo)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Centros de formación (tenant, RDI-001)';

CREATE TABLE unidades_productivas (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id      INT UNSIGNED NOT NULL,
    codigo         VARCHAR(10)  NOT NULL COMMENT 'GAN, GRA, AGR, IND, GOU, INV, HOR, CAF, LAM, API, COM, SIN',
    nombre         VARCHAR(100) NOT NULL,
    prefijo_lote   VARCHAR(5)   NOT NULL COMMENT 'Prefijo del número interno de lote',
    tipo           ENUM('pecuaria','agricola','agroindustrial','servicios','tecnica') NOT NULL,
    activa         TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actualizado_en DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_unidades_codigo (centro_id, codigo),
    UNIQUE KEY uq_unidades_centro_id (centro_id, id),
    CONSTRAINT fk_unidades_centro FOREIGN KEY (centro_id) REFERENCES centros (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Unidades productivas (RF-016)';

CREATE TABLE destinos_internos (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id INT UNSIGNED NOT NULL,
    nombre    VARCHAR(120) NOT NULL COMMENT 'Agroindustria, Bienestar al Aprendiz, Agroambiental',
    unidad_id INT UNSIGNED NULL COMMENT 'Unidad receptora con visibilidad de lo recibido (RDI-007)',
    activo    TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_destinos_nombre (centro_id, nombre),
    CONSTRAINT fk_destinos_centro FOREIGN KEY (centro_id) REFERENCES centros (id),
    CONSTRAINT fk_destinos_unidad FOREIGN KEY (unidad_id) REFERENCES unidades_productivas (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Clientes internos del autoconsumo (RF-023)';
