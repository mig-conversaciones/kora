## Anexo B. Scripts de base de datos

**Convenciones de las migraciones:**

- **Formato.** Cada migración es un archivo numerado con su reversión `.down.sql`. El ejecutor `cli/migrar.php` separa las sentencias por `;` al final de la línea, registra el nombre y la suma de verificación en `migraciones_aplicadas` y rechaza volver a aplicar un archivo cuya suma haya cambiado (RBD-011).
- **Portabilidad.** Todo el SQL es compatible con MariaDB 10.6 o superior y con MySQL 8.0 o superior (RNF-016).
- **Columnas de control.** Las tablas de negocio repiten las columnas comunes de la sección 4.5.

| Orden | Archivo | Tablas |
|---|---|---|
| 000 | (lo crea el ejecutor) | `migraciones_aplicadas` |
| 001 | `001_crear_centros_unidades.sql` | `centros`, `unidades_productivas`, `destinos_internos` |
| 002 | `002_crear_usuarios_roles.sql` | `usuarios`, `roles`, `permisos`, `roles_permisos`, `usuarios_roles`, `usuarios_unidades` |
| 003 | `003_crear_seguridad_sesiones.sql` | `sesiones`, `tokens_cuenta`, `codigos_respaldo_2fa`, `limites_tasa`, `claves_idempotencia` |
| 004 | `004_crear_auditoria.sql` | `eventos_auditoria`, `cadena_auditoria` |
| 005 | `005_crear_cola_trabajos.sql` | `trabajos`, `trabajos_fallidos`, `estado_circuitos` (sección 4.4.2) |
| 006 | `006_crear_configuracion_notificaciones.sql` | `configuracion_sistema`, `versiones_datos`, `notificaciones`, `archivos_adjuntos` |
| 007 | `007_crear_importaciones.sql` | `lotes_importacion`, `filas_importacion` |
| 008 | `008_crear_catalogos.sql` | `productos`, `homologaciones_producto`, `categorias_gasto`, `parametros_indicadores` |
| 009 | `009_crear_finanzas.sql` | `asignaciones_presupuestales`, `gastos_ejecutados`, `procesos_autoconsumo`, `recaudos`, `periodos_cierre` |
| 010 | `010_crear_operacion.sql` | `recepciones_lote`, `salidas`, `transformaciones`, `conciliaciones_recaudo` |
| 011 | `011_crear_hato.sql` | `animales`, `cortes_hato`, `registros_reproductivos` |
| 012 | `012_crear_calidad_exportaciones.sql` | `incidencias_calidad`, `exportaciones` |
| 013 | `013_proteger_auditoria.sql` (opcional) | Disparadores de inmutabilidad, si el plan lo permite (D-33) |

```sql archivo=bd/migraciones/001_crear_centros_unidades.sql
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
```

```sql archivo=bd/migraciones/002_crear_usuarios_roles.sql
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
```

```sql archivo=bd/migraciones/003_crear_seguridad_sesiones.sql
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
```

```sql archivo=bd/migraciones/004_crear_auditoria.sql
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
```

```sql archivo=bd/migraciones/006_crear_configuracion_notificaciones.sql
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
```

```sql archivo=bd/migraciones/007_crear_importaciones.sql
-- 007: lotes de importación y filas de prueba (RF-083 a RF-094).
CREATE TABLE lotes_importacion (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ulid               CHAR(26)     NOT NULL,
    centro_id          INT UNSIGNED NOT NULL,
    fuente             ENUM('presupuesto','recaudos_dg','cupos_autoconsumo','catalogo_productos','base_produccion','base_ventas','estado_reproductivo','gastos_ejecutados') NOT NULL,
    nombre_archivo     VARCHAR(255) NOT NULL,
    ruta_privada       VARCHAR(255) NOT NULL,
    hash_archivo       CHAR(64)     NOT NULL COMMENT 'SHA-256 del archivo (idempotencia, RF-089)',
    tamano             INT UNSIGNED NOT NULL,
    estado             ENUM('cargado','validando','validado','con_errores','aplicando','aplicado','revirtiendo','revertido','fallido') NOT NULL DEFAULT 'cargado',
    filas_total        INT UNSIGNED NOT NULL DEFAULT 0,
    filas_validas      INT UNSIGNED NOT NULL DEFAULT 0,
    filas_error        INT UNSIGNED NOT NULL DEFAULT 0,
    filas_nuevas       INT UNSIGNED NOT NULL DEFAULT 0,
    filas_actualizadas INT UNSIGNED NOT NULL DEFAULT 0,
    filas_sin_cambios  INT UNSIGNED NOT NULL DEFAULT 0,
    resumen            JSON NULL,
    cifras_control     JSON NULL COMMENT 'Conteos y sumas comparados con el archivo (RF-094)',
    respaldo_previo    VARCHAR(120) NULL COMMENT 'Identificador de la copia previa (RDL-004)',
    trabajo_id         BIGINT UNSIGNED NULL,
    creado_por         BIGINT UNSIGNED NOT NULL,
    creado_en          DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    aplicado_por       BIGINT UNSIGNED NULL,
    aplicado_en        DATETIME(3)  NULL,
    revertido_por      BIGINT UNSIGNED NULL,
    revertido_en       DATETIME(3)  NULL,
    motivo_reversion   VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lotes_ulid (ulid),
    KEY idx_lotes_hash (centro_id, fuente, hash_archivo),
    KEY idx_lotes_historial (centro_id, creado_en),
    CONSTRAINT fk_lotes_centro FOREIGN KEY (centro_id) REFERENCES centros (id),
    CONSTRAINT fk_lotes_creador FOREIGN KEY (creado_por) REFERENCES usuarios (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Lotes de importación asistida';

CREATE TABLE filas_importacion (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lote_id            BIGINT UNSIGNED NOT NULL,
    hoja               VARCHAR(80)  NOT NULL,
    fila               INT UNSIGNED NOT NULL,
    clave_natural      VARCHAR(255) NULL,
    datos              JSON NOT NULL COMMENT 'Valores normalizados (sin PII en claro)',
    estado             ENUM('valida','error','aplicada','omitida') NOT NULL,
    accion             ENUM('crear','actualizar','sin_cambios') NULL,
    errores            JSON NULL COMMENT 'Lista de errores por columna',
    entidad            VARCHAR(40)  NULL,
    entidad_id         BIGINT UNSIGNED NULL,
    valores_anteriores JSON NULL COMMENT 'Para la reversión (RF-090)',
    PRIMARY KEY (id),
    KEY idx_filas_lote_estado (lote_id, estado),
    KEY idx_filas_lote_fila (lote_id, hoja, fila),
    CONSTRAINT fk_filas_lote FOREIGN KEY (lote_id) REFERENCES lotes_importacion (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
```

