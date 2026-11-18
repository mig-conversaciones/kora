# 6. Anexos

Los anexos contienen los archivos y documentos de referencia que la plantilla exige en la sección 6. El código se entrega como referencia lista para revisión. Se materializa en el repositorio durante la iteración indicada y forma parte de la definición de terminado (RGO-003).

| Anexo | Contenido |
|---|---|
| A | Configuración: `.env.example`, funcionalidades configurables, seguridad y mantenimiento |
| B | Scripts de base de datos: migraciones con reversión y `seed_production.sql` |
| C | Modo mantenimiento: servicio, intermediario, panel, aviso público, CLI de recuperación y runbook |
| D | Operación: salud, copias de seguridad, despliegue, reversión, integridad, cron en Hostinger, ADR y runbooks |
| E | Contrato de integración con el software de ventas (OpenAPI 3.1) |
| F | Evidencia de IA: plantilla y registro de esta entrega |
| G | Mapeo de las fuentes Excel a las tablas |
| H | Rendimiento: documentos, scripts k6 y plantilla de informe |
| I | Plantillas de correo |
| J | Checklist OWASP ASVS 5.0 nivel 2 |
| K | Principios de Linus Torvalds aplicados al proyecto |
| L | Sistema de diseño, *tokens* y favicon |
| M | Checklists finales de aceptación |

## Anexo A. Configuración

### A.1 Archivo `.env.example`

```ini archivo=.env.example
# Producción CAB — variables de entorno.
# Copiar como .env FUERA de public_html (compartido/.env), permisos 600. Nunca versionar el .env real (RS-012).
# Generar claves con:  php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"

APP_NOMBRE="Produccion CAB"
APP_ENTORNO=produccion
APP_URL=https://produccion.dominio-del-centro.gov.co
APP_ZONA_HORARIA=America/Bogota
APP_DEPURACION=false

# Base de datos: 127.0.0.1 desde la aplicación (indicación de Hostinger), nunca el host remoto srvNNNN.hstgr.io.
BD_HOST=127.0.0.1
BD_PUERTO=3306
BD_NOMBRE=
BD_USUARIO=
BD_CONTRASENA=
BD_SKIP_LOCKED=true

# Llavero de cifrado versionado: la primera clave cifra; todas descifran (rotación cada 90 días, D-15).
CLAVES_CIFRADO="v1:REEMPLAZAR_CON_32_BYTES_EN_BASE64"
CLAVE_HMAC_PII=REEMPLAZAR_CON_32_BYTES_EN_BASE64
CLAVE_HMAC_CODIGOS=REEMPLAZAR_CON_32_BYTES_EN_BASE64
TOKEN_MONITOREO=REEMPLAZAR_CON_64_CARACTERES_HEX

# Sesiones (RAC-019)
SESION_INACTIVIDAD_SEGUNDOS=1800
SESION_INACTIVIDAD_PRIVILEGIADOS_SEGUNDOS=900
SESION_ABSOLUTA_SEGUNDOS=43200
SESION_ABSOLUTA_PRIVILEGIADOS_SEGUNDOS=28800

# Correo SMTP del dominio (Hostinger): puerto 465 con TLS implícito o 587 con STARTTLS
CORREO_HOST=smtp.hostinger.com
CORREO_PUERTO=465
CORREO_CIFRADO=ssl
CORREO_USUARIO=
CORREO_CONTRASENA=
CORREO_REMITENTE=notificaciones@dominio-del-centro.gov.co
CORREO_NOMBRE_REMITENTE="Produccion CAB"

# Cola (config/cola.php)
COLA_CONDUCTOR=mysql
COLA_ESTRATEGIA_RECLAMO=skip_locked
COLA_LOTE=10
COLA_PRESUPUESTO_SEGUNDOS=50
COLA_MEMORIA_MAX_MB=192
COLA_MAX_TRABAJADORES=2

# Caché y almacenamiento privado
CACHE_TAMANO_MAXIMO_MB=200

# Funcionalidades (sobrescriben config/funcionalidades.php; valores por defecto restrictivos, RS-023)
FUNCIONALIDAD_RECAUDOS_ALTA_MANUAL=false
FUNCIONALIDAD_HIBP_HABILITADO=false
FUNCIONALIDAD_CAPTCHA_TRAS_UMBRAL=false
FUNCIONALIDAD_VISTA_SOPORTE=false
```

