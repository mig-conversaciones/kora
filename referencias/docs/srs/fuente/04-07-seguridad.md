## 4.7 Arquitectura de seguridad

### 4.7.1 Modelo de amenazas (STRIDE resumido)

| Amenaza | Activo y vector | Control | Requisitos | Prueba |
|---|---|---|---|---|
| Suplantación | Robo de credenciales o fuerza bruta sobre el inicio de sesión | Argon2id, lista de contraseñas bloqueadas, límites de frecuencia, 2FA obligatoria para privilegiados y mensajes genéricos | RAC-002 a RAC-013, RAC-029 | PI-RAC-029, PS-RAC-005 |
| Suplantación | Fijación o robo de la cookie de sesión | `__Host-`, HttpOnly, Secure, SameSite=Lax, regeneración del ID y expiración | RAC-016 a RAC-019 | PS-RAC-018 |
| Manipulación | Cambiar `unidad_id` o `centro_id` en un formulario o filtro | Alcance construido en el servidor, lista blanca de campos y 404 fuera del alcance | RDI-003 a RDI-005, RS-006 | PI-RDI-010 |
| Manipulación | Alterar eventos de auditoría directamente en la base | Cadena de hash, sellado, verificación diaria y exportación externa firmada | RAU-004, RAU-005 | PI-RAU-004 |
| Manipulación | Archivo Excel malicioso (bomba ZIP, macros, archivo políglota) | Firma OOXML, límites de descompresión, sin `.xlsm`, lectura de valores en *streaming* | RF-084, RS-011 | PS-RS-011 |
| Repudio | Negar una clasificación de recaudos o una reversión | Auditoría con actor, `request_id`, valores anteriores y nuevos, y motivo | RAU-002, RAU-003 | PI-RAU-003 |
| Divulgación | Un líder ve datos de otras unidades (Obs. 4 y 5) | Repositorios con alcance obligatorio y caché aislada por alcance | RDI-006, RDI-008 | PE-RDI-006 |
| Divulgación | Datos personales de pagadores en la base, los registros o las exportaciones | HMAC más los últimos 4 dígitos, sanitizador de registros y exportaciones sin identificación | RF-034, RAU-007 | PS-RF-034 |
| Divulgación | Instantáneas del historial de htmx en `localStorage` | `historyCacheSize: 0` (D-38) | RS-024 | PE-RS-024 |
| Denegación de servicio | Exportaciones o importaciones masivas | Cola con límites, límites de frecuencia y tamaño máximo | RF-Q-005, RAC-029, RS-021 | PCA-RNF-PERF-006 |
| Denegación de servicio | Bloqueo de cuentas ajenas usando el límite de frecuencia | Bloqueos temporales y progresivos, nunca permanentes; límites combinados por cuenta e IP | RAC-029 | PI-RAC-029 |
| Elevación de privilegios | Llamar a rutas de administración sin permiso | Denegación por defecto, permiso declarado por ruta y prueba de cobertura de rutas | RAC-023 a RAC-025 | PI-RAC-025 |
| Elevación de privilegios | XSS para operar con la sesión de la víctima | CSP con nonce, escape por contexto, sin `eval` y CSRF | RS-004, RS-005, RS-007 | PS-RS-007 |

### 4.7.2 Cabeceras de seguridad y CSP

Las plantillas **no usan atributos `style`**, porque la CSP los bloquea. Las barras de avance se hacen con `<progress>` o con atributos de presentación de SVG. Alpine modifica los estilos mediante CSSOM (`el.style`), lo que la CSP sí permite.

