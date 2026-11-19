-- 013: registro de copias de seguridad (RDL-003, RDL-004, RDL-005) y de despliegues (ROB-013).
CREATE TABLE respaldos (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identificador  VARCHAR(120) NOT NULL COMMENT 'kora-<tipo>-<AAAAMMDDTHHMMSSZ>-<azar>',
    tipo           ENUM('diaria','predespliegue','preimportacion','manual') NOT NULL,
    estado         ENUM('en_curso','completado','fallido') NOT NULL DEFAULT 'en_curso',
    motivo         VARCHAR(255) NULL,
    archivos       JSON NULL COMMENT 'Nombre, bytes y SHA-256 de cada archivo cifrado',
    bytes          BIGINT UNSIGNED NOT NULL DEFAULT 0,
    cifras_control JSON NULL COMMENT 'Conteos y sumas de control al momento de la copia (sin datos personales)',
    externo        TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Copia fuera de Hostinger confirmada',
    duracion_ms    INT UNSIGNED NULL,
    error          VARCHAR(500) NULL,
    creado_en      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    terminado_en   DATETIME(3) NULL,
    eliminado_en   DATETIME(3) NULL COMMENT 'Podado por la retención (30 diarias y 12 mensuales)',
    PRIMARY KEY (id),
    UNIQUE KEY uq_respaldos_identificador (identificador),
    KEY idx_respaldos_historial (tipo, estado, creado_en)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Registro de copias cifradas (RDL-003)';

CREATE TABLE despliegues (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version       VARCHAR(40)  NOT NULL,
    tipo          ENUM('despliegue','reversion') NOT NULL,
    desde_version VARCHAR(40)  NULL,
    commit_git    VARCHAR(40)  NULL,
    responsable   VARCHAR(120) NOT NULL,
    migraciones   JSON NULL COMMENT 'Migraciones aplicadas por este despliegue',
    respaldo      VARCHAR(120) NULL COMMENT 'Copia previa (RDL-004)',
    resultado     ENUM('exito','fallido') NOT NULL,
    duracion_s    INT UNSIGNED NULL,
    creado_en     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_despliegues_fecha (creado_en)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Registro de despliegues y reversiones (ROB-013)';
