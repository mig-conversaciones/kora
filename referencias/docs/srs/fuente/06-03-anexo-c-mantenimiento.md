## Anexo C. Modo mantenimiento administrable

Componentes y su relación con los requisitos:

| Componente | Archivo | Requisitos |
|---|---|---|
| Servicio con señal mínima y estado persistente | `app/Servicios/ServicioMantenimiento.php` | RF-MAN-003, RF-MAN-005, RF-MAN-014, RF-MAN-016 |
| Intermediario central | `app/Intermediarios/Mantenimiento.php` | RF-MAN-006, RF-MAN-007, RF-MAN-009, RF-MAN-011 |
| Panel Sistema → Mantenimiento | `app/Controladores/MantenimientoControlador.php` | RF-MAN-001, RF-MAN-002, RF-MAN-004, RF-MAN-017 |
| Aviso público | `app/Vistas/errores/mantenimiento.php` | RF-MAN-007, RF-MAN-018 |
| Recuperación sin panel | `cli/mantenimiento.php` | RF-MAN-012 |
| Configuración | `config/mantenimiento.php` (anexo A.4) | RF-MAN-010 |
| Manejo en el cliente | `retroalimentacion-ui.js` (4.3.4) | RF-MAN-008 |
| Pausa de la cola | `cli/trabajador_cron.php` (4.4.8) | RF-Q-017 |

### C.1 Servicio de mantenimiento