```sql archivo=bd/migraciones/008_crear_catalogos.sql
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
```

```sql archivo=bd/migraciones/009_crear_finanzas.sql
-- 009: presupuesto, gastos ejecutados, procesos de autoconsumo, recaudos y cierres de periodo.
CREATE TABLE asignaciones_presupuestales (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id            INT UNSIGNED NOT NULL,
    unidad_id            INT UNSIGNED NOT NULL,
    vigencia             SMALLINT UNSIGNED NOT NULL,
    valor_total          DECIMAL(15,2) NOT NULL,
    mantenimiento        DECIMAL(15,2) NOT NULL DEFAULT 0,
    alimentacion         DECIMAL(15,2) NOT NULL DEFAULT 0,
    materiales_formacion DECIMAL(15,2) NOT NULL DEFAULT 0,
    numero_semovientes   INT UNSIGNED NULL,
    activa_unica         TINYINT AS (IF(archivado_en IS NULL, 1, NULL)) STORED COMMENT 'Unicidad de la asignación activa',
    origen_tipo          ENUM('importacion','manual','integracion') NOT NULL DEFAULT 'manual',
    lote_importacion_id  BIGINT UNSIGNED NULL,
    origen_archivo       VARCHAR(255) NULL,
    origen_hoja          VARCHAR(80)  NULL,
    origen_fila          INT UNSIGNED NULL,
    archivado_en         DATETIME(3)  NULL,
    archivado_por        BIGINT UNSIGNED NULL,
    motivo_archivo       VARCHAR(500) NULL,
    version              INT UNSIGNED NOT NULL DEFAULT 1,
    creado_en            DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actualizado_en       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    creado_por           BIGINT UNSIGNED NULL,
    actualizado_por      BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_asignaciones_activa (centro_id, unidad_id, vigencia, activa_unica),
    CONSTRAINT fk_asignaciones_unidad FOREIGN KEY (centro_id, unidad_id) REFERENCES unidades_productivas (centro_id, id),
    CONSTRAINT fk_asignaciones_lote FOREIGN KEY (lote_importacion_id) REFERENCES lotes_importacion (id),
    CONSTRAINT chk_asignaciones_valores CHECK (valor_total >= 0 AND mantenimiento >= 0 AND alimentacion >= 0 AND materiales_formacion >= 0),
    CONSTRAINT chk_asignaciones_desglose CHECK (mantenimiento + alimentacion + materiales_formacion <= valor_total)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Asignaciones anuales (RF-024)';

CREATE TABLE gastos_ejecutados (
    id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id                  INT UNSIGNED NOT NULL,
    unidad_id                  INT UNSIGNED NOT NULL,
    fecha                      DATE NOT NULL,
    categoria_id               SMALLINT UNSIGNED NOT NULL,
    valor                      DECIMAL(15,2) NOT NULL,
    valor_componente_academico DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Vacas de práctica (IND_17)',
    descripcion                VARCHAR(2000) NULL,
    referencia_soporte         VARCHAR(120)  NULL,
    origen_tipo                ENUM('importacion','manual','integracion') NOT NULL DEFAULT 'manual',
    lote_importacion_id        BIGINT UNSIGNED NULL,
    origen_archivo             VARCHAR(255) NULL,
    origen_hoja                VARCHAR(80)  NULL,
    origen_fila                INT UNSIGNED NULL,
    archivado_en               DATETIME(3)  NULL,
    archivado_por              BIGINT UNSIGNED NULL,
    motivo_archivo             VARCHAR(500) NULL,
    version                    INT UNSIGNED NOT NULL DEFAULT 1,
    creado_en                  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actualizado_en             DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    creado_por                 BIGINT UNSIGNED NULL,
    actualizado_por            BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_gastos_listado (centro_id, unidad_id, archivado_en, fecha, categoria_id),
    CONSTRAINT fk_gastos_unidad FOREIGN KEY (centro_id, unidad_id) REFERENCES unidades_productivas (centro_id, id),
    CONSTRAINT fk_gastos_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_gasto (id),
    CONSTRAINT fk_gastos_lote FOREIGN KEY (lote_importacion_id) REFERENCES lotes_importacion (id),
    CONSTRAINT chk_gastos_valores CHECK (valor >= 0 AND valor_componente_academico >= 0 AND valor_componente_academico <= valor)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Gastos ejecutados (RF-025)';

CREATE TABLE procesos_autoconsumo (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id           INT UNSIGNED NOT NULL,
    unidad_id           INT UNSIGNED NOT NULL COMMENT 'Unidad de origen',
    codigo              VARCHAR(20)  NOT NULL COMMENT 'AUTO_001...',
    vigencia            SMALLINT UNSIGNED NOT NULL,
    destino_id          INT UNSIGNED NULL,
    destino_texto       VARCHAR(150) NULL,
    producto_id         INT UNSIGNED NULL,
    producto_texto      VARCHAR(200) NOT NULL,
    cupo_cantidad       DECIMAL(14,3) NOT NULL,
    medida              ENUM('L','kg','ud','lb','cubeta') NOT NULL,
    cupo_valor          DECIMAL(15,2) NOT NULL,
    origen_tipo         ENUM('importacion','manual','integracion') NOT NULL DEFAULT 'manual',
    lote_importacion_id BIGINT UNSIGNED NULL,
    origen_fila         INT UNSIGNED NULL,
    archivado_en        DATETIME(3)  NULL,
    archivado_por       BIGINT UNSIGNED NULL,
    motivo_archivo      VARCHAR(500) NULL,
    version             INT UNSIGNED NOT NULL DEFAULT 1,
    creado_en           DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actualizado_en      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    creado_por          BIGINT UNSIGNED NULL,
    actualizado_por     BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_procesos_codigo (centro_id, codigo, vigencia),
    CONSTRAINT fk_procesos_unidad FOREIGN KEY (centro_id, unidad_id) REFERENCES unidades_productivas (centro_id, id),
    CONSTRAINT fk_procesos_destino FOREIGN KEY (destino_id) REFERENCES destinos_internos (id),
    CONSTRAINT fk_procesos_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT fk_procesos_lote FOREIGN KEY (lote_importacion_id) REFERENCES lotes_importacion (id),
    CONSTRAINT chk_procesos_cupo CHECK (cupo_cantidad > 0 AND cupo_valor >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Cupos de autoconsumo (RF-036)';

CREATE TABLE recaudos (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id               INT UNSIGNED NOT NULL,
    unidad_id               INT UNSIGNED NULL COMMENT 'NULL hasta clasificar (RF-031)',
    producto_id             INT UNSIGNED NULL,
    concepto_fuente         VARCHAR(200) NULL,
    unidad_fuente           VARCHAR(100) NULL COMMENT 'Texto declarado en el archivo DG',
    fecha_pago              DATE NOT NULL,
    valor                   DECIMAL(15,2) NOT NULL,
    cupon_pago              VARCHAR(60)  NULL,
    prefactura              VARCHAR(40)  NULL,
    factura_electronica     VARCHAR(40)  NULL,
    pasarela                VARCHAR(30)  NULL COMMENT 'Wompi, Ecollect, QR',
    tipo_ingreso            VARCHAR(40)  NOT NULL COMMENT 'Solo Producción cuenta en IND_001',
    identificacion_hmac     CHAR(64)     NULL COMMENT 'HMAC-SHA256; nunca en claro (RF-034)',
    identificacion_ultimos4 CHAR(4)      NULL,
    estado_clasificacion    ENUM('pendiente','clasificado','ambiguo') NOT NULL DEFAULT 'pendiente',
    estado_conciliacion     ENUM('sin_conciliar','conciliado','con_diferencia') NOT NULL DEFAULT 'sin_conciliar',
    clave_natural           CHAR(64)     NOT NULL COMMENT 'SHA-256 de cupón, prefactura, factura, valor y fecha',
    origen_tipo             ENUM('importacion','manual','integracion') NOT NULL DEFAULT 'importacion',
    lote_importacion_id     BIGINT UNSIGNED NULL,
    origen_archivo          VARCHAR(255) NULL,
    origen_hoja             VARCHAR(80)  NULL,
    origen_fila             INT UNSIGNED NULL,
    archivado_en            DATETIME(3)  NULL,
    archivado_por           BIGINT UNSIGNED NULL,
    motivo_archivo          VARCHAR(500) NULL,
    version                 INT UNSIGNED NOT NULL DEFAULT 1,
    creado_en               DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actualizado_en          DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    creado_por              BIGINT UNSIGNED NULL,
    actualizado_por         BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recaudos_clave (centro_id, clave_natural),
    KEY idx_recaudos_tablero (centro_id, tipo_ingreso, archivado_en, fecha_pago, unidad_id, valor),
    KEY idx_recaudos_clasificacion (centro_id, estado_clasificacion, fecha_pago),
    KEY idx_recaudos_prefactura (centro_id, prefactura),
    KEY idx_recaudos_factura (centro_id, factura_electronica),
    CONSTRAINT fk_recaudos_centro FOREIGN KEY (centro_id) REFERENCES centros (id),
    CONSTRAINT fk_recaudos_unidad FOREIGN KEY (unidad_id) REFERENCES unidades_productivas (id),
    CONSTRAINT fk_recaudos_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT fk_recaudos_lote FOREIGN KEY (lote_importacion_id) REFERENCES lotes_importacion (id),
    CONSTRAINT chk_recaudos_valor CHECK (valor >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Recaudos de la Dirección General (RF-029 a RF-035)';

CREATE TABLE periodos_cierre (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id         INT UNSIGNED NOT NULL,
    anio              SMALLINT UNSIGNED NOT NULL,
    mes               TINYINT UNSIGNED NOT NULL,
    estado            ENUM('cerrado','reabierto') NOT NULL DEFAULT 'cerrado',
    cerrado_por       BIGINT UNSIGNED NOT NULL,
    cerrado_en        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    reabierto_por     BIGINT UNSIGNED NULL,
    reabierto_en      DATETIME(3) NULL,
    motivo_reapertura VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_periodos_mes (centro_id, anio, mes),
    CONSTRAINT fk_periodos_centro FOREIGN KEY (centro_id) REFERENCES centros (id),
    CONSTRAINT chk_periodos_mes CHECK (mes BETWEEN 1 AND 12)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Cierres mensuales (RF-028)';
```

