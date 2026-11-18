## 4.2 Arquitectura MVC en PHP nativo

**Reglas de las capas:**

- **Controladores.** Solo orquestan: validan el permiso, llaman a un servicio y renderizan.
- **Servicios.** Contienen las reglas de negocio, portadas de `js/schemas.js` y `js/metrics.js` del prototipo.
- **Repositorios.** Encapsulan el SQL con alcance obligatorio.
- **Vistas.** Plantillas PHP que solo presentan y escapan la salida.

Ninguna capa salta a otra que no sea la siguiente; PHPStan verifica estas dependencias (RNF-011). La carga de clases sigue PSR-4 con Composer (`"App\\": "app/"`).

### 4.2.1 Controlador frontal

```php archivo=public/index.php
<?php
declare(strict_types=1);

/**
 * Controlador frontal de Producción CAB.
 * Antes de depender de la base de datos evalúa la señal mínima de mantenimiento: durante una migración o
 * con la base caída, el usuario debe ver el aviso 503 y no un error 500 (RF-MAN-005).
 */

use App\Nucleo\Aplicacion;
use App\Nucleo\Entorno;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Servicios\ServicioMantenimiento;

define('RAIZ', dirname(__DIR__));

require RAIZ . '/vendor/autoload.php';

Entorno::cargar(RAIZ . '/.env');

$solicitud = Solicitud::desdeGlobales();
$senal = ServicioMantenimiento::leerSenal(RAIZ . '/almacenamiento/estado/mantenimiento.json');

// Ruta rápida: con mantenimiento activo, quien no tiene sesión ni pide una ruta exceptuada recibe el
// aviso sin abrir conexión a la base de datos. Las sesiones existentes continúan hacia el intermediario
// Mantenimiento, que decide si hay acceso excepcional autorizado (RF-MAN-009).
if ($senal !== null
    && !ServicioMantenimiento::rutaExceptuada($solicitud->ruta)
    && !$solicitud->tieneCookie('__Host-pcab_sesion')) {
    ServicioMantenimiento::respuestaAviso($solicitud, $senal)->enviar();
    exit;
}

try {
    $respuesta = Aplicacion::crear(require RAIZ . '/config/app.php')->manejar($solicitud);
} catch (Throwable $error) {
    // Falla del arranque (por ejemplo, configuración incompleta): respuesta mínima sin detalles (RS-010).
    error_log(json_encode(['nivel' => 'critico', 'evento' => 'arranque_fallido', 'codigo' => $error->getCode()]));
    $respuesta = $senal !== null
        ? ServicioMantenimiento::respuestaAviso($solicitud, $senal)
        : Respuesta::servicioNoDisponible($solicitud);
}
$respuesta->enviar();
```

### 4.2.2 Configuración del servidor web

`public/` es la raíz del documento: todo lo demás (código, `.env` y almacenamiento) queda fuera del alcance web. En Hostinger, `public_html` apunta a `releases/vX.Y.Z/public` (sección 4.8).

```apache archivo=public/.htaccess
# Producción CAB — public/.htaccess (LiteSpeed/Apache).
# LiteSpeed de Hostinger interpreta estas directivas; en Nginx usar la configuración equivalente (4.8).
Options -Indexes -MultiViews
DirectoryIndex index.php

<IfModule mod_rewrite.c>
    RewriteEngine On

    # 1. Forzar HTTPS (RS-009). Se contempla el proxy que informa X-Forwarded-Proto.
    RewriteCond %{HTTPS} !=on
    RewriteCond %{HTTP:X-Forwarded-Proto} !=https
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # 2. Bloquear archivos ocultos (salvo .well-known para Let's Encrypt) y extensiones sensibles.
    RewriteRule (^|/)\.(?!well-known/) - [F,L]
    RewriteRule \.(?:env|ini|log|sql|md|lock|sh|phar|bak|dist|yml|yaml)$ - [F,L,NC]

    # 3. Archivos estáticos existentes se sirven directamente.
    RewriteCond %{REQUEST_FILENAME} -f
    RewriteRule ^ - [L]

    # 4. Todo lo demás pasa por el controlador frontal.
    RewriteRule ^ index.php [L,QSA]
</IfModule>

<IfModule mod_headers.c>
    # Cabeceras para respuestas estáticas; las dinámicas las agrega CabecerasSeguridad (RS-008).
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set X-Frame-Options "DENY"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" "expr=%{HTTPS} == 'on'"
    Header always unset X-Powered-By
    <FilesMatch "\.(ico|webmanifest|svg|png)$">
        Header set Cache-Control "public, max-age=86400"
    </FilesMatch>
</IfModule>

# Límite del cuerpo de la solicitud (RS-021); la aplicación aplica además límites por ruta.
LimitRequestBody 12582912
```