```php archivo=app/Servicios/ServicioMantenimiento.php
<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use DateTimeImmutable;
use Throwable;

/**
 * Modo mantenimiento (RF-MAN). El estado autoritativo vive en configuracion_sistema con versión optimista; la
 * señal mínima en almacenamiento/estado/mantenimiento.json permite responder 503 sin base de datos. Orden de
 * escritura: al activar, primero la señal (dirección segura) y luego la base; al desactivar, primero la base y
 * después la señal, de modo que cualquier fallo deja el sistema en mantenimiento y nunca abierto por error.
 */
final class ServicioMantenimiento
{
    /** @param array<string,mixed> $config config/mantenimiento.php */
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly ServicioAuditoria $auditoria,
        private readonly array $config,
        private readonly string $versionEsquemaEsperada,
    ) {
    }

    /** @return array<string,mixed>|null datos de la señal si el modo está activo; null en otro caso */
    public static function leerSenal(string $ruta): ?array
    {
        if (!is_file($ruta)) {
            return null;
        }
        $datos = json_decode((string) file_get_contents($ruta), true);
        return is_array($datos) && ($datos['activo'] ?? false) === true ? $datos : null;
    }

    public static function rutaExceptuada(string $ruta): bool
    {
        $config = require RAIZ . '/config/mantenimiento.php';
        if (in_array($ruta, $config['rutas_exceptuadas'], true)) {
            return true;
        }
        foreach ($config['prefijos_exceptuados'] as $prefijo) {
            if (str_starts_with($ruta, $prefijo)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 503 con aviso según el tipo de solicitud (RF-MAN-007): página, fragmento HTMX redirigido al contenedor
     * principal o JSON. Siempre no-store; Retry-After solo con una estimación futura razonable.
     *
     * @param array<string,mixed> $senal
     */
    public static function respuestaAviso(Solicitud $solicitud, array $senal): Respuesta
    {
        $config = require RAIZ . '/config/mantenimiento.php';
        $titulo = (string) ($senal['titulo'] ?? $config['aviso_por_defecto']['titulo']);
        $mensaje = (string) ($senal['mensaje'] ?? $config['aviso_por_defecto']['mensaje']);
        $estimado = $senal['estimado_hasta'] ?? null;
        if (str_starts_with($solicitud->ruta, '/api/') || str_contains((string) $solicitud->cabecera('Accept'), 'application/json')) {
            $respuesta = Respuesta::json(['estado' => 'mantenimiento', 'titulo' => $titulo, 'mensaje' => $mensaje], 503);
        } else {
            $soloFragmento = $solicitud->esHtmx();
            ob_start();
            require RAIZ . '/app/Vistas/errores/mantenimiento.php';
            $respuesta = Respuesta::html((string) ob_get_clean(), 503);
            if ($soloFragmento) {
                $respuesta->conCabecera('HX-Retarget', '#contenido-principal')->conCabecera('HX-Reswap', 'innerHTML');
            }
        }
        $respuesta->conCabecera('Cache-Control', 'no-store')->conCabecera('X-Mantenimiento', 'activo');
        $segundos = $estimado === null ? 0 : (int) strtotime((string) $estimado) - time();
        if ($segundos > 0) {
            $respuesta->conCabecera('Retry-After', (string) min($segundos, 86400));
        }
        return $respuesta;
    }

    public function activo(): bool
    {
        return self::leerSenal($this->config['senal']) !== null;
    }

    /** Ventana posterior a la desactivación con lote reducido en la cola (RF-MAN-016). */
    public function enReanudacion(): bool
    {
        $datos = is_file($this->config['senal']) ? json_decode((string) file_get_contents($this->config['senal']), true) : null;
        if (!is_array($datos) || ($datos['activo'] ?? false) || empty($datos['desactivado_en'])) {
            return false;
        }
        return time() - (int) strtotime((string) $datos['desactivado_en']) < $this->config['minutos_reanudacion'] * 60;
    }

    /** @param array<string,mixed> $entrada */
    public function activar(Solicitud $solicitud, array $entrada): array
    {
        $datos = $this->validarConfiguracion($entrada);
        $anterior = $this->estadoActual();
        $actorId = $solicitud->atributo('usuario')?->id;
        $nuevo = $datos + ['activo' => true, 'activado_en' => gmdate('c'), 'activado_por' => $actorId, 'origen' => $solicitud->atributo('origen', 'web')];
        $this->escribirSenal($nuevo);
        try {
            $this->guardarEstado($nuevo, (int) ($entrada['version'] ?? 0), $actorId);
        } catch (Throwable $error) {
            $this->escribirSenal(['activo' => false, 'desactivado_en' => gmdate('c')]);
            throw $error;
        }
        $this->auditoria->registrar($solicitud, 'mantenimiento.activar', 'exito', null, ['alcance' => 'global'], $anterior, $nuevo, $datos['motivo']);
        return ['exito' => true, 'mensaje' => 'Modo mantenimiento activado.'];
    }

    /** @param array<string,mixed> $entrada */
    public function desactivar(Solicitud $solicitud, array $entrada): array
    {
        $motivo = trim((string) ($entrada['motivo'] ?? ''));
        if ($motivo === '') {
            throw new ErrorValidacion(['motivo' => 'Indica el motivo de la desactivación.']);
        }
        $comprobaciones = $this->verificarSalida();
        if (in_array(false, array_column($comprobaciones, 'correcta'), true)) {
            $this->auditoria->registrar($solicitud, 'mantenimiento.desactivar', 'error', null, ['comprobaciones' => $comprobaciones], null, null, $motivo);
            return ['exito' => false, 'mensaje' => 'Una comprobación crítica falló; el mantenimiento sigue activo.', 'comprobaciones' => $comprobaciones];
        }
        $anterior = $this->estadoActual();
        $nuevo = ['activo' => false, 'desactivado_en' => gmdate('c'), 'titulo' => null, 'mensaje' => null, 'estimado_hasta' => null];
        $this->guardarEstado($nuevo, (int) ($entrada['version'] ?? 0), $solicitud->atributo('usuario')?->id);
        $this->escribirSenal($nuevo);
        $this->auditoria->registrar($solicitud, 'mantenimiento.desactivar', 'exito', null, ['comprobaciones' => $comprobaciones], $anterior, $nuevo, $motivo);
        return ['exito' => true, 'mensaje' => 'Servicio reanudado; las colas se reanudan de forma gradual.', 'comprobaciones' => $comprobaciones];
    }

    /**
     * Comprobaciones de salida (RF-MAN-016): esquema esperado, base de datos y trabajador activos.
     *
     * @return list<array{nombre:string,correcta:bool,detalle:string}>
     */
    public function verificarSalida(): array
    {
        $resultado = [];
        try {
            $aplicada = (string) $this->bd->valor('SELECT MAX(nombre) FROM migraciones_aplicadas');
            $resultado[] = ['nombre' => 'Esquema', 'correcta' => str_starts_with($aplicada, $this->versionEsquemaEsperada), 'detalle' => "Aplicada: {$aplicada}; esperada: {$this->versionEsquemaEsperada}"];
            $resultado[] = ['nombre' => 'Base de datos', 'correcta' => (int) $this->bd->valor('SELECT 1') === 1, 'detalle' => 'Consulta de prueba'];
        } catch (Throwable) {
            $resultado[] = ['nombre' => 'Base de datos', 'correcta' => false, 'detalle' => 'Sin conexión'];
        }
        $latido = RAIZ . '/almacenamiento/latidos/trabajador.txt';
        $edad = is_file($latido) ? time() - (int) strtotime((string) file_get_contents($latido)) : PHP_INT_MAX;
        $resultado[] = ['nombre' => 'Trabajador de cola', 'correcta' => $edad < 180, 'detalle' => "Último latido hace {$edad} s"];
        return $resultado;
    }

    /** Trabajos de negocio aún reservados: el panel indica «Listo para migrar» cuando llegan a cero (RF-MAN-014). */
    public function estadoDrenaje(): array
    {
        $tecnicos = $this->config['tipos_trabajo_permitidos'];
        $marcadores = implode(', ', array_map(static fn (int $i): string => ":t{$i}", array_keys($tecnicos)));
        $parametros = [];
        foreach ($tecnicos as $i => $tipo) {
            $parametros["t{$i}"] = $tipo;
        }
        $enCurso = (int) $this->bd->valor("SELECT COUNT(*) FROM trabajos WHERE estado = 'reservado' AND tipo NOT IN ({$marcadores})", $parametros);
        return ['en_curso' => $enCurso, 'listo_para_migrar' => $enCurso === 0];
    }

    /**
     * Solicitud sintética para operaciones de la CLI: sin usuario web; la auditoría registra el origen «cli» y el
     * motivo. Solo la usa cli/mantenimiento.php, ejecutable únicamente por SSH (RF-MAN-012).
     */
    public function solicitudDeSistema(string $origen, string $motivo): Solicitud
    {
        return (new Solicitud('CLI', '/cli/mantenimiento', [], ['motivo' => $motivo], [], [], ''))
            ->conAtributo('request_id', bin2hex(random_bytes(16)))
            ->conAtributo('origen', $origen);
    }

    /** @return array<string,mixed> */
    public function estadoActual(): array
    {
        $fila = $this->bd->fila("SELECT valor, version FROM configuracion_sistema WHERE clave = 'mantenimiento'");
        return ($fila === null ? [] : (array) json_decode((string) $fila['valor'], true)) + ['version' => (int) ($fila['version'] ?? 0)];
    }

    /**
     * Valida y sanea el aviso público (texto plano con límites, RF-MAN-003).
     *
     * @param array<string,mixed> $entrada
     * @return array{motivo:string,titulo:string,mensaje:string,estimado_hasta:?string}
     */
    private function validarConfiguracion(array $entrada): array
    {
        $errores = [];
        $motivo = trim((string) ($entrada['motivo'] ?? ''));
        $titulo = trim(strip_tags((string) ($entrada['titulo'] ?? $this->config['aviso_por_defecto']['titulo'])));
        $mensaje = trim(strip_tags((string) ($entrada['mensaje'] ?? $this->config['aviso_por_defecto']['mensaje'])));
        $estimado = trim((string) ($entrada['estimado_hasta'] ?? ''));
        if ($motivo === '' || mb_strlen($motivo) > 500) {
            $errores['motivo'] = 'El motivo interno es obligatorio (máximo 500 caracteres).';
        }
        if ($titulo === '' || mb_strlen($titulo) > 120) {
            $errores['titulo'] = 'El título es obligatorio (máximo 120 caracteres).';
        }
        if ($mensaje === '' || mb_strlen($mensaje) > 500) {
            $errores['mensaje'] = 'El mensaje es obligatorio (máximo 500 caracteres).';
        }
        if ($estimado !== '' && DateTimeImmutable::createFromFormat(DATE_ATOM, $estimado) === false) {
            $errores['estimado_hasta'] = 'Usa fecha y hora válidas con zona horaria.';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        return ['motivo' => $motivo, 'titulo' => $titulo, 'mensaje' => $mensaje, 'estimado_hasta' => $estimado === '' ? null : $estimado];
    }

    /** @param array<string,mixed> $estado */
    private function guardarEstado(array $estado, int $versionEsperada, ?int $usuarioId): void
    {
        $filas = $this->bd->ejecutar(
            "UPDATE configuracion_sistema SET valor = :valor, version = version + 1, actualizado_por = :usuario
              WHERE clave = 'mantenimiento' AND version = :version",
            ['valor' => json_encode($estado, JSON_THROW_ON_ERROR), 'usuario' => $usuarioId, 'version' => $versionEsperada]
        );
        if ($filas !== 1) {
            throw new ErrorHttp(409, 'Otra persona cambió el estado del mantenimiento. Recarga el panel.');
        }
    }

    /** Escritura atómica: archivo temporal en el mismo directorio y rename (RF-MAN-005). @param array<string,mixed> $datos */
    private function escribirSenal(array $datos): void
    {
        $ruta = $this->config['senal'];
        $temporal = $ruta . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporal, json_encode($datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX) === false || !rename($temporal, $ruta)) {
            throw new ErrorHttp(500, 'No fue posible escribir la señal de mantenimiento.');
        }
        chmod($ruta, 0640);
    }
}
```