```sql archivo=bd/migraciones/010_crear_operacion.sql
-- 010: recepciones de lotes, salidas, transformaciones lácteas y conciliaciones de recaudo.
CREATE TABLE recepciones_lote (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id           INT UNSIGNED NOT NULL,
    unidad_id           INT UNSIGNED NOT NULL,
    numero_interno      VARCHAR(40)  NOT NULL COMMENT 'GAN-REC-210126-001',
    tipo_movimiento     ENUM('REC','DEV') NOT NULL DEFAULT 'REC' COMMENT 'DEV reservado (RF-042)',
    fecha_recepcion     DATE NOT NULL,
    producto_id         INT UNSIGNED NOT NULL,
    cantidad            DECIMAL(14,3) NOT NULL,
    medida              ENUM('L','kg','ud','lb','cubeta') NOT NULL,
    precio_sugerido     DECIMAL(15,2) NULL,
    responsable_id      BIGINT UNSIGNED NULL,
    responsable_texto   VARCHAR(150) NULL COMMENT 'Nombre histórico importado',
    origen_tipo         ENUM('importacion','manual','integracion') NOT NULL DEFAULT 'manual',
    lote_importacion_id BIGINT UNSIGNED NULL,
    origen_archivo      VARCHAR(255) NULL,
    origen_hoja         VARCHAR(80)  NULL,
    origen_fila         INT UNSIGNED NULL,
    archivado_en        DATETIME(3)  NULL,
    archivado_por       BIGINT UNSIGNED NULL,
    motivo_archivo      VARCHAR(500) NULL,
    version             INT UNSIGNED NOT NULL DEFAULT 1,
    creado_en           DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actualizado_en      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    creado_por          BIGINT UNSIGNED NULL,
    actualizado_por     BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recepciones_lote_producto (centro_id, numero_interno, producto_id),
    UNIQUE KEY uq_recepciones_centro_id (centro_id, id),
    KEY idx_recepciones_tablero (centro_id, unidad_id, archivado_en, fecha_recepcion),
    KEY idx_recepciones_producto (centro_id, unidad_id, producto_id, fecha_recepcion),
    CONSTRAINT fk_recepciones_unidad FOREIGN KEY (centro_id, unidad_id) REFERENCES unidades_productivas (centro_id, id),
    CONSTRAINT fk_recepciones_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT fk_recepciones_responsable FOREIGN KEY (responsable_id) REFERENCES usuarios (id),
    CONSTRAINT fk_recepciones_lote FOREIGN KEY (lote_importacion_id) REFERENCES lotes_importacion (id),
    CONSTRAINT chk_recepciones_cantidad CHECK (cantidad > 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Recepciones de lotes (RF-039)';

CREATE TABLE salidas (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id              INT UNSIGNED NOT NULL,
    unidad_id              INT UNSIGNED NOT NULL,
    recepcion_id           BIGINT UNSIGNED NULL COMMENT 'NULL en salidas heredadas sin vínculo (RF-045)',
    numero_interno_texto   VARCHAR(40)  NULL COMMENT 'Num_Interno_Compra de la fuente',
    fecha                  DATE NOT NULL,
    producto_id            INT UNSIGNED NULL,
    nombre_producto_fuente VARCHAR(200) NULL,
    cantidad               DECIMAL(14,3) NOT NULL,
    medida                 ENUM('L','kg','ud','lb','cubeta') NOT NULL,
    precio_unitario        DECIMAL(15,2) NOT NULL,
    valor                  DECIMAL(15,2) NOT NULL COMMENT 'cantidad x precio, calculado en la aplicación (RBD-015)',
    canal                  ENUM('VENTA_DIRECTA','AUTOCONSUMO') NOT NULL,
    proceso_id             BIGINT UNSIGNED NULL,
    destino                VARCHAR(150) NULL,
    factura                VARCHAR(40)  NULL,
    referencia_transaccion VARCHAR(80)  NULL,
    id_externo             VARCHAR(80)  NULL COMMENT 'Identificador del software de ventas (RF-097)',
    clave_natural          CHAR(64)     NOT NULL,
    origen_tipo            ENUM('importacion','manual','integracion') NOT NULL DEFAULT 'manual',
    lote_importacion_id    BIGINT UNSIGNED NULL,
    origen_archivo         VARCHAR(255) NULL,
    origen_hoja            VARCHAR(80)  NULL,
    origen_fila            INT UNSIGNED NULL,
    archivado_en           DATETIME(3)  NULL,
    archivado_por          BIGINT UNSIGNED NULL,
    motivo_archivo         VARCHAR(500) NULL,
    version                INT UNSIGNED NOT NULL DEFAULT 1,
    creado_en              DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actualizado_en         DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    creado_por             BIGINT UNSIGNED NULL,
    actualizado_por        BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_salidas_clave (centro_id, clave_natural),
    UNIQUE KEY uq_salidas_externo (centro_id, id_externo),
    KEY idx_salidas_tablero (centro_id, canal, archivado_en, fecha, unidad_id, valor),
    KEY idx_salidas_recepcion (recepcion_id, fecha),
    KEY idx_salidas_proceso (proceso_id, fecha),
    KEY idx_salidas_factura (centro_id, factura),
    CONSTRAINT fk_salidas_unidad FOREIGN KEY (centro_id, unidad_id) REFERENCES unidades_productivas (centro_id, id),
    CONSTRAINT fk_salidas_recepcion FOREIGN KEY (centro_id, recepcion_id) REFERENCES recepciones_lote (centro_id, id),
    CONSTRAINT fk_salidas_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT fk_salidas_proceso FOREIGN KEY (proceso_id) REFERENCES procesos_autoconsumo (id),
    CONSTRAINT fk_salidas_lote FOREIGN KEY (lote_importacion_id) REFERENCES lotes_importacion (id),
    CONSTRAINT chk_salidas_valores CHECK (cantidad > 0 AND precio_unitario >= 0 AND valor >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Salidas: venta directa y autoconsumo (RF-043)';

CREATE TABLE transformaciones (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id             INT UNSIGNED NOT NULL,
    unidad_id             INT UNSIGNED NOT NULL COMMENT 'Unidad de origen de la leche',
    fecha                 DATE NOT NULL,
    salida_leche_id       BIGINT UNSIGNED NOT NULL,
    recepcion_derivado_id BIGINT UNSIGNED NOT NULL,
    litros_asignados      DECIMAL(14,3) NOT NULL,
    origen_tipo           ENUM('importacion','manual','integracion') NOT NULL DEFAULT 'manual',
    lote_importacion_id   BIGINT UNSIGNED NULL,
    archivado_en          DATETIME(3)  NULL,
    archivado_por         BIGINT UNSIGNED NULL,
    motivo_archivo        VARCHAR(500) NULL,
    version               INT UNSIGNED NOT NULL DEFAULT 1,
    creado_en             DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actualizado_en        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    creado_por            BIGINT UNSIGNED NULL,
    actualizado_por       BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_transformaciones_derivado (recepcion_derivado_id),
    KEY idx_transformaciones_salida (salida_leche_id),
    KEY idx_transformaciones_tablero (centro_id, unidad_id, archivado_en, fecha),
    CONSTRAINT fk_transformaciones_unidad FOREIGN KEY (centro_id, unidad_id) REFERENCES unidades_productivas (centro_id, id),
    CONSTRAINT fk_transformaciones_salida FOREIGN KEY (salida_leche_id) REFERENCES salidas (id),
    CONSTRAINT fk_transformaciones_derivado FOREIGN KEY (centro_id, recepcion_derivado_id) REFERENCES recepciones_lote (centro_id, id),
    CONSTRAINT chk_transformaciones_litros CHECK (litros_asignados > 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Transformación láctea (RF-047)';

CREATE TABLE conciliaciones_recaudo (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id    INT UNSIGNED NOT NULL,
    recaudo_id   BIGINT UNSIGNED NOT NULL,
    salida_id    BIGINT UNSIGNED NOT NULL,
    criterio     ENUM('prefactura','factura','referencia','manual') NOT NULL,
    diferencia   DECIMAL(15,2) NOT NULL DEFAULT 0,
    creado_por   BIGINT UNSIGNED NOT NULL,
    creado_en    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    archivado_en DATETIME(3) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_conciliaciones (recaudo_id, salida_id),
    CONSTRAINT fk_conciliaciones_centro FOREIGN KEY (centro_id) REFERENCES centros (id),
    CONSTRAINT fk_conciliaciones_recaudo FOREIGN KEY (recaudo_id) REFERENCES recaudos (id),
    CONSTRAINT fk_conciliaciones_salida FOREIGN KEY (salida_id) REFERENCES salidas (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Conciliación recaudo-venta (RF-032)';
```