```apache archivo=public/assets/.htaccess
# Recursos con huella en el nombre (app.3f9a1c.css): inmutables durante un año (RNF-PERF-013).
<IfModule mod_headers.c>
    Header set Cache-Control "public, max-age=31536000, immutable"
</IfModule>
<IfModule mod_mime.c>
    AddType font/woff2 .woff2
    AddType application/manifest+json .webmanifest
</IfModule>
```

```ini archivo=public/.user.ini
; Producción CAB — ajustes de PHP para LiteSpeed (Hostinger). Verificar en la I0 que el plan respeta
; .user.ini; si no, fijar los mismos valores en hPanel → Avanzado → Configuración de PHP.
display_errors = Off
log_errors = On
expose_php = Off
memory_limit = 256M
max_execution_time = 30
upload_max_filesize = 10M
post_max_size = 12M
max_input_vars = 2000
session.use_strict_mode = 1
session.use_only_cookies = 1
session.use_trans_sid = 0
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = "Lax"
opcache.enable = 1
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
zend.exception_ignore_args = On
```

> **Advertencia.** `realpath_cache_ttl` es una directiva de sistema (PHP_INI_SYSTEM) y no puede cambiarse desde `.user.ini`. Tras cambiar el enlace de versión, algunos procesos PHP pueden resolver la versión anterior durante un máximo de unos 120 s. Por eso las migraciones son compatibles hacia atrás (RDL-002) y el despliegue con cambios incompatibles usa el modo mantenimiento (ROB-005).

### 4.2.3 Configuración y acceso a la base de datos

```php archivo=config/base_datos.php
<?php
declare(strict_types=1);

/**
 * Conexión a MariaDB (Hostinger Cloud) o MySQL 8.
 * Hostinger indica conectar desde la aplicación a 127.0.0.1:3306 o al socket local; el host srvNNNN.hstgr.io
 * es solo para conexiones remotas autorizadas (RBD-017).
 */

use App\Nucleo\Entorno;

return [
    'dsn' => sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        Entorno::texto('BD_HOST', '127.0.0.1'),
        Entorno::entero('BD_PUERTO', 3306),
        Entorno::texto('BD_NOMBRE')
    ),
    'usuario' => Entorno::texto('BD_USUARIO'),
    'contrasena' => Entorno::texto('BD_CONTRASENA'),
    'opciones' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ],
    // Se ejecutan al abrir cada conexión: UTC y modo estricto (RBD-002, RBD-003).
    'inicializacion' => [
        "SET time_zone = '+00:00'",
        "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'",
        'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
    ],
    // verificar_entorno.php compara estas versiones con SELECT VERSION() (RBD-001).
    'version_minima' => ['mariadb' => '10.6.0', 'mysql' => '8.0.0'],
    // Estrategia de reclamo de la cola: SKIP LOCKED si el motor lo soporta (RF-Q-006).
    'soporta_skip_locked' => Entorno::booleano('BD_SKIP_LOCKED', true),
];
```

