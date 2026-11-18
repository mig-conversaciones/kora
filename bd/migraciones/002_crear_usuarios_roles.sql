-- 002: identidad y autorización híbrida (RBAC con permisos recurso.accion y ABAC por unidades asignadas).
CREATE TABLE usuarios (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ulid                   CHAR(26)     NOT NULL,
    centro_id              INT UNSIGNED NULL COMMENT 'NULL solo para superadministradores',
    correo                 VARCHAR(190) NOT NULL,
    nombre                 VARCHAR(150) NOT NULL,
    hash_contrasena        VARCHAR(255) NULL COMMENT 'Argon2id; NULL hasta activar la invitación (RAC-002)',
    estado                 ENUM('invitado','activo','desactivado') NOT NULL DEFAULT 'invitado',
    totp_secreto_cifrado   VARCHAR(255) NULL COMMENT 'Sobre cifrado con versión de clave (RS-013)',
    totp_ultimo_paso       BIGINT UNSIGNED NULL COMMENT 'Último paso TOTP aceptado (RAC-012)',
    totp_activado_en       DATETIME(3)  NULL,
    preferencias           JSON         NULL COMMENT 'Tema, densidad y menú (RF-008)',
    version_permisos       INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Invalida la caché de permisos (RF-011)',
    contrasena_cambiada_en DATETIME(3)  NULL,
    ultimo_acceso_en       DATETIME(3)  NULL,
    anonimizado_en         DATETIME(3)  NULL COMMENT 'Solicitud del titular, Ley 1581 (RDL-007)',
    creado_en              DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actualizado_en         DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    creado_por             BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_ulid (ulid),
    UNIQUE KEY uq_usuarios_correo (correo),
    KEY idx_usuarios_centro_estado (centro_id, estado),
    CONSTRAINT fk_usuarios_centro FOREIGN KEY (centro_id) REFERENCES centros (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Cuentas de usuario';

CREATE TABLE roles (
    id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo       VARCHAR(40) NOT NULL,
    nombre       VARCHAR(80) NOT NULL,
    alcance      ENUM('global','centro','unidad') NOT NULL COMMENT 'Alcance de datos del rol',
    requiere_2fa TINYINT(1)  NOT NULL DEFAULT 0,
    privilegiado TINYINT(1)  NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_codigo (codigo)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Roles (RBAC)';

CREATE TABLE permisos (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo      VARCHAR(80)  NOT NULL COMMENT 'recurso.accion',
    descripcion VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permisos_codigo (codigo)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Permisos recurso.accion';

CREATE TABLE roles_permisos (
    rol_id     SMALLINT UNSIGNED NOT NULL,
    permiso_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (rol_id, permiso_id),
    CONSTRAINT fk_roles_permisos_rol FOREIGN KEY (rol_id) REFERENCES roles (id),
    CONSTRAINT fk_roles_permisos_permiso FOREIGN KEY (permiso_id) REFERENCES permisos (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE usuarios_roles (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id   BIGINT UNSIGNED NOT NULL,
    rol_id       SMALLINT UNSIGNED NOT NULL,
    centro_id    INT UNSIGNED NULL COMMENT 'NULL para el rol global de superadministrador',
    asignado_por BIGINT UNSIGNED NULL,
    asignado_en  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_roles (usuario_id, rol_id, centro_id),
    CONSTRAINT fk_usuarios_roles_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT fk_usuarios_roles_rol FOREIGN KEY (rol_id) REFERENCES roles (id),
    CONSTRAINT fk_usuarios_roles_centro FOREIGN KEY (centro_id) REFERENCES centros (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE usuarios_unidades (
    usuario_id   BIGINT UNSIGNED NOT NULL,
    unidad_id    INT UNSIGNED NOT NULL,
    asignado_por BIGINT UNSIGNED NULL,
    asignado_en  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (usuario_id, unidad_id),
    CONSTRAINT fk_usuarios_unidades_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT fk_usuarios_unidades_unidad FOREIGN KEY (unidad_id) REFERENCES unidades_productivas (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Unidades asignadas (ABAC)';