```sql archivo=bd/migraciones/011_crear_hato.sql
-- 011: inventario del hato por cortes fechados (RF-052 a RF-057).
CREATE TABLE animales (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id           INT UNSIGNED NOT NULL,
    unidad_id           INT UNSIGNED NOT NULL,
    identificador       VARCHAR(30)  NOT NULL COMMENT 'Número del animal (158, 285...)',
    fecha_nacimiento    DATE NULL,
    activo              TINYINT(1)   NOT NULL DEFAULT 1,
    origen_tipo         ENUM('importacion','manual','integracion') NOT NULL DEFAULT 'manual',
    lote_importacion_id BIGINT UNSIGNED NULL,
    archivado_en        DATETIME(3)  NULL,
    version             INT UNSIGNED NOT NULL DEFAULT 1,
    creado_en           DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actualizado_en      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    creado_por          BIGINT UNSIGNED NULL,
    actualizado_por     BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_animales_identificador (centro_id, unidad_id, identificador),
    CONSTRAINT fk_animales_unidad FOREIGN KEY (centro_id, unidad_id) REFERENCES unidades_productivas (centro_id, id),
    CONSTRAINT fk_animales_lote FOREIGN KEY (lote_importacion_id) REFERENCES lotes_importacion (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Hembras del hato';

CREATE TABLE cortes_hato (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id           INT UNSIGNED NOT NULL,
    unidad_id           INT UNSIGNED NOT NULL,
    fecha_corte         DATE NOT NULL,
    vigente             TINYINT(1) NOT NULL DEFAULT 0,
    lote_importacion_id BIGINT UNSIGNED NULL,
    creado_por          BIGINT UNSIGNED NULL,
    creado_en           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cortes_fecha (centro_id, unidad_id, fecha_corte),
    CONSTRAINT fk_cortes_unidad FOREIGN KEY (centro_id, unidad_id) REFERENCES unidades_productivas (centro_id, id),
    CONSTRAINT fk_cortes_lote FOREIGN KEY (lote_importacion_id) REFERENCES lotes_importacion (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Fotografías fechadas del hato (RF-053)';

CREATE TABLE registros_reproductivos (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centro_id                INT UNSIGNED NOT NULL,
    unidad_id                INT UNSIGNED NOT NULL,
    corte_id                 BIGINT UNSIGNED NOT NULL,
    animal_id                BIGINT UNSIGNED NOT NULL,
    lote_hato                ENUM('PRODUCCION','HORRA','PRACTICA') NOT NULL,
    penultimo_parto          DATE NULL,
    ultimo_parto             DATE NULL,
    iep_dias                 INT NULL COMMENT 'Calculado: último - penúltimo parto',
    iep_importado            INT NULL,
    servicios_posparto       SMALLINT UNSIGNED NULL,
    servicio_efectivo        DATE NULL,
    ultima_palpacion         DATE NULL,
    estado_reproductivo      VARCHAR(30) NULL COMMENT 'PREÑADA, VACÍA... normalizado',
    edad_gestacion           VARCHAR(30) NULL,
    dias_abiertos            INT NULL COMMENT 'Calculado (RF-054)',
    dias_abiertos_importado  INT NULL,
    observaciones_academicas VARCHAR(500) NULL,
    origen_fila              INT UNSIGNED NULL,
    version                  INT UNSIGNED NOT NULL DEFAULT 1,
    creado_en                DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actualizado_en           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    creado_por               BIGINT UNSIGNED NULL,
    actualizado_por          BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_registro_corte_animal (corte_id, animal_id),
    KEY idx_registros_lote (centro_id, unidad_id, corte_id, lote_hato),
    CONSTRAINT fk_registros_corte FOREIGN KEY (corte_id) REFERENCES cortes_hato (id),
    CONSTRAINT fk_registros_animal FOREIGN KEY (animal_id) REFERENCES animales (id),
    CONSTRAINT fk_registros_unidad FOREIGN KEY (centro_id, unidad_id) REFERENCES unidades_productivas (centro_id, id),
    CONSTRAINT chk_registros_partos CHECK (penultimo_parto IS NULL OR ultimo_parto IS NULL OR penultimo_parto < ultimo_parto)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Estado reproductivo por corte';
```