```php archivo=app/Nucleo/BaseDatos.php
<?php
declare(strict_types=1);

namespace App\Nucleo;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Envoltura mínima sobre PDO: conexión perezosa, transacciones reutilizables y conteo de consultas por
 * solicitud, que las pruebas usan para detectar N+1 (RNF-PERF-009).
 */
final class BaseDatos
{
    private ?PDO $pdo = null;
    private int $consultas = 0;
    private int $profundidad = 0;

    /** @param array{dsn:string,usuario:string,contrasena:string,opciones:array<int,mixed>,inicializacion:list<string>} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->config['dsn'], $this->config['usuario'], $this->config['contrasena'], $this->config['opciones']);
            foreach ($this->config['inicializacion'] as $sentencia) {
                $this->pdo->exec($sentencia);
            }
        }
        return $this->pdo;
    }

    /** @return list<array<string,mixed>> */
    public function consultar(string $sql, array $parametros = []): array
    {
        return $this->preparar($sql, $parametros)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function fila(string $sql, array $parametros = []): ?array
    {
        $fila = $this->preparar($sql, $parametros)->fetch();
        return $fila === false ? null : $fila;
    }

    public function valor(string $sql, array $parametros = []): mixed
    {
        $valor = $this->preparar($sql, $parametros)->fetchColumn();
        return $valor === false ? null : $valor;
    }

    public function ejecutar(string $sql, array $parametros = []): int
    {
        return $this->preparar($sql, $parametros)->rowCount();
    }

    public function insertar(string $sql, array $parametros = []): int
    {
        $this->preparar($sql, $parametros);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Ejecuta $operacion en una transacción. Las llamadas anidadas reutilizan la transacción externa para
     * que el cambio de negocio y el trabajo encolado se confirmen juntos (outbox, RF-Q-004).
     *
     * @template T
     * @param callable(self):T $operacion
     * @return T
     */
    public function transaccion(callable $operacion): mixed
    {
        if ($this->profundidad > 0) {
            $this->profundidad++;
            try {
                return $operacion($this);
            } finally {
                $this->profundidad--;
            }
        }
        $this->pdo()->beginTransaction();
        $this->profundidad = 1;
        try {
            $resultado = $operacion($this);
            $this->pdo()->commit();
            return $resultado;
        } catch (Throwable $error) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            throw $error;
        } finally {
            $this->profundidad = 0;
        }
    }

    public function enTransaccion(): bool
    {
        return $this->profundidad > 0;
    }

    public function totalConsultas(): int
    {
        return $this->consultas;
    }

    private function preparar(string $sql, array $parametros): PDOStatement
    {
        $this->consultas++;
        $sentencia = $this->pdo()->prepare($sql);
        foreach ($parametros as $clave => $valor) {
            $marcador = is_int($clave) ? $clave + 1 : ':' . ltrim((string) $clave, ':');
            $tipo = match (true) {
                is_int($valor) => PDO::PARAM_INT,
                is_bool($valor) => PDO::PARAM_BOOL,
                $valor === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $sentencia->bindValue($marcador, $valor, $tipo);
        }
        $sentencia->execute();
        return $sentencia;
    }
}
```

```php archivo=app/Nucleo/Entorno.php
<?php
declare(strict_types=1);

namespace App\Nucleo;

use RuntimeException;

/**
 * Lee .env sin dependencias externas. El archivo vive fuera de public_html con permisos 600 (RS-012);
 * aquí solo se interpreta el formato CLAVE=valor con comillas opcionales.
 */
final class Entorno
{
    /** @var array<string,string> */
    private static array $valores = [];

    public static function cargar(string $ruta): void
    {
        if (!is_readable($ruta)) {
            throw new RuntimeException('No se encontró la configuración del entorno.');
        }
        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
                continue;
            }
            [$clave, $valor] = array_map('trim', explode('=', $linea, 2));
            if (strlen($valor) >= 2 && ($valor[0] === '"' || $valor[0] === "'") && $valor[-1] === $valor[0]) {
                $valor = substr($valor, 1, -1);
            }
            self::$valores[$clave] = $valor;
        }
    }

    public static function texto(string $clave, ?string $defecto = null): string
    {
        $valor = self::$valores[$clave] ?? getenv($clave);
        if ($valor === false || $valor === '') {
            if ($defecto === null) {
                throw new RuntimeException("Falta la variable de entorno {$clave}.");
            }
            return $defecto;
        }
        return (string) $valor;
    }

    public static function entero(string $clave, int $defecto): int
    {
        return (int) self::texto($clave, (string) $defecto);
    }

    public static function booleano(string $clave, bool $defecto): bool
    {
        return filter_var(self::texto($clave, $defecto ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN);
    }
}
```

### 4.2.4 Solicitud, respuesta y enrutamiento

```php archivo=app/Nucleo/Solicitud.php
<?php
declare(strict_types=1);

namespace App\Nucleo;

/** Solicitud HTTP normalizada. Los intermediarios agregan atributos: request_id, usuario, alcance, ruta. */
final class Solicitud
{
    /** @var array<string,mixed> */
    private array $atributos = [];

    /**
     * @param array<string,mixed> $consulta
     * @param array<string,mixed> $cuerpo
     * @param array<string,string> $cabeceras nombres en minúsculas
     * @param array<string,string> $cookies
     */
    public function __construct(
        public readonly string $metodo,
        public readonly string $ruta,
        public readonly array $consulta,
        public readonly array $cuerpo,
        public readonly array $cabeceras,
        public readonly array $cookies,
        public readonly string $ip,
    ) {
    }

    public static function desdeGlobales(): self
    {
        $cabeceras = [];
        foreach ($_SERVER as $clave => $valor) {
            if (str_starts_with((string) $clave, 'HTTP_')) {
                $cabeceras[strtolower(str_replace('_', '-', substr((string) $clave, 5)))] = (string) $valor;
            }
        }
        $ruta = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            rtrim($ruta, '/') === '' ? '/' : rtrim($ruta, '/'),
            $_GET,
            $_POST,
            $cabeceras,
            $_COOKIE,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    public function cabecera(string $nombre): ?string
    {
        return $this->cabeceras[strtolower($nombre)] ?? null;
    }

    public function esHtmx(): bool
    {
        return $this->cabecera('HX-Request') === 'true';
    }

    public function esEscritura(): bool
    {
        return !in_array($this->metodo, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function tieneCookie(string $nombre): bool
    {
        return isset($this->cookies[$nombre]) && $this->cookies[$nombre] !== '';
    }

    public function atributo(string $clave, mixed $defecto = null): mixed
    {
        return $this->atributos[$clave] ?? $defecto;
    }

    public function conAtributo(string $clave, mixed $valor): self
    {
        $copia = clone $this;
        $copia->atributos[$clave] = $valor;
        return $copia;
    }
}
```