```php archivo=app/Intermediarios/CabecerasSeguridad.php
<?php
declare(strict_types=1);

namespace App\Intermediarios;

use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;

/**
 * Genera un nonce por respuesta y aplica la CSP estricta y las cabeceras de seguridad (RS-007, RS-008).
 * Sin 'unsafe-inline' ni 'unsafe-eval': htmx corre con allowEval = false y Alpine en su versión CSP (ADR-004).
 */
final class CabecerasSeguridad implements Intermediario
{
    public function procesar(Solicitud $solicitud, callable $siguiente): Respuesta
    {
        $nonce = base64_encode(random_bytes(18));
        /** @var Respuesta $respuesta */
        $respuesta = $siguiente($solicitud->conAtributo('nonce', $nonce));
        $politica = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'nonce-{$nonce}'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            'upgrade-insecure-requests',
            'report-uri /csp-reportes',
            'report-to csp',
        ]);
        return $respuesta
            ->conCabecera('Content-Security-Policy', $politica)
            ->conCabecera('Reporting-Endpoints', 'csp="/csp-reportes"')
            ->conCabecera('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->conCabecera('X-Content-Type-Options', 'nosniff')
            ->conCabecera('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->conCabecera('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->conCabecera('Cross-Origin-Opener-Policy', 'same-origin')
            ->conCabecera('Cross-Origin-Resource-Policy', 'same-origin')
            ->conCabecera('X-Frame-Options', 'DENY')
            ->conCabecera('X-Request-ID', (string) $solicitud->atributo('request_id', ''));
    }
}
```

### 4.7.3 Sesiones en el servidor

```php archivo=app/Nucleo/ManejadorSesionBd.php
<?php
declare(strict_types=1);

namespace App\Nucleo;

use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Sesiones de PHP guardadas en MariaDB (ADR-006, RAC-017). Se guarda el SHA-256 del ID, nunca el ID: una
 * copia de la base no permite secuestrar sesiones. Los nombres de los métodos los impone la interfaz de PHP.
 * Expiración por inactividad y absoluta evaluadas en servidor (RAC-019); revocar = marcar revocada_en.
 */
final class ManejadorSesionBd implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly int $inactividadSegundos = 1800,
        private readonly int $absolutaSegundos = 43200,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    /** 256 bits aleatorios, independiente de session.sid_length (obsoleto desde PHP 8.4). */
    public function create_sid(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function validateId(string $id): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $id) !== 1) {
            return false;
        }
        return $this->bd->valor('SELECT 1 FROM sesiones WHERE id = :id AND revocada_en IS NULL', ['id' => hash('sha256', $id)]) !== null;
    }

    public function read(string $id): string|false
    {
        $fila = $this->bd->fila(
            'SELECT datos FROM sesiones
              WHERE id = :id AND revocada_en IS NULL
                AND ultima_actividad_en > UTC_TIMESTAMP(3) - INTERVAL :inactividad SECOND
                AND expira_absoluta_en > UTC_TIMESTAMP(3)',
            ['id' => hash('sha256', $id), 'inactividad' => $this->inactividadSegundos]
        );
        return $fila === null ? '' : (string) $fila['datos'];
    }

    public function write(string $id, string $data): bool
    {
        // El dato se enlaza dos veces con nombres distintos: VALUES() está obsoleto en MySQL 8 y el alias de fila
        // no existe en MariaDB, así que esta forma es la portable (RNF-016).
        $this->bd->ejecutar(
            'INSERT INTO sesiones (id, datos, creada_en, ultima_actividad_en, expira_absoluta_en)
             VALUES (:id, :datos, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3) + INTERVAL :absoluta SECOND)
             ON DUPLICATE KEY UPDATE datos = :datos_actualizados, ultima_actividad_en = UTC_TIMESTAMP(3)',
            ['id' => hash('sha256', $id), 'datos' => $data, 'datos_actualizados' => $data, 'absoluta' => $this->absolutaSegundos]
        );
        return true;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->bd->ejecutar(
            'UPDATE sesiones SET ultima_actividad_en = UTC_TIMESTAMP(3) WHERE id = :id AND revocada_en IS NULL',
            ['id' => hash('sha256', $id)]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->bd->ejecutar('UPDATE sesiones SET revocada_en = UTC_TIMESTAMP(3) WHERE id = :id', ['id' => hash('sha256', $id)]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->bd->ejecutar(
            'DELETE FROM sesiones WHERE ultima_actividad_en < UTC_TIMESTAMP(3) - INTERVAL 7 DAY LIMIT 1000'
        );
    }
}
```

