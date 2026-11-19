-- 008: catálogo de productos (con derivados lácteos), homologación, categorías de gasto y parámetros.
CREATE TABLE productos (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id            INT UNSIGNED NOT NULL,
    codigo_sgac          VARCHAR(20)  NOT NULL,
    nombre               VARCHAR(200) NOT NULL,
    medida               ENUM('L','kg','ud','lb','cubeta') NOT NULL,
    clase                VARCHAR(150) NULL,
    codigo_familia       VARCHAR(10)  NULL,
    nombre_familia       VARCHAR(150) NULL,
    precio_minimo        DECIMAL(15,2) NULL,
    precio_maximo        DECIMAL(15,2) NULL,
    precio_venta         DECIMAL(15,2) NULL,
    unidad_sugerida_id   INT UNSIGNED NULL,
    categoria_rotacion   ENUM('fresco','animal','otro') NOT NULL DEFAULT 'fresco' COMMENT 'Meta de IND_12',
    es_derivado_lacteo   TINYINT(1)   NOT NULL DEFAULT 0,
    factor_leche         DECIMAL(9,4) NULL COMMENT 'Litros de leche equivalentes por unidad (S-12)',
    meta_rendimiento_min DECIMAL(9,4) NULL,
    meta_rendimiento_max DECIMAL(9,4) NULL,
    origen_tipo          ENUM('importacion','manual','integracion') NOT NULL DEFAULT 'manual',
    lote_importacion_id  BIGINT UNSIGNED NULL,
    origen_fila          INT UNSIGNED NULL,
    archivado_en         DATETIME(3)  NULL,
    version              INT UNSIGNED NOT NULL DEFAULT 1,
    creado_en            DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actualizado_en       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    creado_por           BIGINT UNSIGNED NULL,
    actualizado_por      BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_productos_codigo (centro_id, codigo_sgac),
    KEY idx_productos_derivados (centro_id, es_derivado_lacteo, archivado_en),
    CONSTRAINT fk_productos_centro FOREIGN KEY (centro_id) REFERENCES centros (id),
    CONSTRAINT fk_productos_unidad FOREIGN KEY (unidad_sugerida_id) REFERENCES unidades_productivas (id),
    CONSTRAINT fk_productos_lote FOREIGN KEY (lote_importacion_id) REFERENCES lotes_importacion (id),
    CONSTRAINT chk_productos_metas CHECK (meta_rendimiento_min IS NULL OR meta_rendimiento_max IS NULL OR meta_rendimiento_min <= meta_rendimiento_max),
    CONSTRAINT chk_productos_factor CHECK (factor_leche IS NULL OR factor_leche >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Catálogo de productos (RF-017, RF-018)';

CREATE TABLE homologaciones_producto (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id         INT UNSIGNED NOT NULL,
    texto_normalizado VARCHAR(200) NOT NULL COMMENT 'Sin tildes, minúsculas, espacios simples',
    producto_id       INT UNSIGNED NULL COMMENT 'NULL = concepto fuente sin homologar',
    origen            VARCHAR(40)  NOT NULL DEFAULT 'manual',
    creado_por        BIGINT UNSIGNED NULL,
    creado_en         DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_homologaciones_texto (centro_id, texto_normalizado),
    CONSTRAINT fk_homologaciones_centro FOREIGN KEY (centro_id) REFERENCES centros (id),
    CONSTRAINT fk_homologaciones_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Homologación de conceptos (RF-019)';

CREATE TABLE categorias_gasto (
    id                 SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id          INT UNSIGNED NOT NULL,
    codigo             VARCHAR(30)  NOT NULL,
    nombre             VARCHAR(80)  NOT NULL,
    linea_presupuestal ENUM('mantenimiento','alimentacion','materiales_formacion','otra') NOT NULL,
    incluye_ind17      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Solo Mantenimiento y Alimentación (S-14)',
    activa             TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categorias_codigo (centro_id, codigo),
    CONSTRAINT fk_categorias_centro FOREIGN KEY (centro_id) REFERENCES centros (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Categorías de gasto (RF-020, Obs. 1)';

CREATE TABLE parametros_indicadores (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id     INT UNSIGNED NOT NULL,
    clave         ENUM('meta_vacas_ordeno','precio_referencia_leche','costo_maximo_precio','rotacion_frescos','rotacion_animales','meta_aprovechamiento') NOT NULL,
    valor         DECIMAL(15,4) NOT NULL,
    vigente_desde DATE NOT NULL,
    creado_por    BIGINT UNSIGNED NULL,
    creado_en     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_parametros_vigencia (centro_id, clave, vigente_desde),
    CONSTRAINT fk_parametros_centro FOREIGN KEY (centro_id) REFERENCES centros (id),
    CONSTRAINT chk_parametros_valor CHECK (valor >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Metas y parámetros con historial (RF-021)';