```php archivo=app/Nucleo/Respuesta.php
<?php
declare(strict_types=1);

namespace App\Nucleo;

/** Respuesta HTTP. Las cabeceras de mensajes y seguridad se agregan con conCabecera. */
final class Respuesta
{
    /** @param array<string,string> $cabeceras */
    public function __construct(public string $cuerpo = '', public int $estado = 200, public array $cabeceras = [])
    {
    }

    public static function html(string $html, int $estado = 200): self
    {
        return new self($html, $estado, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<string,mixed> $datos */
    public static function json(array $datos, int $estado = 200): self
    {
        return new self(json_encode($datos, JSON_THROW_ON_ERROR), $estado, ['Content-Type' => 'application/json']);
    }

    public static function redireccion(string $url, int $estado = 303): self
    {
        return new self('', $estado, ['Location' => $url]);
    }

    /** 503 controlado cuando la base de datos no responde; sin detalles internos (RS-010). */
    public static function servicioNoDisponible(Solicitud $solicitud): self
    {
        $html = '<!doctype html><html lang="es"><meta charset="utf-8"><title>Servicio no disponible</title>'
            . '<main role="status"><h1>El servicio no está disponible en este momento</h1>'
            . '<p>Intenta de nuevo en unos minutos. Si el problema continúa, informa al área de soporte.</p></main></html>';
        $respuesta = self::html($html, 503)
            ->conCabecera('Retry-After', '120')
            ->conCabecera('Cache-Control', 'no-store');
        return $solicitud->esHtmx()
            ? $respuesta->conCabecera('HX-Retarget', '#contenedor-errores')->conCabecera('HX-Reswap', 'innerHTML')
            : $respuesta;
    }

    public function conCabecera(string $nombre, string $valor): self
    {
        $this->cabeceras[$nombre] = $valor;
        return $this;
    }

    public function enviar(): void
    {
        http_response_code($this->estado);
        foreach ($this->cabeceras as $nombre => $valor) {
            header("{$nombre}: {$valor}", true);
        }
        echo $this->cuerpo;
    }
}
```

```php archivo=app/Nucleo/Enrutador.php
<?php
declare(strict_types=1);

namespace App\Nucleo;

use LogicException;

/**
 * Enrutamiento manual. Cada ruta declara el permiso que su política debe verificar; una ruta sin permiso y
 * no marcada como pública se rechaza al registrarla (denegación por defecto, RAC-023).
 */
final class Enrutador
{
    /** @var list<array{metodo:string,regex:string,parametros:list<string>,accion:array{0:class-string,1:string},permiso:string,opciones:array<string,mixed>}> */
    private array $rutas = [];

    /**
     * @param array{0:class-string,1:string} $accion
     * @param array<string,mixed> $opciones publica, exceptuadaMantenimiento, reautenticacion, funcionalidad
     */
    public function agregar(string $metodo, string $patron, array $accion, string $permiso, array $opciones = []): void
    {
        if ($permiso === '' && empty($opciones['publica'])) {
            throw new LogicException("La ruta {$metodo} {$patron} no declara permiso.");
        }
        preg_match_all('/\{(\w+)\}/', $patron, $nombres);
        $this->rutas[] = [
            'metodo' => strtoupper($metodo),
            'regex' => '#^' . preg_replace('/\{(\w+)\}/', '(?P<$1>[A-Za-z0-9_-]+)', $patron) . '$#',
            'parametros' => $nombres[1],
            'accion' => $accion,
            'permiso' => $permiso,
            'opciones' => $opciones,
        ];
    }

    /** @return array{accion:array{0:class-string,1:string},permiso:string,parametros:array<string,string>,opciones:array<string,mixed>} */
    public function resolver(string $metodo, string $ruta): array
    {
        $existeConOtroMetodo = false;
        foreach ($this->rutas as $definicion) {
            if (preg_match($definicion['regex'], $ruta, $coincidencias) !== 1) {
                continue;
            }
            if ($definicion['metodo'] !== strtoupper($metodo)) {
                $existeConOtroMetodo = true;
                continue;
            }
            return [
                'accion' => $definicion['accion'],
                'permiso' => $definicion['permiso'],
                'parametros' => array_intersect_key($coincidencias, array_flip($definicion['parametros'])),
                'opciones' => $definicion['opciones'],
            ];
        }
        throw new ErrorHttp($existeConOtroMetodo ? 405 : 404);
    }
}
```