### 4.7.4 CSRF y autorización

```php archivo=app/Intermediarios/VerificarCsrf.php
<?php
declare(strict_types=1);

namespace App\Intermediarios;

use App\Nucleo\ErrorHttp;
use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Solicitud;
use App\Servicios\ServicioAuditoria;

/**
 * Protección CSRF de las escrituras (RS-005): token sincronizador de la sesión (campo _csrf o cabecera
 * X-CSRF-Token) comparado en tiempo constante, más verificación de Origin y Sec-Fetch-Site. Las rutas de la
 * futura API de integración usan firma HMAC y se excluyen por la opción de ruta «sinCsrf».
 */
final class VerificarCsrf implements Intermediario
{
    public function __construct(
        private readonly Sesion $sesion,
        private readonly ServicioAuditoria $auditoria,
        private readonly string $origenPermitido,
    ) {
    }

    public function procesar(Solicitud $solicitud, callable $siguiente): Respuesta
    {
        $ruta = $solicitud->atributo('ruta', []);
        if (!$solicitud->esEscritura() || !empty($ruta['opciones']['sinCsrf'])) {
            return $siguiente($solicitud);
        }
        $recibido = $solicitud->cabecera('X-CSRF-Token') ?? (string) ($solicitud->cuerpo['_csrf'] ?? '');
        $esperado = $this->sesion->tokenCsrf();
        $origen = $solicitud->cabecera('Origin');
        $sitio = $solicitud->cabecera('Sec-Fetch-Site');
        $origenValido = ($origen === null || hash_equals($this->origenPermitido, $origen))
            && ($sitio === null || in_array($sitio, ['same-origin', 'none'], true));
        if ($esperado === '' || !hash_equals($esperado, $recibido) || !$origenValido) {
            $this->auditoria->registrar($solicitud, 'seguridad.csrf', 'denegado', null, ['origen_presente' => $origen !== null]);
            throw new ErrorHttp(403);
        }
        return $siguiente($solicitud);
    }
}
```

```php archivo=app/Intermediarios/Autorizar.php
<?php
declare(strict_types=1);

namespace App\Intermediarios;

use App\Nucleo\ErrorHttp;
use App\Nucleo\Funcionalidades;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Politicas\Autorizador;
use App\Servicios\ServicioAlcance;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioReautenticacion;

/**
 * Autorización de la ruta (RAC-023, RAC-024): construye el AlcanceDatos en servidor, verifica el permiso
 * RBAC declarado por la ruta y exige reautenticación reciente cuando la ruta lo indica (RAC-026). Las
 * condiciones sobre un registro concreto (ABAC) se verifican en el controlador con la política del recurso.
 */
final class Autorizar implements Intermediario
{
    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly ServicioAlcance $alcances,
        private readonly Funcionalidades $funcionalidades,
        private readonly ServicioReautenticacion $reautenticacion,
        private readonly ServicioAuditoria $auditoria,
    ) {
    }

    public function procesar(Solicitud $solicitud, callable $siguiente): Respuesta
    {
        $ruta = $solicitud->atributo('ruta');
        $funcionalidad = $ruta['opciones']['funcionalidad'] ?? null;
        if ($funcionalidad !== null && !$this->funcionalidades->activa($funcionalidad)) {
            throw new ErrorHttp(404);
        }
        if (!empty($ruta['opciones']['publica'])) {
            return $siguiente($solicitud);
        }
        $usuario = $solicitud->atributo('usuario');
        $alcance = $this->alcances->paraUsuario($usuario, $solicitud);
        $solicitud = $solicitud->conAtributo('alcance', $alcance);
        if (!$this->autorizador->decidir($usuario, $ruta['permiso'], null, $alcance)->permitida()) {
            $this->auditoria->registrar($solicitud, $ruta['permiso'], 'denegado');
            throw new ErrorHttp(403);
        }
        if (!empty($ruta['opciones']['reautenticacion']) && !$this->reautenticacion->esReciente($solicitud)) {
            return $this->reautenticacion->solicitar($solicitud);
        }
        return $siguiente($solicitud);
    }
}
```