### C.2 Intermediario central

```php archivo=app/Intermediarios/Mantenimiento.php
<?php
declare(strict_types=1);

namespace App\Intermediarios;

use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Solicitud;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioMantenimiento;

/**
 * Aplica el modo mantenimiento a páginas, API, fragmentos HTMX y escrituras, incluso con sesiones abiertas
 * (RF-MAN-006). La señal se lee en cada solicitud, sin caché entre solicitudes (RF-MAN-013). El acceso
 * excepcional exige sesión autenticada con 2FA y el permiso explícito; no se acepta ninguna otra vía:
 * parámetros, cabeceras, cookies no validadas, rutas adivinables ni IP (RF-MAN-009, RF-MAN-011).
 */
final class Mantenimiento implements Intermediario
{
    public function __construct(private readonly Sesion $sesion, private readonly ServicioAuditoria $auditoria)
    {
    }

    public function procesar(Solicitud $solicitud, callable $siguiente): Respuesta
    {
        $senal = ServicioMantenimiento::leerSenal(RAIZ . '/almacenamiento/estado/mantenimiento.json');
        if ($senal === null) {
            return $siguiente($solicitud);
        }
        $ruta = $solicitud->atributo('ruta', []);
        if (!empty($ruta['opciones']['exceptuadaMantenimiento']) || ServicioMantenimiento::rutaExceptuada($solicitud->ruta)) {
            // Ruta exceptuada: continúa, pero los intermediarios de sesión y autorización se siguen aplicando.
            return $siguiente($solicitud->conAtributo('mantenimiento', $senal));
        }
        $usuario = $this->sesion->usuarioAutenticado();
        if ($usuario !== null && $this->sesion->nivelAutenticacion() === '2fa' && $usuario->tienePermiso('mantenimiento.acceso_excepcional')) {
            if (!$this->sesion->obtener('acceso_mantenimiento_auditado')) {
                $this->auditoria->registrar($solicitud, 'mantenimiento.acceso_excepcional', 'exito', null, ['ruta' => $solicitud->ruta]);
                $this->sesion->guardar('acceso_mantenimiento_auditado', true);
            }
            return $siguiente($solicitud->conAtributo('mantenimiento', $senal));
        }
        if ($usuario !== null && $solicitud->esEscritura()) {
            $this->auditoria->registrar($solicitud, 'mantenimiento.operacion_rechazada', 'denegado', null, ['ruta' => $solicitud->ruta]);
        }
        return ServicioMantenimiento::respuestaAviso($solicitud, $senal);
    }
}
```