```php archivo=config/rutas.php
<?php
declare(strict_types=1);

use App\Controladores\ControladorTrabajos;
use App\Controladores\GastosControlador;
use App\Controladores\MantenimientoControlador;
use App\Controladores\RecaudosControlador;
use App\Controladores\SaludControlador;
use App\Controladores\TableroCentralControlador;
use App\Nucleo\Enrutador;

/** Extracto del registro de rutas: cada ruta declara el permiso que verifica su política (RAC-024). */
return static function (Enrutador $r): void {
    $r->agregar('GET', '/health/live', [SaludControlador::class, 'vivo'], '', ['publica' => true, 'exceptuadaMantenimiento' => true]);
    $r->agregar('GET', '/health/ready', [SaludControlador::class, 'listo'], '', ['publica' => true, 'exceptuadaMantenimiento' => true]);
    $r->agregar('GET', '/tablero/central', [TableroCentralControlador::class, 'mostrar'], 'tablero_central.consultar');
    $r->agregar('GET', '/presupuesto/gastos', [GastosControlador::class, 'listar'], 'gastos.consultar');
    $r->agregar('POST', '/presupuesto/gastos', [GastosControlador::class, 'crear'], 'gastos.gestionar');
    $r->agregar('GET', '/recaudos', [RecaudosControlador::class, 'listar'], 'recaudos.consultar');
    $r->agregar('POST', '/recaudos', [RecaudosControlador::class, 'crear'], 'recaudos.gestionar', ['funcionalidad' => 'recaudos_alta_manual']);
    $r->agregar('GET', '/trabajos/{ulid}', [ControladorTrabajos::class, 'estado'], 'trabajos.consultar_propios');
    $r->agregar('GET', '/trabajos/{ulid}/resultado', [ControladorTrabajos::class, 'resultado'], 'trabajos.consultar_propios');
    $r->agregar('GET', '/sistema/mantenimiento', [MantenimientoControlador::class, 'mostrar'], 'mantenimiento.gestionar', ['exceptuadaMantenimiento' => true]);
    $r->agregar('POST', '/sistema/mantenimiento', [MantenimientoControlador::class, 'cambiar'], 'mantenimiento.gestionar', ['exceptuadaMantenimiento' => true, 'reautenticacion' => true]);
};
```

```php archivo=app/Nucleo/Aplicacion.php
<?php
declare(strict_types=1);

namespace App\Nucleo;

use App\Intermediarios\Autenticar;
use App\Intermediarios\Autorizar;
use App\Intermediarios\CabecerasSeguridad;
use App\Intermediarios\IdSolicitud;
use App\Intermediarios\Idempotencia;
use App\Intermediarios\IniciarSesion;
use App\Intermediarios\LimitarTasa;
use App\Intermediarios\Mantenimiento;
use App\Intermediarios\VerificarCsrf;
use Throwable;

/**
 * Resuelve la ruta y ejecuta la cadena de intermediarios en orden fijo. El identificador de solicitud y las
 * cabeceras de seguridad van primero para que incluso los errores y el aviso de mantenimiento salgan
 * correlacionados y con CSP.
 */
final class Aplicacion
{
    private const CADENA = [
        IdSolicitud::class,
        CabecerasSeguridad::class,
        Mantenimiento::class,
        IniciarSesion::class,
        LimitarTasa::class,
        VerificarCsrf::class,
        Autenticar::class,
        Idempotencia::class,
        Autorizar::class,
    ];

    public function __construct(private readonly Contenedor $contenedor, private readonly Enrutador $enrutador)
    {
    }

    /** @param array<string,mixed> $config */
    public static function crear(array $config): self
    {
        $enrutador = new Enrutador();
        (require RAIZ . '/config/rutas.php')($enrutador);
        return new self(Contenedor::desdeConfiguracion($config), $enrutador);
    }

    public function manejar(Solicitud $solicitud): Respuesta
    {
        try {
            $ruta = $this->enrutador->resolver($solicitud->metodo, $solicitud->ruta);
            $solicitud = $solicitud->conAtributo('ruta', $ruta);
            $controlador = function (Solicitud $s) use ($ruta): Respuesta {
                [$clase, $metodo] = $ruta['accion'];
                return $this->contenedor->obtener($clase)->{$metodo}($s, ...array_values($ruta['parametros']));
            };
            $cadena = array_reduce(
                array_reverse(self::CADENA),
                fn (callable $siguiente, string $clase): callable => fn (Solicitud $s): Respuesta => $this->contenedor->obtener($clase)->procesar($s, $siguiente),
                $controlador
            );
            return $cadena($solicitud);
        } catch (Throwable $error) {
            // ManejadorErrores convierte ErrorHttp, ErrorValidacion y PDOException (503) en fragmentos
            // controlados con request_id y sin detalles internos (RUI-015, RS-010).
            return $this->contenedor->obtener(ManejadorErrores::class)->convertir($solicitud, $error);
        }
    }
}
```