```php archivo=app/Politicas/PoliticaRecaudos.php
<?php
declare(strict_types=1);

namespace App\Politicas;

/**
 * Política de recaudos. Regla de negocio explícita de la revisión del mockup (Obs. 2, RF-029): solo el Grupo
 * de Recaudo registra, clasifica y concilia; la Coordinación administrativa y los demás roles consultan dentro
 * de su alcance. Las escrituras exigen periodo abierto (RF-028). Defensa en profundidad: aunque el permiso solo
 * está asignado a ese rol, la regla también se expresa aquí.
 */
final class PoliticaRecaudos implements Politica
{
    private const ESCRITURAS = ['recaudos.gestionar', 'recaudos.importar', 'recaudos.clasificar', 'recaudos.conciliar'];

    public function __construct(private readonly PoliticaPeriodo $periodos)
    {
    }

    public function decidir(UsuarioSesion $usuario, string $accion, ?object $recurso, AlcanceDatos $alcance): Decision
    {
        if (!$usuario->tienePermiso($accion)) {
            return Decision::denegar('Permiso no asignado al rol.');
        }
        $escritura = in_array($accion, self::ESCRITURAS, true);
        if ($escritura && !$usuario->tieneRol('grupo_recaudo')) {
            return Decision::denegar('Solo el Grupo de Recaudo registra recaudos.');
        }
        if ($recurso === null) {
            return Decision::permitir();
        }
        // Un recaudo sin unidad (pendiente de clasificar) solo es visible con alcance de centro.
        if ($recurso->centroId !== $alcance->centroId || !$alcance->permiteUnidad($recurso->unidadId)) {
            return Decision::fueraDeAlcance();
        }
        if ($escritura && $this->periodos->estaCerrado($recurso->centroId, $recurso->fechaPago)) {
            return Decision::denegar('El periodo está cerrado.');
        }
        return Decision::permitir();
    }
}
```

### 4.7.5 Cifrado autenticado con llavero versionado

```php archivo=app/Nucleo/Cifrador.php
<?php
declare(strict_types=1);

namespace App\Nucleo;

use InvalidArgumentException;
use RuntimeException;

/**
 * Cifrado autenticado XChaCha20-Poly1305 (libsodium) con llavero versionado (RS-013, D-15). El sobre cifrado
 * lleva la versión de la clave: "v2.<base64url(nonce || texto cifrado)>". El contexto (por ejemplo
 * "usuarios.totp:42") se autentica como dato asociado para impedir mover un cifrado de una fila a otra.
 */
final class Cifrador
{
    /** @param array<string,string> $llavero versión => clave de 32 bytes; la primera es la vigente */
    public function __construct(private readonly array $llavero)
    {
        if ($llavero === []) {
            throw new InvalidArgumentException('El llavero de cifrado está vacío.');
        }
    }

    /** Formato del entorno: CLAVES_CIFRADO="v2:BASE64,v1:BASE64" (la primera cifra; todas descifran). */
    public static function desdeEntorno(string $valor): self
    {
        $llavero = [];
        foreach (explode(',', $valor) as $par) {
            [$version, $clave] = array_pad(explode(':', trim($par), 2), 2, '');
            $binaria = base64_decode($clave, true);
            if ($version === '' || $binaria === false || strlen($binaria) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new InvalidArgumentException('Hay una clave inválida en el llavero de cifrado.');
            }
            $llavero[$version] = $binaria;
        }
        return new self($llavero);
    }

    public function cifrar(string $textoPlano, string $contexto): string
    {
        $version = (string) array_key_first($this->llavero);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cifrado = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($textoPlano, $contexto, $nonce, $this->llavero[$version]);
        return $version . '.' . sodium_bin2base64($nonce . $cifrado, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    public function descifrar(string $sobre, string $contexto): string
    {
        [$version, $contenido] = array_pad(explode('.', $sobre, 2), 2, '');
        $clave = $this->llavero[$version] ?? throw new RuntimeException('Versión de clave desconocida.');
        $binario = sodium_base642bin($contenido, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $nonce = substr($binario, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cifrado = substr($binario, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $texto = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cifrado, $contexto, $nonce, $clave);
        if ($texto === false) {
            throw new RuntimeException('El dato cifrado fue alterado o la clave no corresponde.');
        }
        return $texto;
    }

    /** El trabajo seguridad.recifrar usa esta comprobación para migrar a la clave vigente tras una rotación. */
    public function necesitaRecifrado(string $sobre): bool
    {
        return !str_starts_with($sobre, array_key_first($this->llavero) . '.');
    }
}
```