### A.2 Funcionalidades configurables

```php archivo=config/funcionalidades.php
<?php
declare(strict_types=1);

/**
 * Funcionalidades configurables (feature flags, ROB-007). Valores por defecto restrictivos (PoD, RS-023).
 * Cada interruptor documenta su propósito, responsable y fecha prevista de retiro para no acumular deuda.
 * El .env puede sobrescribir cualquier valor con FUNCIONALIDAD_<CLAVE_EN_MAYUSCULAS>=true|false.
 * Una ruta asociada a una funcionalidad inactiva responde 404 (intermediario Autorizar).
 */

return [
    'recaudos_alta_manual' => [
        'activa' => false,
        'descripcion' => 'Alta manual excepcional de recaudos por el Grupo de Recaudo con motivo y soporte (RF-033).',
        'responsable' => 'Coordinación administrativa',
        'creada' => '2026-11-17',
        'retiro_previsto' => null,
    ],
    'hibp_habilitado' => [
        'activa' => false,
        'descripcion' => 'Consulta k-anonimato de contraseñas filtradas (RAC-004); requiere evaluación de privacidad.',
        'responsable' => 'Líder técnico',
        'creada' => '2026-11-17',
        'retiro_previsto' => null,
    ],
    'captcha_tras_umbral' => [
        'activa' => false,
        'descripcion' => 'Desafío adicional tras superar el umbral de fallos (RAC-030); evaluar accesibilidad WCAG 3.3.8.',
        'responsable' => 'Líder técnico',
        'creada' => '2026-11-17',
        'retiro_previsto' => null,
    ],
    'vista_soporte' => [
        'activa' => false,
        'descripcion' => 'Vista de soporte de solo lectura como otro usuario (RF-013).',
        'responsable' => 'Superadministrador',
        'creada' => '2026-11-17',
        'retiro_previsto' => null,
    ],
    'tablero_otras_unidades' => [
        'activa' => false,
        'descripcion' => 'Tableros técnicos de unidades distintas a Ganadería (RF-079, versión futura).',
        'responsable' => 'Líder de producción de centro',
        'creada' => '2026-11-17',
        'retiro_previsto' => '2027-12-31',
    ],
    'exportacion_pdf' => [
        'activa' => true,
        'descripcion' => 'Exportación de tableros a PDF mediante trabajos (RF-081).',
        'responsable' => 'Líder técnico',
        'creada' => '2026-11-17',
        'retiro_previsto' => null,
    ],
    'sugerencias_conciliacion' => [
        'activa' => true,
        'descripcion' => 'Sugerencias automáticas de clasificación y conciliación de recaudos (RF-031, RF-032).',
        'responsable' => 'Grupo de Recaudo',
        'creada' => '2026-11-17',
        'retiro_previsto' => null,
    ],
    'transiciones_vista' => [
        'activa' => true,
        'descripcion' => 'View Transitions en navegaciones HTMX (RUI-019); se desactiva si genera problemas.',
        'responsable' => 'UX/UI',
        'creada' => '2026-11-17',
        'retiro_previsto' => null,
    ],
];
```

### A.3 Seguridad