### C.3 Panel Sistema → Mantenimiento

```php archivo=app/Controladores/MantenimientoControlador.php
<?php
declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Politicas\Autorizador;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioMantenimiento;

/**
 * Panel del modo mantenimiento (RF-MAN-001 a RF-MAN-004). La ruta POST exige permiso, CSRF y reautenticación
 * reciente con 2FA (opción «reautenticacion» de la ruta, RAC-026); el formulario usa data-confirmar (modal).
 */
final class MantenimientoControlador extends ControladorBase
{
    public function __construct(Vista $vista, Autorizador $autorizador, ServicioAuditoria $auditoria, private readonly ServicioMantenimiento $mantenimiento)
    {
        parent::__construct($vista, $autorizador, $auditoria);
    }

    public function mostrar(Solicitud $solicitud): Respuesta
    {
        $this->autorizar($solicitud, 'mantenimiento.gestionar');
        return $this->renderizar($solicitud, 'sistema/mantenimiento', 'sistema/mantenimiento_panel', [
            'estado' => $this->mantenimiento->estadoActual(),
            'drenaje' => $this->mantenimiento->estadoDrenaje(),
            'comprobaciones' => null,
        ]);
    }

    public function cambiar(Solicitud $solicitud): Respuesta
    {
        $this->autorizar($solicitud, 'mantenimiento.gestionar');
        $accion = (string) ($solicitud->cuerpo['accion'] ?? '');
        $datosPanel = ['estado' => $this->mantenimiento->estadoActual(), 'drenaje' => $this->mantenimiento->estadoDrenaje(), 'comprobaciones' => null];
        try {
            $resultado = match ($accion) {
                'activar' => $this->mantenimiento->activar($solicitud, $solicitud->cuerpo),
                'desactivar' => $this->mantenimiento->desactivar($solicitud, $solicitud->cuerpo),
                default => throw new ErrorValidacion(['accion' => 'Acción no reconocida.']),
            };
        } catch (ErrorValidacion $error) {
            return $this->respuestaValidacion($solicitud, $error, 'sistema/mantenimiento_panel', $datosPanel);
        }
        $respuesta = $this->renderizar($solicitud, 'sistema/mantenimiento', 'sistema/mantenimiento_panel', [
            'estado' => $this->mantenimiento->estadoActual(),
            'drenaje' => $this->mantenimiento->estadoDrenaje(),
            'comprobaciones' => $resultado['comprobaciones'] ?? null,
        ]);
        if (!$resultado['exito']) {
            return $this->conToast($respuesta, 'error', 'No se desactivó el mantenimiento: falló una comprobación crítica. Revisa el detalle en el panel.');
        }
        return $this->conToast($respuesta, 'success', $accion === 'activar'
            ? 'Modo mantenimiento activado. Los usuarios ven el aviso y la cola de negocio está en pausa.'
            : 'Servicio reanudado. Las colas se reanudan de forma gradual durante 10 minutos.');
    }
}
```