### 4.7.6 Auditoría encadenada con sellado diferido

> **Decisión D-39.** Encadenar cada evento dentro de la transacción de negocio obligaría a usar un bloqueo global, que serializaría todas las escrituras. En su lugar, el evento se inserta en la misma transacción que el cambio (atomicidad) y `cli/programador.php` lo **sella** cada minuto, siguiendo una secuencia propia. Así se toleran los commits fuera de orden y no hay contención. Un evento queda sin sello durante 3 min como máximo, y la verificación diaria recorre la cadena completa (RAU-004).

```php archivo=app/Servicios/ServicioAuditoria.php
<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\GeneradorUlid;
use App\Nucleo\Solicitud;
use App\Politicas\RecursoAuditable;

/**
 * Auditoría funcional de solo inserción (RAU-001 a RAU-007). Registra el evento en la misma transacción del
 * cambio de negocio y lo sella después en una cadena SHA-256 con secuencia propia (D-39), de modo que una
 * alteración directa en la base se detecta en la verificación (VerificarCadena).
 */
final class ServicioAuditoria
{
    private const CLAVES_SENSIBLES = '/contrasena|password|token|secreto|codigo|otp|cookie|clave|semilla/i';

    public function __construct(private readonly BaseDatos $bd, private readonly string $claveHmacPii)
    {
    }

    /**
     * @param array<string,mixed> $metadatos
     * @param array<string,mixed>|null $antes
     * @param array<string,mixed>|null $despues
     */
    public function registrar(
        Solicitud $solicitud,
        string $accion,
        string $resultado,
        ?object $recurso = null,
        array $metadatos = [],
        ?array $antes = null,
        ?array $despues = null,
        ?string $motivo = null,
    ): void {
        $usuario = $solicitud->atributo('usuario');
        $auditable = $recurso instanceof RecursoAuditable ? $recurso : null;
        $this->bd->insertar(
            'INSERT INTO eventos_auditoria (evento_id, ocurrido_en, request_id, actor_id, actor_rol, centro_id, unidad_id,
                                            accion, recurso, recurso_id, resultado, origen, ip_tratada, ip_hmac, agente,
                                            valores_antes, valores_despues, motivo, metadatos)
             VALUES (:evento, UTC_TIMESTAMP(3), :request, :actor, :rol, :centro, :unidad, :accion, :recurso, :recurso_id,
                     :resultado, :origen, :ip, :ip_hmac, :agente, :antes, :despues, :motivo, :metadatos)',
            [
                'evento' => GeneradorUlid::generar(),
                'request' => $solicitud->atributo('request_id'),
                'actor' => $usuario?->id,
                'rol' => $usuario?->rolPrincipal(),
                'centro' => $auditable?->centroId() ?? $usuario?->centroId,
                'unidad' => $auditable?->unidadId(),
                'accion' => mb_substr($accion, 0, 80),
                'recurso' => $auditable?->tipoRecurso(),
                'recurso_id' => $auditable?->idRecurso(),
                'resultado' => $resultado,
                'origen' => PHP_SAPI === 'cli' ? 'cli' : ($solicitud->esHtmx() ? 'htmx' : 'web'),
                'ip' => $this->ipTratada($solicitud->ip),
                'ip_hmac' => $solicitud->ip === '' ? null : hash_hmac('sha256', $solicitud->ip, $this->claveHmacPii),
                'agente' => mb_substr((string) $solicitud->cabecera('User-Agent'), 0, 255),
                'antes' => $antes === null ? null : json_encode($this->sanitizar($antes), JSON_THROW_ON_ERROR),
                'despues' => $despues === null ? null : json_encode($this->sanitizar($despues), JSON_THROW_ON_ERROR),
                'motivo' => $motivo === null ? null : mb_substr($motivo, 0, 500),
                'metadatos' => json_encode($this->sanitizar($metadatos), JSON_THROW_ON_ERROR),
            ]
        );
    }

    /** Sella en lote los eventos con más de 120 s (cierra la ventana de commits fuera de orden). */
    public function sellarPendientes(int $lote = 500): int
    {
        return $this->bd->transaccion(function (BaseDatos $bd) use ($lote): int {
            $control = $bd->fila('SELECT ultima_secuencia, ultimo_hash FROM cadena_auditoria WHERE id = 1 FOR UPDATE');
            $secuencia = (int) $control['ultima_secuencia'];
            $hash = (string) $control['ultimo_hash'];
            $eventos = $bd->consultar(
                'SELECT * FROM eventos_auditoria
                  WHERE hash_evento IS NULL AND ocurrido_en < UTC_TIMESTAMP(3) - INTERVAL 120 SECOND
                  ORDER BY id LIMIT ' . max(1, min(2000, $lote))
            );
            foreach ($eventos as $evento) {
                $secuencia++;
                $nuevo = hash('sha256', $hash . '|' . $secuencia . '|' . self::canonico($evento));
                $bd->ejecutar(
                    'UPDATE eventos_auditoria SET secuencia_sello = :secuencia, hash_anterior = :anterior, hash_evento = :hash
                      WHERE id = :id AND hash_evento IS NULL',
                    ['secuencia' => $secuencia, 'anterior' => $hash, 'hash' => $nuevo, 'id' => (int) $evento['id']]
                );
                $hash = $nuevo;
            }
            if ($eventos !== []) {
                $bd->ejecutar(
                    'UPDATE cadena_auditoria SET ultima_secuencia = :secuencia, ultimo_hash = :hash WHERE id = 1',
                    ['secuencia' => $secuencia, 'hash' => $hash]
                );
            }
            return count($eventos);
        });
    }

    /** Representación canónica estable del evento (sin las columnas del sello). @param array<string,mixed> $evento */
    public static function canonico(array $evento): string
    {
        $campos = ['id', 'evento_id', 'ocurrido_en', 'request_id', 'actor_id', 'actor_rol', 'centro_id', 'unidad_id', 'accion',
            'recurso', 'recurso_id', 'resultado', 'origen', 'ip_tratada', 'ip_hmac', 'agente', 'valores_antes',
            'valores_despues', 'motivo', 'metadatos'];
        $datos = [];
        foreach ($campos as $campo) {
            $datos[$campo] = $evento[$campo] === null ? null : (string) $evento[$campo];
        }
        return json_encode($datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** IPv4 truncada a /24 e IPv6 a /48 (RAU-002): suficiente para investigar, sin identificar al equipo. */
    private function ipTratada(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return preg_replace('/\.\d+$/', '.0', $ip) . '/24';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $hex = bin2hex((string) inet_pton($ip));
            return implode(':', str_split(substr($hex, 0, 12), 4)) . '::/48';
        }
        return null;
    }

    /**
     * Elimina secretos de los valores auditados (RAU-007).
     *
     * @param array<string,mixed> $valores
     * @return array<string,mixed>
     */
    private function sanitizar(array $valores): array
    {
        foreach ($valores as $clave => $valor) {
            if (preg_match(self::CLAVES_SENSIBLES, (string) $clave) === 1) {
                $valores[$clave] = '[omitido]';
            } elseif (is_array($valor)) {
                $valores[$clave] = $this->sanitizar($valor);
            }
        }
        return $valores;
    }
}
```