```sql archivo=bd/migraciones/012_crear_calidad_exportaciones.sql
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
```

```sql archivo=bd/migraciones/013_proteger_auditoria.sql
-- 013 (opcional, D-33): inmutabilidad de la auditoría en el motor. Aplicar solo si la verificación de la I0
-- confirma que el plan permite crear disparadores. Cada disparador tiene un cuerpo de una sola sentencia,
-- compatible con el ejecutor que separa por punto y coma al final de la línea.
CREATE TRIGGER trg_auditoria_sin_borrado BEFORE DELETE ON eventos_auditoria FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La auditoría es de solo inserción';

-- En UPDATE solo se permite completar el sello: el contenido del evento conserva sus valores originales.
CREATE TRIGGER trg_auditoria_contenido_inmutable BEFORE UPDATE ON eventos_auditoria FOR EACH ROW
    SET NEW.evento_id = OLD.evento_id, NEW.ocurrido_en = OLD.ocurrido_en, NEW.request_id = OLD.request_id,
        NEW.actor_id = OLD.actor_id, NEW.actor_rol = OLD.actor_rol, NEW.centro_id = OLD.centro_id,
        NEW.unidad_id = OLD.unidad_id, NEW.accion = OLD.accion, NEW.recurso = OLD.recurso,
        NEW.recurso_id = OLD.recurso_id, NEW.resultado = OLD.resultado, NEW.origen = OLD.origen,
        NEW.ip_tratada = OLD.ip_tratada, NEW.ip_hmac = OLD.ip_hmac, NEW.agente = OLD.agente,
        NEW.valores_antes = OLD.valores_antes, NEW.valores_despues = OLD.valores_despues,
        NEW.motivo = OLD.motivo, NEW.metadatos = OLD.metadatos;
```