### C.4 Aviso público

```php archivo=app/Vistas/errores/mantenimiento.php
<?php
/**
 * Aviso público de mantenimiento (RF-MAN-018). No depende de la base de datos ni de la sesión; usa solo recursos
 * estáticos exceptuados. No muestra información interna (motivo, versión, rutas). Variables: $titulo, $mensaje,
 * $estimado (ISO 8601 o null) y $soloFragmento (respuesta HTMX).
 */
$escapar = static fn (?string $texto): string => htmlspecialchars((string) $texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$estimadoTexto = null;
if (!empty($estimado)) {
    try {
        $fecha = (new DateTimeImmutable((string) $estimado))->setTimezone(new DateTimeZone('America/Bogota'));
        $estimadoTexto = $fecha > new DateTimeImmutable() ? $fecha->format('d/m/Y \a \l\a\s H:i') : null;
    } catch (Exception) {
        $estimadoTexto = null;
    }
}
?>
<?php if (empty($soloFragmento)): ?>
<!doctype html>
<html lang="es-CO">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= $escapar($titulo) ?> · Producción CAB</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/mantenimiento/aviso.css">
</head>
<body>
<?php endif; ?>
<main class="aviso-mantenimiento">
    <section class="aviso-tarjeta" role="status" aria-live="polite" aria-labelledby="aviso-titulo">
        <img src="/favicon.svg" alt="" width="56" height="56">
        <h1 id="aviso-titulo"><?= $escapar($titulo) ?></h1>
        <p><?= nl2br($escapar($mensaje)) ?></p>
        <?php if ($estimadoTexto !== null): ?>
            <p class="aviso-estimado">Regreso estimado: <strong><?= $escapar($estimadoTexto) ?></strong> (hora de Colombia).</p>
        <?php else: ?>
            <p class="aviso-estimado">Estamos terminando; gracias por tu paciencia.</p>
        <?php endif; ?>
        <p class="aviso-ayuda">Si necesitas atención urgente, comunícate con la coordinación del centro.</p>
    </section>
</main>
<?php if (empty($soloFragmento)): ?>
</body>
</html>
<?php endif; ?>
```