### 4.2.5 Controlador base

```php archivo=app/Controladores/ControladorBase.php
<?php
declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Politicas\Autorizador;
use App\Servicios\ServicioAuditoria;

/**
 * Base de todos los controladores. Concentra las decisiones de presentación que deben ser idénticas en todo
 * el sistema: página completa o fragmento HTMX, respuestas de validación que conservan lo escrito, mensajes
 * por HX-Trigger y verificación de permisos. Los controladores hijos solo orquestan.
 */
abstract class ControladorBase
{
    public function __construct(
        protected readonly Vista $vista,
        protected readonly Autorizador $autorizador,
        protected readonly ServicioAuditoria $auditoria,
    ) {
    }

    /**
     * Página completa o solo el fragmento según HX-Request (RI-003). Toda respuesta varía por HX-Request y
     * no se almacena en cachés compartidas porque contiene datos de un alcance concreto.
     *
     * @param array<string,mixed> $datos
     */
    protected function renderizar(Solicitud $solicitud, string $pagina, string $fragmento, array $datos = [], int $estado = 200): Respuesta
    {
        $datos += ['solicitud' => $solicitud, 'usuario' => $solicitud->atributo('usuario')];
        $html = $solicitud->esHtmx()
            ? $this->vista->fragmento($fragmento, $datos)
            : $this->vista->pagina($pagina, $fragmento, $datos);
        return Respuesta::html($html, $estado)
            ->conCabecera('Vary', 'HX-Request')
            ->conCabecera('Cache-Control', 'no-store');
    }

    /** Verifica el permiso con la política del recurso: 403, o 404 si está fuera del alcance (RAC-028). */
    protected function autorizar(Solicitud $solicitud, string $permiso, ?object $recurso = null): void
    {
        $decision = $this->autorizador->decidir($solicitud->atributo('usuario'), $permiso, $recurso, $solicitud->atributo('alcance'));
        if ($decision->permitida()) {
            return;
        }
        $this->auditoria->registrar($solicitud, $permiso, 'denegado', $recurso, ['motivo' => $decision->motivo()]);
        throw new ErrorHttp($decision->fueraDeAlcance() ? 404 : 403);
    }

    /**
     * 422 con el fragmento del formulario: conserva lo escrito (salvo secretos) y marca los campos inválidos
     * para que retroalimentacion-ui.js lleve el foco al resumen de errores (RNF-005, RUI-012).
     *
     * @param array<string,mixed> $datos
     */
    protected function respuestaValidacion(Solicitud $solicitud, ErrorValidacion $error, string $fragmentoFormulario, array $datos = []): Respuesta
    {
        $valores = $solicitud->cuerpo;
        unset($valores['contrasena'], $valores['contrasena_actual'], $valores['codigo'], $valores['_csrf']);
        return $this->renderizar($solicitud, $fragmentoFormulario, $fragmentoFormulario, $datos + [
            'valores' => $valores,
            'errores' => $error->errores(),
        ], 422);
    }

    /**
     * Mensaje para retroalimentacion-ui.js. El JSON se codifica en ASCII (ó) porque las cabeceras HTTP
     * no transportan UTF-8 de forma confiable (D-34). X-Toast-Type se conserva por compatibilidad (RI-004).
     */
    protected function conToast(Respuesta $respuesta, string $tipo, string $mensaje, ?string $titulo = null): Respuesta
    {
        $evento = ['mostrarToast' => array_filter(['tipo' => $tipo, 'mensaje' => $mensaje, 'titulo' => $titulo])];
        return $respuesta
            ->conCabecera('HX-Trigger', json_encode($evento, JSON_THROW_ON_ERROR))
            ->conCabecera('X-Toast-Type', $tipo);
    }

    /**
     * Estado vacío explicativo con acción sugerida; nunca una tabla vacía (RUI-014).
     *
     * @param array{texto:string,url:string}|null $accion
     */
    protected function estadoVacio(Solicitud $solicitud, string $titulo, string $descripcion, ?array $accion = null): Respuesta
    {
        return $this->renderizar($solicitud, 'parciales/estado_vacio', 'parciales/estado_vacio', [
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'accion' => $accion,
        ]);
    }
}
```