**Reversiones.** Cada archivo `NNN_*.down.sql` elimina, en orden inverso, lo creado por su migración. En producción, antes de revertir una migración con datos, se exporta lo afectado y se hace una copia (RDL-004).

```sql
-- 013_proteger_auditoria.down.sql
DROP TRIGGER IF EXISTS trg_auditoria_contenido_inmutable;
DROP TRIGGER IF EXISTS trg_auditoria_sin_borrado;
-- 012_crear_calidad_exportaciones.down.sql
DROP TABLE IF EXISTS exportaciones;
DROP TABLE IF EXISTS incidencias_calidad;
-- 011_crear_hato.down.sql
DROP TABLE IF EXISTS registros_reproductivos;
DROP TABLE IF EXISTS cortes_hato;
DROP TABLE IF EXISTS animales;
-- 010_crear_operacion.down.sql
DROP TABLE IF EXISTS conciliaciones_recaudo;
DROP TABLE IF EXISTS transformaciones;
DROP TABLE IF EXISTS salidas;
DROP TABLE IF EXISTS recepciones_lote;
-- 009_crear_finanzas.down.sql
DROP TABLE IF EXISTS periodos_cierre;
DROP TABLE IF EXISTS recaudos;
DROP TABLE IF EXISTS procesos_autoconsumo;
DROP TABLE IF EXISTS gastos_ejecutados;
DROP TABLE IF EXISTS asignaciones_presupuestales;
-- 008_crear_catalogos.down.sql
DROP TABLE IF EXISTS parametros_indicadores;
DROP TABLE IF EXISTS categorias_gasto;
DROP TABLE IF EXISTS homologaciones_producto;
DROP TABLE IF EXISTS productos;
-- 007_crear_importaciones.down.sql
DROP TABLE IF EXISTS filas_importacion;
DROP TABLE IF EXISTS lotes_importacion;
-- 006_crear_configuracion_notificaciones.down.sql
DROP TABLE IF EXISTS archivos_adjuntos;
DROP TABLE IF EXISTS notificaciones;
DROP TABLE IF EXISTS versiones_datos;
DROP TABLE IF EXISTS configuracion_sistema;
-- 004_crear_auditoria.down.sql (solo en entornos sin auditoría que conservar)
DROP TABLE IF EXISTS cadena_auditoria;
DROP TABLE IF EXISTS eventos_auditoria;
-- 003_crear_seguridad_sesiones.down.sql
DROP TABLE IF EXISTS claves_idempotencia;
DROP TABLE IF EXISTS limites_tasa;
DROP TABLE IF EXISTS codigos_respaldo_2fa;
DROP TABLE IF EXISTS tokens_cuenta;
DROP TABLE IF EXISTS sesiones;
-- 002_crear_usuarios_roles.down.sql
DROP TABLE IF EXISTS usuarios_unidades;
DROP TABLE IF EXISTS usuarios_roles;
DROP TABLE IF EXISTS roles_permisos;
DROP TABLE IF EXISTS permisos;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS usuarios;
-- 001_crear_centros_unidades.down.sql
DROP TABLE IF EXISTS destinos_internos;
DROP TABLE IF EXISTS unidades_productivas;
DROP TABLE IF EXISTS centros;
```

### B.1 Semilla mínima de producción

Solo contiene datos de configuración: el centro, las unidades, los roles, los permisos, las categorías de gasto, los parámetros, los destinos y el estado inicial del sistema. **No incluye datos de negocio ni usuarios** (RDL-008). El primer superadministrador se crea con `php cli/crear_superadministrador.php --correo=...`, que envía una invitación.