```php archivo=config/seguridad.php
<?php
declare(strict_types=1);

/**
 * Parámetros de seguridad (RAC, RS). Los costos de Argon2id se calibran en el plan para que el inicio de sesión
 * tarde 500 ms o menos (RAC-002); los límites de frecuencia nunca bloquean de forma permanente (RAC-029).
 */

use App\Nucleo\Entorno;

return [
    'cookie_sesion' => [
        'nombre' => '__Host-pcab_sesion',
        'segura' => true,
        'solo_http' => true,
        'same_site' => 'Lax',
        'ruta' => '/',
    ],
    'sesion' => [
        'inactividad' => Entorno::entero('SESION_INACTIVIDAD_SEGUNDOS', 1800),
        'inactividad_privilegiados' => Entorno::entero('SESION_INACTIVIDAD_PRIVILEGIADOS_SEGUNDOS', 900),
        'absoluta' => Entorno::entero('SESION_ABSOLUTA_SEGUNDOS', 43200),
        'absoluta_privilegiados' => Entorno::entero('SESION_ABSOLUTA_PRIVILEGIADOS_SEGUNDOS', 28800),
        'aviso_antes_segundos' => 120,
    ],
    'contrasenas' => [
        'algoritmo' => PASSWORD_ARGON2ID,
        'opciones' => ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1],
        'minimo_un_factor' => 15,
        'minimo_con_2fa' => 8,
        'maximo' => 128,
        'lista_bloqueada' => RAIZ . '/almacenamiento/listas/contrasenas-comunes.txt',
        'palabras_contexto' => ['sena', 'cab', 'buga', 'produccion', 'ganaderia', 'milagroso'],
    ],
    'totp' => ['digitos' => 6, 'paso_segundos' => 30, 'ventana' => 1, 'codigos_respaldo' => 10],
    'tokens_cuenta' => ['recuperacion_minutos' => 30, 'invitacion_horas' => 72],
    'limites' => [
        'login_cuenta' => ['maximo' => 5, 'ventana' => 900, 'bloqueo' => 900],
        'login_ip' => ['maximo' => 20, 'ventana' => 900, 'bloqueo' => 900],
        'otp' => ['maximo' => 5, 'ventana' => 300, 'bloqueo' => 900],
        'recuperacion' => ['maximo' => 3, 'ventana' => 3600, 'bloqueo' => 3600],
        'activacion_ip' => ['maximo' => 10, 'ventana' => 3600, 'bloqueo' => 3600],
        'administracion' => ['maximo' => 60, 'ventana' => 60, 'bloqueo' => 60],
        'exportaciones' => ['maximo' => 10, 'ventana' => 3600, 'bloqueo' => 600],
        'importaciones' => ['maximo' => 5, 'ventana' => 3600, 'bloqueo' => 600],
    ],
    'reautenticacion_segundos' => 300,
    'roles_2fa_obligatoria' => ['superadministrador', 'administrador'],
    'roles_2fa_recomendada' => ['grupo_recaudo', 'coordinacion_administrativa'],
    'roles_privilegiados' => ['superadministrador', 'administrador'],
    'origen_permitido' => Entorno::texto('APP_URL'),
    'token_monitoreo' => Entorno::texto('TOKEN_MONITOREO'),
];
```

### A.4 Modo mantenimiento

```php archivo=config/mantenimiento.php
<?php
declare(strict_types=1);

/**
 * Modo mantenimiento (RF-MAN-005, RF-MAN-010). Una ruta exceptuada no concede autorización: los intermediarios
 * de sesión y autorización se siguen aplicando. /health/ready está exceptuada del aviso, pero responde 503
 * (no listo) mientras el modo esté activo.
 */

return [
    'senal' => RAIZ . '/almacenamiento/estado/mantenimiento.json',
    'rutas_exceptuadas' => ['/ingresar', '/2fa', '/salir', '/sistema/mantenimiento', '/health/live', '/health/ready'],
    'prefijos_exceptuados' => ['/assets/', '/favicon'],
    'permiso_gestion' => 'mantenimiento.gestionar',
    'permiso_acceso_excepcional' => 'mantenimiento.acceso_excepcional',
    'minutos_reanudacion' => 10,
    'retry_after_por_defecto' => 300,
    'tipos_trabajo_permitidos' => ['correo.sistema', 'mantenimiento.limpieza', 'auditoria.verificar_cadena', 'auditoria.exportar_lote', 'auditoria.archivar', 'seguridad.verificar_integridad'],
    'aviso_por_defecto' => [
        'titulo' => 'Estamos mejorando Producción CAB',
        'mensaje' => 'El sistema está en mantenimiento programado. Tus datos están seguros; vuelve a intentarlo más tarde.',
    ],
];
```