### C.5 Recuperación sin panel (CLI)

```php archivo=cli/mantenimiento.php
<?php
declare(strict_types=1);

/**
 * Recuperación del modo mantenimiento sin panel (RF-MAN-012), por SSH y solo para el titular de la cuenta:
 *   php cli/mantenimiento.php activar --motivo="Migración 014" [--titulo="..."] [--mensaje="..."] [--hasta="2027-04-29T22:00:00-05:00"]
 *   php cli/mantenimiento.php desactivar --motivo="Fin de la migración" [--forzar-sin-bd]
 *   php cli/mantenimiento.php estado
 * Con la base de datos caída opera solo sobre la señal mínima y deja el evento en
 * almacenamiento/estado/eventos_pendientes.jsonl; el programador lo vuelca a la auditoría al recuperarse.
 */

use App\Nucleo\Contenedor;
use App\Nucleo\Entorno;
use App\Servicios\ServicioMantenimiento;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('RAIZ', dirname(__DIR__));
require RAIZ . '/vendor/autoload.php';
Entorno::cargar(RAIZ . '/.env');

$config = require RAIZ . '/config/mantenimiento.php';
$accion = $argv[1] ?? 'estado';
$opciones = getopt('', ['motivo:', 'titulo:', 'mensaje:', 'hasta:', 'forzar-sin-bd']);
$ahora = gmdate('c');

$escribirSenal = static function (array $datos) use ($config): void {
    $temporal = $config['senal'] . '.' . bin2hex(random_bytes(6)) . '.tmp';
    file_put_contents($temporal, json_encode($datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX);
    chmod($temporal, 0640);
    rename($temporal, $config['senal']);
};

if ($accion === 'estado') {
    $senal = ServicioMantenimiento::leerSenal($config['senal']);
    fwrite(STDOUT, $senal === null ? "Mantenimiento: inactivo\n" : 'Mantenimiento: ACTIVO desde ' . ($senal['activado_en'] ?? '¿?') . "\n");
    exit(0);
}

if ($accion === 'drenaje') {
    // Usado por scripts/desplegar.sh: código 0 cuando no quedan trabajos de negocio reservados (RF-MAN-014).
    $drenaje = Contenedor::desdeConfiguracion(require RAIZ . '/config/app.php')->obtener(ServicioMantenimiento::class)->estadoDrenaje();
    fwrite(STDOUT, $drenaje['listo_para_migrar'] ? "listo\n" : "en_curso {$drenaje['en_curso']}\n");
    exit($drenaje['listo_para_migrar'] ? 0 : 3);
}

$motivo = trim((string) ($opciones['motivo'] ?? ''));
if (!in_array($accion, ['activar', 'desactivar'], true) || $motivo === '') {
    fwrite(STDERR, "Uso: php cli/mantenimiento.php activar|desactivar|estado --motivo=\"...\"\n");
    exit(2);
}

try {
    /** @var ServicioMantenimiento $servicio */
    $servicio = Contenedor::desdeConfiguracion(require RAIZ . '/config/app.php')->obtener(ServicioMantenimiento::class);
    $solicitudCli = $servicio->solicitudDeSistema('cli', $motivo);
    $resultado = $accion === 'activar'
        ? $servicio->activar($solicitudCli, ['motivo' => $motivo, 'titulo' => $opciones['titulo'] ?? null, 'mensaje' => $opciones['mensaje'] ?? null, 'estimado_hasta' => $opciones['hasta'] ?? null, 'version' => $servicio->estadoActual()['version']])
        : $servicio->desactivar($solicitudCli, ['motivo' => $motivo, 'version' => $servicio->estadoActual()['version']]);
    fwrite(STDOUT, $resultado['mensaje'] . PHP_EOL);
    exit($resultado['exito'] ? 0 : 1);
} catch (PDOException) {
    if ($accion === 'desactivar' && !isset($opciones['forzar-sin-bd'])) {
        fwrite(STDERR, "Sin base de datos no se pueden ejecutar las comprobaciones de salida. Use --forzar-sin-bd solo si es imprescindible.\n");
        exit(1);
    }
    $escribirSenal($accion === 'activar'
        ? ['activo' => true, 'activado_en' => $ahora, 'titulo' => $opciones['titulo'] ?? $config['aviso_por_defecto']['titulo'], 'mensaje' => $opciones['mensaje'] ?? $config['aviso_por_defecto']['mensaje'], 'estimado_hasta' => $opciones['hasta'] ?? null, 'origen' => 'cli']
        : ['activo' => false, 'desactivado_en' => $ahora, 'origen' => 'cli']);
    $evento = ['accion' => "mantenimiento.{$accion}", 'motivo' => $motivo, 'operador' => get_current_user() ?: 'cli', 'ocurrido_en' => $ahora, 'sin_bd' => true];
    file_put_contents(RAIZ . '/almacenamiento/estado/eventos_pendientes.jsonl', json_encode($evento, JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
    fwrite(STDOUT, "Señal de mantenimiento actualizada sin base de datos ({$accion}). Evento pendiente de auditar.\n");
    exit(0);
}
```