```sql archivo=bd/semillas/seed_production.sql
-- Semilla mínima de producción (RDL-008). Idempotente: usa INSERT IGNORE sobre claves únicas.
INSERT IGNORE INTO centros (codigo, nombre, regional, codigo_regional)
VALUES ('9124', 'Centro Agropecuario de Buga', 'Valle', '76');

SET @centro = (SELECT id FROM centros WHERE codigo = '9124');

INSERT IGNORE INTO unidades_productivas (centro_id, codigo, nombre, prefijo_lote, tipo) VALUES
    (@centro, 'GAN', 'Ganadería', 'GAN', 'pecuaria'),
    (@centro, 'GRA', 'Granja', 'GRA', 'pecuaria'),
    (@centro, 'API', 'Apicultura', 'API', 'pecuaria'),
    (@centro, 'AGR', 'Agrícola', 'AGR', 'agricola'),
    (@centro, 'HOR', 'Hortalizas', 'HOR', 'agricola'),
    (@centro, 'INV', 'Invernadero', 'INV', 'agricola'),
    (@centro, 'IND', 'Agroindustria', 'IND', 'agroindustrial'),
    (@centro, 'GOU', 'Gourmet - Café', 'GOU', 'agroindustrial'),
    (@centro, 'CAF', 'Cafetería Aprendices', 'CAF', 'servicios'),
    (@centro, 'LAM', 'Lamfa', 'LAM', 'servicios'),
    (@centro, 'COM', 'Comercio', 'COM', 'servicios'),
    (@centro, 'SIN', 'Sin clasificar (solo heredados)', 'SIN', 'tecnica');

INSERT IGNORE INTO destinos_internos (centro_id, nombre, unidad_id) VALUES
    (@centro, 'Agroindustria', (SELECT id FROM unidades_productivas WHERE centro_id = @centro AND codigo = 'IND')),
    (@centro, 'Bienestar al Aprendiz', NULL),
    (@centro, 'Agroambiental', NULL);

INSERT IGNORE INTO roles (codigo, nombre, alcance, requiere_2fa, privilegiado) VALUES
    ('superadministrador', 'Superadministrador', 'global', 1, 1),
    ('administrador', 'Administrador funcional', 'centro', 1, 1),
    ('coordinacion_administrativa', 'Coordinación administrativa', 'centro', 0, 0),
    ('grupo_recaudo', 'Grupo de Recaudo', 'centro', 0, 0),
    ('lider_produccion_centro', 'Líder de producción de centro', 'centro', 0, 0),
    ('lider_unidad', 'Líder de unidad productiva', 'unidad', 0, 0),
    ('coordinacion_academica', 'Coordinación académica', 'centro', 0, 0);

INSERT IGNORE INTO permisos (codigo, descripcion) VALUES
    ('tablero_central.consultar', 'Consultar el tablero central'),
    ('tablero_unidad.consultar', 'Consultar el tablero técnico de unidad'),
    ('asignaciones.consultar', 'Consultar asignaciones presupuestales'),
    ('asignaciones.gestionar', 'Gestionar asignaciones presupuestales'),
    ('asignaciones.importar', 'Importar presupuesto'),
    ('gastos.consultar', 'Consultar gastos ejecutados'),
    ('gastos.gestionar', 'Gestionar gastos ejecutados'),
    ('gastos.importar', 'Importar gastos ejecutados'),
    ('periodos.cerrar', 'Cerrar y reabrir periodos'),
    ('recaudos.consultar', 'Consultar recaudos'),
    ('recaudos.gestionar', 'Registrar y editar recaudos'),
    ('recaudos.importar', 'Importar el archivo de recaudos de la DG'),
    ('recaudos.clasificar', 'Clasificar recaudos'),
    ('recaudos.conciliar', 'Conciliar recaudos con ventas'),
    ('procesos.consultar', 'Consultar procesos de autoconsumo'),
    ('procesos.gestionar', 'Gestionar procesos de autoconsumo'),
    ('procesos.importar', 'Importar cupos de autoconsumo'),
    ('recepciones.consultar', 'Consultar recepciones'),
    ('recepciones.gestionar', 'Gestionar recepciones'),
    ('recepciones.importar', 'Importar base de producción'),
    ('salidas.consultar', 'Consultar salidas'),
    ('salidas.gestionar', 'Gestionar salidas'),
    ('salidas.importar', 'Importar base de ventas'),
    ('transformaciones.consultar', 'Consultar transformaciones'),
    ('transformaciones.gestionar', 'Gestionar transformaciones'),
    ('hato.consultar', 'Consultar el hato'),
    ('hato.gestionar', 'Gestionar el hato'),
    ('hato.gestionar_practica', 'Gestionar el lote de práctica académica'),
    ('hato.importar', 'Importar estado reproductivo'),
    ('catalogo.consultar', 'Consultar catálogos'),
    ('catalogo.gestionar', 'Gestionar catálogos'),
    ('catalogo.importar', 'Importar catálogo de productos'),
    ('catalogo.gestionar_factores', 'Gestionar factores de derivados lácteos'),
    ('parametros.consultar', 'Consultar parámetros'),
    ('parametros.gestionar', 'Gestionar parámetros e indicadores'),
    ('calidad.consultar', 'Consultar la bandeja de calidad'),
    ('calidad.gestionar', 'Corregir incidencias de calidad'),
    ('exportaciones.solicitar', 'Solicitar exportaciones'),
    ('trabajos.consultar_propios', 'Consultar trabajos propios'),
    ('usuarios.gestionar', 'Gestionar usuarios del centro'),
    ('roles.gestionar', 'Gestionar roles y permisos'),
    ('auditoria.consultar', 'Consultar la auditoría'),
    ('auditoria.exportar', 'Exportar la auditoría'),
    ('sistema.salud', 'Consultar la salud del sistema'),
    ('sistema.colas', 'Gestionar colas de trabajos'),
    ('sistema.funcionalidades', 'Gestionar funcionalidades configurables'),
    ('mantenimiento.gestionar', 'Activar y desactivar el modo mantenimiento'),
    ('mantenimiento.acceso_excepcional', 'Acceso autorizado durante el mantenimiento'),
    ('soporte.vista_usuario', 'Vista de soporte de solo lectura');

-- Asignación de permisos según la matriz de autorización (3.10, RAC-025).
INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'usuarios.gestionar', 'roles.gestionar', 'auditoria.consultar', 'auditoria.exportar', 'sistema.salud',
    'sistema.colas', 'sistema.funcionalidades', 'mantenimiento.gestionar', 'mantenimiento.acceso_excepcional',
    'soporte.vista_usuario', 'trabajos.consultar_propios')
WHERE r.codigo = 'superadministrador';

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'usuarios.gestionar', 'tablero_central.consultar', 'tablero_unidad.consultar', 'asignaciones.consultar',
    'gastos.consultar', 'recaudos.consultar', 'procesos.consultar', 'recepciones.consultar', 'salidas.consultar',
    'transformaciones.consultar', 'hato.consultar', 'catalogo.consultar', 'catalogo.gestionar', 'catalogo.importar',
    'catalogo.gestionar_factores', 'parametros.consultar', 'parametros.gestionar', 'calidad.consultar',
    'sistema.salud', 'exportaciones.solicitar', 'trabajos.consultar_propios')
WHERE r.codigo = 'administrador';

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'tablero_central.consultar', 'tablero_unidad.consultar', 'asignaciones.consultar', 'asignaciones.gestionar',
    'asignaciones.importar', 'gastos.consultar', 'gastos.gestionar', 'gastos.importar', 'periodos.cerrar',
    'recaudos.consultar', 'procesos.consultar', 'procesos.gestionar', 'procesos.importar', 'recepciones.consultar',
    'salidas.consultar', 'transformaciones.consultar', 'catalogo.consultar', 'parametros.consultar',
    'calidad.consultar', 'exportaciones.solicitar', 'trabajos.consultar_propios')
WHERE r.codigo = 'coordinacion_administrativa';

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'tablero_central.consultar', 'tablero_unidad.consultar', 'asignaciones.consultar', 'recaudos.consultar',
    'recaudos.gestionar', 'recaudos.importar', 'recaudos.clasificar', 'recaudos.conciliar', 'recepciones.consultar',
    'salidas.consultar', 'catalogo.consultar', 'calidad.consultar', 'calidad.gestionar', 'exportaciones.solicitar',
    'trabajos.consultar_propios')
WHERE r.codigo = 'grupo_recaudo';

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'tablero_central.consultar', 'tablero_unidad.consultar', 'asignaciones.consultar', 'gastos.consultar',
    'recaudos.consultar', 'procesos.consultar', 'recepciones.consultar', 'recepciones.gestionar',
    'recepciones.importar', 'salidas.consultar', 'salidas.gestionar', 'salidas.importar',
    'transformaciones.consultar', 'transformaciones.gestionar', 'hato.consultar', 'catalogo.consultar',
    'catalogo.gestionar', 'catalogo.importar', 'parametros.consultar', 'calidad.consultar', 'calidad.gestionar',
    'exportaciones.solicitar', 'trabajos.consultar_propios')
WHERE r.codigo = 'lider_produccion_centro';

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'tablero_central.consultar', 'tablero_unidad.consultar', 'asignaciones.consultar', 'gastos.consultar',
    'gastos.gestionar', 'recaudos.consultar', 'procesos.consultar', 'recepciones.consultar', 'recepciones.gestionar',
    'recepciones.importar', 'salidas.consultar', 'salidas.gestionar', 'salidas.importar', 'transformaciones.consultar',
    'transformaciones.gestionar', 'hato.consultar', 'hato.gestionar', 'hato.importar', 'catalogo.consultar',
    'parametros.consultar', 'calidad.consultar', 'calidad.gestionar', 'exportaciones.solicitar',
    'trabajos.consultar_propios')
WHERE r.codigo = 'lider_unidad';

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'tablero_central.consultar', 'tablero_unidad.consultar', 'asignaciones.consultar', 'gastos.consultar',
    'procesos.consultar', 'recepciones.consultar', 'salidas.consultar', 'transformaciones.consultar',
    'transformaciones.gestionar', 'hato.consultar', 'hato.gestionar_practica', 'hato.importar', 'catalogo.consultar',
    'catalogo.gestionar_factores', 'parametros.consultar', 'calidad.consultar', 'exportaciones.solicitar',
    'trabajos.consultar_propios')
WHERE r.codigo = 'coordinacion_academica';

INSERT IGNORE INTO categorias_gasto (centro_id, codigo, nombre, linea_presupuestal, incluye_ind17) VALUES
    (@centro, 'MANTENIMIENTO', 'Mantenimiento', 'mantenimiento', 1),
    (@centro, 'ALIMENTACION', 'Alimentación y vacunas', 'alimentacion', 1),
    (@centro, 'MATERIALES_FORMACION', 'Materiales de formación', 'materiales_formacion', 0);

INSERT IGNORE INTO parametros_indicadores (centro_id, clave, valor, vigente_desde) VALUES
    (@centro, 'meta_vacas_ordeno', 75, '2026-01-01'),
    (@centro, 'precio_referencia_leche', 2200, '2026-01-01'),
    (@centro, 'costo_maximo_precio', 75, '2026-01-01'),
    (@centro, 'rotacion_frescos', 15, '2026-01-01'),
    (@centro, 'rotacion_animales', 30, '2026-01-01'),
    (@centro, 'meta_aprovechamiento', 95, '2026-01-01');

INSERT IGNORE INTO configuracion_sistema (clave, valor) VALUES
    ('mantenimiento', '{"activo": false, "titulo": null, "mensaje": null, "estimado_hasta": null}');

INSERT IGNORE INTO versiones_datos (centro_id, version) VALUES (@centro, 1);
```