**Ejemplo de controlador delgado** (registro de gastos, RF-025 y Obs. 1):

```php archivo=app/Controladores/GastosControlador.php
<?php
declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Politicas\Autorizador;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioGastos;

/** Gastos ejecutados (RF-025): las reglas viven en ServicioGastos y ReglasGasto. */
final class GastosControlador extends ControladorBase
{
    public function __construct(Vista $vista, Autorizador $autorizador, ServicioAuditoria $auditoria, private readonly ServicioGastos $gastos)
    {
        parent::__construct($vista, $autorizador, $auditoria);
    }

    public function crear(Solicitud $solicitud): Respuesta
    {
        $this->autorizar($solicitud, 'gastos.gestionar');
        try {
            $gasto = $this->gastos->registrar($solicitud->atributo('alcance'), $solicitud->atributo('usuario'), $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->respuestaValidacion($solicitud, $error, 'gastos/formulario', ['categorias' => $this->gastos->categoriasActivas()]);
        }
        $respuesta = $this->renderizar($solicitud, 'gastos/listado', 'gastos/fila', ['gasto' => $gasto], 201);
        return $this->conToast($respuesta, 'success', 'Gasto registrado. Ya cuenta en la ejecución de su línea presupuestal.');
    }
}
```

### 4.2.6 Alcance de datos y repositorios con alcance obligatorio

```php archivo=app/Politicas/AlcanceDatos.php
<?php
declare(strict_types=1);

namespace App\Politicas;

/**
 * Alcance de datos de la solicitud, construido solo en el servidor a partir de la sesión (RDI-003, RDI-005).
 * Un alcance sin unidades no significa «todas»: significa «ninguna» (denegación por defecto, RDI-004).
 */
final class AlcanceDatos
{
    /** @param list<int> $unidades */
    private function __construct(
        public readonly int $centroId,
        private readonly array $unidades,
        public readonly bool $todoElCentro,
    ) {
    }

    /** @param list<int> $unidadesAsignadas */
    public static function deUsuario(int $centroId, array $unidadesAsignadas, bool $rolConAlcanceDeCentro): self
    {
        $unidades = array_values(array_unique(array_map('intval', $unidadesAsignadas)));
        return new self($centroId, $unidades, $rolConAlcanceDeCentro);
    }

    public function permiteUnidad(?int $unidadId): bool
    {
        if ($unidadId === null) {
            return $this->todoElCentro;
        }
        return $this->todoElCentro || in_array($unidadId, $this->unidades, true);
    }

    /** @return list<int> */
    public function unidades(): array
    {
        return $this->unidades;
    }

    public function estaVacio(): bool
    {
        return !$this->todoElCentro && $this->unidades === [];
    }

    /** Huella estable para claves de caché (RDI-008). */
    public function huella(): string
    {
        $unidades = $this->unidades;
        sort($unidades);
        return hash('sha256', $this->centroId . '|' . ($this->todoElCentro ? '*' : implode(',', $unidades)));
    }

    /**
     * Condición SQL obligatoria y sus parámetros. El alias proviene del código del repositorio, nunca de la
     * entrada. Un alcance vacío produce una condición falsa, jamás una consulta sin filtro.
     *
     * @return array{0:string,1:array<string,int>}
     */
    public function condicionSql(string $alias): array
    {
        if ($this->estaVacio()) {
            return ['1 = 0', []];
        }
        $parametros = ['alcance_centro' => $this->centroId];
        if ($this->todoElCentro) {
            return ["{$alias}.centro_id = :alcance_centro", $parametros];
        }
        $marcadores = [];
        foreach ($this->unidades as $i => $unidad) {
            $marcadores[] = ":alcance_unidad_{$i}";
            $parametros["alcance_unidad_{$i}"] = $unidad;
        }
        return ["{$alias}.centro_id = :alcance_centro AND {$alias}.unidad_id IN (" . implode(', ', $marcadores) . ')', $parametros];
    }
}
```