### 4.7.7 TOTP propio (RFC 6238)

```php archivo=app/Servicios/ServicioTotp.php
<?php
declare(strict_types=1);

namespace App\Servicios;

use InvalidArgumentException;

/**
 * TOTP según RFC 6238 (SHA-1, 6 dígitos, pasos de 30 s, ventana ±1) implementado sin dependencias (D-37).
 * Vector de prueba (RFC 6238, anexo B): clave ASCII "12345678901234567890", T = 59 s → 287082 (6 dígitos).
 * La anti-reutilización se apoya en el último paso aceptado guardado por usuario (RAC-012).
 */
final class ServicioTotp
{
    private const PASO_SEGUNDOS = 30;
    private const DIGITOS = 6;
    private const VENTANA = 1;
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Semilla de 160 bits en Base32 (RFC 4648). Se cifra antes de guardarla (RS-013). */
    public function generarSemilla(): string
    {
        return self::aBase32(random_bytes(20));
    }

    public function uriAprovisionamiento(string $semilla, string $cuenta, string $emisor = 'Produccion CAB'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($emisor),
            rawurlencode($cuenta),
            $semilla,
            rawurlencode($emisor),
            self::DIGITOS,
            self::PASO_SEGUNDOS
        );
    }

    /** Devuelve el paso aceptado o null. Rechaza pasos iguales o anteriores al último usado. */
    public function verificar(string $semilla, string $codigo, ?int $ultimoPaso, ?int $ahora = null): ?int
    {
        if (preg_match('/^\d{6}$/', $codigo) !== 1) {
            return null;
        }
        $clave = self::desdeBase32($semilla);
        $pasoActual = intdiv($ahora ?? time(), self::PASO_SEGUNDOS);
        for ($desfase = -self::VENTANA; $desfase <= self::VENTANA; $desfase++) {
            $paso = $pasoActual + $desfase;
            if ($ultimoPaso !== null && $paso <= $ultimoPaso) {
                continue;
            }
            if (hash_equals(self::codigoParaPaso($clave, $paso), $codigo)) {
                return $paso;
            }
        }
        return null;
    }

    public static function codigoParaPaso(string $clave, int $paso): string
    {
        $hmac = hash_hmac('sha1', pack('J', $paso), $clave, true);
        $desplazamiento = ord($hmac[19]) & 0x0f;
        $binario = ((ord($hmac[$desplazamiento]) & 0x7f) << 24)
            | ((ord($hmac[$desplazamiento + 1]) & 0xff) << 16)
            | ((ord($hmac[$desplazamiento + 2]) & 0xff) << 8)
            | (ord($hmac[$desplazamiento + 3]) & 0xff);
        return str_pad((string) ($binario % (10 ** self::DIGITOS)), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    private static function aBase32(string $binario): string
    {
        $bits = '';
        foreach (str_split($binario) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $salida = '';
        foreach (str_split($bits, 5) as $grupo) {
            $salida .= self::ALFABETO[(int) bindec(str_pad($grupo, 5, '0'))];
        }
        return $salida;
    }

    private static function desdeBase32(string $texto): string
    {
        $limpio = strtoupper((string) preg_replace('/[\s=]/', '', $texto));
        $bits = '';
        foreach (str_split($limpio) as $caracter) {
            $valor = strpos(self::ALFABETO, $caracter);
            if ($valor === false) {
                throw new InvalidArgumentException('La semilla TOTP no es Base32 válida.');
            }
            $bits .= str_pad(decbin($valor), 5, '0', STR_PAD_LEFT);
        }
        $binario = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binario .= chr((int) bindec($byte));
            }
        }
        return $binario;
    }
}
```