### C.6 `docs/runbooks/mantenimiento.md`

**Propósito.** Cerrar de forma controlada el servicio de negocio durante migraciones, cargas masivas o incidentes, y reabrirlo con verificaciones. El modo mantenimiento es una indisponibilidad controlada, no un despliegue sin interrupción.

**Quién.** El superadministrador, o quien tenga el permiso delegado `mantenimiento.gestionar`, documentado en el acta de asignación. Para la recuperación por CLI, el titular de la cuenta con acceso SSH.

**Antes de activar**

1. Comunicar la ventana a los usuarios con 48 h de antelación (RNF-006), usando la plantilla de correo «Aviso de mantenimiento» del anexo I.
2. Verificar que no haya una importación aplicándose (Sistema → Colas) y que la copia de seguridad diaria esté al día.
3. Preparar el título, el mensaje público y la hora estimada (zona America/Bogota).

**Activación desde el panel**

1. Ir a Sistema → Mantenimiento y completar el motivo interno, el título, el mensaje y, si aplica, la hora estimada.
2. Pulsar «Activar» y confirmar en el modal. Si se solicita, reautenticarse con 2FA.
3. Comprobar en una ventana privada que la página principal responde 503 con el aviso, y que `/health/ready` con el token responde 503.
4. Esperar el drenaje hasta que el panel indique «Listo para migrar» (sin trabajos de negocio en curso). Los trabajos técnicos de la lista permitida siguen ejecutándose.

**Durante el mantenimiento**

- El personal autorizado entra con su cuenta y 2FA. Ve la franja «Modo mantenimiento activo» y conserva sus permisos habituales: la excepción no los amplía.
- Webhooks y eventos entrantes: no aplica en la v1 (D-26). Con el API futuro, los emisores reciben 503 con `Retry-After` y reintentan.
- Ejecutar el despliegue o la migración con `scripts/desplegar.sh --con-mantenimiento` (anexo D).

**Desactivación**

1. En el panel, pulsar «Desactivar» con un motivo. El sistema verifica la versión del esquema, la base de datos y el latido del trabajador.
2. Si alguna comprobación falla, el modo sigue activo y el panel muestra el detalle. Se corrige la causa y se vuelve a intentar.
3. Si las comprobaciones pasan, la cola se reanuda con lote reducido durante 10 min. Se revisan los pendientes y los posibles duplicados en Sistema → Colas.
4. Ejecutar las pruebas de humo: inicio de sesión técnico, tablero central y consulta. Confirmar a los usuarios que el servicio volvió.

**Recuperación sin panel**, por ejemplo si una migración dejó el panel inaccesible:

1. Entrar por SSH: `cd ~/domains/DOMINIO/produccion_cab/current`.
2. Ver el estado: `php cli/mantenimiento.php estado`.
3. Activar: `php cli/mantenimiento.php activar --motivo="..."`, que funciona aunque la base de datos esté caída.
4. Desactivar: `php cli/mantenimiento.php desactivar --motivo="..."`. Con la base caída exige `--forzar-sin-bd` y deja el evento pendiente de auditar.
5. Cuando la base vuelve, comprobar que el evento pendiente aparece en la auditoría, que el programador lo vuelca automáticamente.

**Verificación y registro.** Cada activación, desactivación, acceso excepcional e intento no autorizado queda en la auditoría (RF-MAN-017). Tras cada ventana se registran la duración, el motivo y las incidencias.

### C.7 Pruebas y criterios de aceptación del modo mantenimiento

| Prueba | Criterio medible | Requisitos | Estado |
|---|---|---|---|
| Cambio autorizado y no autorizado; CSRF | El superadministrador activa con 2FA reciente; otros roles reciben 403; sin CSRF, 403; todo auditado | RF-MAN-001, RF-MAN-002, RF-MAN-017 | PENDIENTE DE EJECUCIÓN |
| 503 en páginas, API y HTMX con sesiones activas | Las tres variantes devuelven 503 con `no-store`, `X-Mantenimiento` y `Retry-After` si hay estimación; un formulario enviado con la sesión abierta no se ejecuta | RF-MAN-006, RF-MAN-007 | PENDIENTE DE EJECUCIÓN |
| Acceso del superadministrador sin omisión de permisos | Con 2FA y el permiso entra; no puede ejecutar operaciones de negocio que su rol no permite | RF-MAN-009 | PENDIENTE DE EJECUCIÓN |
| Intentos de omisión | `?bypass=1`, cabeceras falsas y cookies inventadas devuelven 503 | RF-MAN-011 | PENDIENTE DE EJECUCIÓN |
| Persistencia ante un despliegue | El estado sobrevive al cambio de versión (la señal está en `compartido/`) | RF-MAN-005 | PENDIENTE DE EJECUCIÓN |
| Concurrencia y propagación | Dos activaciones simultáneas: una tiene éxito y la otra recibe 409. La propagación tarda 5 s o menos en el entorno real; sin caché obsoleta al desactivar | RF-MAN-005, RF-MAN-013 | PENDIENTE DE EJECUCIÓN |
| Trabajos sin pérdida ni duplicados | Ninguna importación nueva comienza; las pendientes se conservan; al reanudar se ejecutan una vez | RF-MAN-014, RF-Q-017 | PENDIENTE DE EJECUCIÓN |
| HTMX detiene la consulta periódica | Ninguna solicitud de consulta periódica después del primer 503 | RF-MAN-008 | PENDIENTE DE EJECUCIÓN |
| Recuperación con la base caída y sin panel | La CLI activa y desactiva la señal; el evento aparece en la auditoría al volver la base | RF-MAN-012 | PENDIENTE DE EJECUCIÓN |
| Retorno al servicio con comprobaciones | Con una migración pendiente no se desactiva; con todo correcto, se reanuda con lote reducido | RF-MAN-016 | PENDIENTE DE EJECUCIÓN |
| Aviso accesible | axe sin errores; sin motivo interno ni versiones en el HTML | RF-MAN-018 | PENDIENTE DE EJECUCIÓN |

**Lista de verificación del modo mantenimiento** (plantilla):

| Verificación | Estado |
|---|---|
| Control desde el panel del superadministrador con autorización en el servidor, CSRF y auditoría | Especificado; PENDIENTE DE EJECUCIÓN |
| Aviso configurable y respuesta 503 en páginas, API y HTMX, con acceso excepcional seguro | Especificado; PENDIENTE DE EJECUCIÓN |
| Política de solicitudes en curso, trabajos y webhooks, con persistencia, drenaje y reanudación definidos | Especificado (webhooks: NO APLICA en la v1, política para el API futuro); PENDIENTE DE EJECUCIÓN |
| Estado persistente, caché coherente y recuperación operativa aunque el panel no esté disponible | Especificado; PENDIENTE DE EJECUCIÓN |
| Criterios de aceptación, pruebas y runbook incluidos en el SRS | Incluidos (C.6 y C.7); evidencia PENDIENTE DE EJECUCIÓN |