```sql archivo=bd/semillas/derivados_lacteos.sql
-- Configuración inicial de derivados lácteos (RF-018, S-12, Obs. 6). Se ejecuta después de importar el catálogo.
-- Factor inicial = límite inferior del rango de la guía (PENDIENTE DE VALIDACIÓN con el Taller de Lácteos).
SET @centro = (SELECT id FROM centros WHERE codigo = '9124');
UPDATE productos SET es_derivado_lacteo = 1, factor_leche = 8.0000, meta_rendimiento_min = 8.0000, meta_rendimiento_max = 8.5000
 WHERE centro_id = @centro AND LOWER(nombre) = 'queso cuajada';
UPDATE productos SET es_derivado_lacteo = 1, factor_leche = 0.9500, meta_rendimiento_min = 0.9500, meta_rendimiento_max = 1.0500
 WHERE centro_id = @centro AND LOWER(nombre) = 'yogurt';
UPDATE productos SET es_derivado_lacteo = 1, factor_leche = 3.2000, meta_rendimiento_min = 3.2000, meta_rendimiento_max = 3.6000
 WHERE centro_id = @centro AND LOWER(nombre) = 'manjar blanco';
UPDATE productos SET es_derivado_lacteo = 1, factor_leche = 3.8000, meta_rendimiento_min = 3.8000, meta_rendimiento_max = 4.2000
 WHERE centro_id = @centro AND LOWER(nombre) = 'cortado';
-- Si alguno no existe en el catálogo, el panel de catálogos lo informa y se crea con su código SGAC oficial.
```