```php archivo=app/Repositorios/RepositorioConAlcance.php
<?php
declare(strict_types=1);

namespace App\Repositorios;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Politicas\AlcanceDatos;
use InvalidArgumentException;

/**
 * Base de los repositorios de negocio. Todo método público exige un AlcanceDatos y antepone su condición a
 * cualquier filtro, de modo que ninguna consulta de negocio puede omitirlo (RDI-003).
 */
abstract class RepositorioConAlcance
{
    /** Tabla y alias: constantes de código, nunca valores de la entrada. */
    protected const TABLA = '';
    protected const ALIAS = 't';
    /** Columnas ordenables (lista blanca, RS-003). */
    protected const ORDENABLES = ['id' => 't.id'];

    public function __construct(protected readonly BaseDatos $bd)
    {
    }

    /**
     * Listado paginado. $filtrosSql lo construye la subclase con marcadores; nunca interpola valores.
     * Se pide una fila extra para calcular X-Has-More sin un COUNT costoso (RNF-PERF-010).
     *
     * @param array<string,scalar|null> $parametros
     * @return array{filas:list<array<string,mixed>>,hayMas:bool}
     */
    public function listar(AlcanceDatos $alcance, string $filtrosSql, array $parametros, string $orden, int $pagina, int $porPagina = 25): array
    {
        [$condicion, $parametrosAlcance] = $alcance->condicionSql(static::ALIAS);
        $porPagina = max(1, min(100, $porPagina));
        $columna = static::ORDENABLES[ltrim($orden, '-')] ?? static::ALIAS . '.id';
        $direccion = str_starts_with($orden, '-') ? 'DESC' : 'ASC';
        $sql = sprintf(
            'SELECT %1$s.* FROM %2$s %1$s WHERE %3$s AND %1$s.archivado_en IS NULL %4$s ORDER BY %5$s %6$s, %1$s.id %6$s LIMIT %7$d OFFSET %8$d',
            static::ALIAS,
            static::TABLA,
            $condicion,
            $filtrosSql === '' ? '' : 'AND ' . $filtrosSql,
            $columna,
            $direccion,
            $porPagina + 1,
            (max(1, $pagina) - 1) * $porPagina
        );
        $filas = $this->bd->consultar($sql, $parametrosAlcance + $parametros);
        return ['filas' => array_slice($filas, 0, $porPagina), 'hayMas' => count($filas) > $porPagina];
    }

    /**
     * Registro por id dentro del alcance; fuera del alcance responde 404 para no revelar su existencia (RAC-028).
     *
     * @return array<string,mixed>
     */
    public function obtener(AlcanceDatos $alcance, int $id): array
    {
        [$condicion, $parametros] = $alcance->condicionSql(static::ALIAS);
        $fila = $this->bd->fila(
            sprintf('SELECT %1$s.* FROM %2$s %1$s WHERE %1$s.id = :id AND %3$s', static::ALIAS, static::TABLA, $condicion),
            $parametros + ['id' => $id]
        );
        if ($fila === null) {
            throw new ErrorHttp(404);
        }
        return $fila;
    }

    /**
     * Actualización con bloqueo optimista (RBD-009). $cambios ya pasó por la lista blanca del caso de uso.
     *
     * @param array<string,scalar|null> $cambios
     */
    public function actualizar(AlcanceDatos $alcance, int $id, int $version, array $cambios, int $usuarioId): void
    {
        $this->obtener($alcance, $id);
        $asignaciones = [];
        foreach (array_keys($cambios) as $columna) {
            if (preg_match('/^[a-z_]+$/', $columna) !== 1) {
                throw new InvalidArgumentException('Columna no permitida.');
            }
            $asignaciones[] = "{$columna} = :{$columna}";
        }
        $filas = $this->bd->ejecutar(
            sprintf('UPDATE %s SET %s, version = version + 1, actualizado_por = :usuario_actor, actualizado_en = UTC_TIMESTAMP(3) WHERE id = :id_registro AND version = :version_esperada', static::TABLA, implode(', ', $asignaciones)),
            $cambios + ['usuario_actor' => $usuarioId, 'id_registro' => $id, 'version_esperada' => $version]
        );
        if ($filas === 0) {
            throw new ErrorHttp(409, 'El registro cambió; recarga para ver la versión actual.');
        }
    }
}
```
