### 4.4.7 Contrato de los manejadores

```php archivo=app/Cola/ManejadorTrabajo.php
<?php
declare(strict_types=1);

namespace App\Cola;

/**
 * Contrato de cada tipo de trabajo. La entrega es al-menos-una-vez: el manejador debe ser idempotente
 * para su operación y ámbito (RF-Q-010). Debe reevaluar los permisos del actor antes de producir efectos
 * (RAC-024) y lanzar ErrorTransitorio para reintentar o ErrorDefinitivo para no reintentar.
 */
interface ManejadorTrabajo
{
    /**
     * @param array<string,mixed> $trabajo fila de `trabajos` con `carga` ya decodificada
     * @return array<string,mixed>|null resultado serializable (por ejemplo, ruta privada del archivo generado)
     */
    public function ejecutar(array $trabajo, ContextoTrabajo $contexto): ?array;
}
```

```php archivo=app/Cola/ContextoTrabajo.php
<?php
declare(strict_types=1);

namespace App\Cola;

/**
 * Contexto de ejecución de un trabajo: latido que amplía la reserva e informa progreso, control del
 * presupuesto de tiempo de la ejecución del trabajador y continuación de trabajos por bloques (RF-Q-011).
 */
final class ContextoTrabajo
{
    private bool $continuar = false;

    public function __construct(
        private readonly ConductorCola $conductor,
        private readonly int $id,
        private readonly string $token,
        private readonly float $limite,
        private readonly int $reservaSegundos,
    ) {
    }

    /** @param array<string,mixed>|null $puntoControl */
    public function latido(int $progreso, ?string $mensaje = null, ?array $puntoControl = null): void
    {
        if (!$this->conductor->ampliarReserva($this->id, $this->token, $this->reservaSegundos, $progreso, $mensaje, $puntoControl)) {
            throw new ReservaPerdida('La reserva del trabajo se perdió; se detiene sin efectos adicionales.');
        }
    }

    public function quedaTiempo(float $segundosNecesarios = 5.0): bool
    {
        return microtime(true) + $segundosNecesarios < $this->limite;
    }

    /** El manejador guardó su punto de control y pide seguir en la próxima ejecución sin consumir intento. */
    public function solicitarContinuacion(): void
    {
        $this->continuar = true;
    }

    public function debeContinuar(): bool
    {
        return $this->continuar;
    }
}
```

```php archivo=app/Cola/ErrorTransitorio.php
<?php
declare(strict_types=1);

namespace App\Cola;

use RuntimeException;

/** Fallo recuperable (red, SMTP, bloqueo): se reintenta con espera exponencial o la sugerida por el proveedor. */
final class ErrorTransitorio extends RuntimeException
{
    public function __construct(string $mensaje, public readonly ?int $retardoSugerido = null)
    {
        parent::__construct($mensaje);
    }
}
```

```php archivo=app/Cola/ErrorDefinitivo.php
<?php
declare(strict_types=1);

namespace App\Cola;

use RuntimeException;

/** Fallo no recuperable (validación, permiso revocado, tipo inexistente): va a trabajos_fallidos sin reintentos. */
final class ErrorDefinitivo extends RuntimeException
{
}
```

```php archivo=app/Cola/ReservaPerdida.php
<?php
declare(strict_types=1);

namespace App\Cola;

use RuntimeException;

/** La reserva venció y otro trabajador pudo tomar el trabajo: se abandona sin escribir resultados. */
final class ReservaPerdida extends RuntimeException
{
}
```

### 4.4.8 Trabajador invocado por cron

```php archivo=cli/trabajador_cron.php
<?php
declare(strict_types=1);

/**
 * Trabajador de la cola invocado por el cron de hPanel cada minuto (equivale a cron_worker.php, RF-Q-005).
 * Ciclo: recuperar reservas vencidas → reclamar un lote de forma atómica → ejecutar fuera de la transacción
 * → marcar el resultado verificando la reserva. Se detiene por presupuesto de tiempo, memoria o falta de
 * trabajo; en mantenimiento solo ejecuta tipos técnicos permitidos (RF-Q-017).
 */

use App\Cola\ConductorCola;
use App\Cola\ContextoTrabajo;
use App\Cola\ErrorDefinitivo;
use App\Cola\ErrorTransitorio;
use App\Cola\ManejadorTrabajo;
use App\Cola\ReservaPerdida;
use App\Nucleo\Contenedor;
use App\Nucleo\Entorno;
use App\Nucleo\Registro;
use App\Servicios\ServicioMantenimiento;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('RAIZ', dirname(__DIR__));
require RAIZ . '/vendor/autoload.php';
Entorno::cargar(RAIZ . '/.env');

/** Espera exponencial con variación aleatoria para evitar reintentos sincronizados (RF-Q-009). */
function calcularEspera(int $intento, array $espera): int
{
    $base = min($espera['maximo_segundos'], $espera['base_segundos'] * (2 ** max(0, $intento - 1)));
    return (int) round($base * (1 + (mt_rand() / mt_getrandmax()) * $espera['variacion']));
}

$config = require RAIZ . '/config/cola.php';
$inicio = microtime(true);
$limite = $inicio + $config['presupuesto_segundos'];
$colaSolicitada = $argv[1] ?? 'todas';

// Límite de trabajadores simultáneos por servidor mediante ranuras con flock: una tercera instancia sale
// de inmediato sin reclamar (RF-Q-005).
$ranura = null;
for ($i = 1; $i <= $config['max_trabajadores']; $i++) {
    $archivo = fopen(RAIZ . "/almacenamiento/bloqueos/trabajador-{$i}.lock", 'c');
    if ($archivo !== false && flock($archivo, LOCK_EX | LOCK_NB)) {
        $ranura = $archivo;
        break;
    }
    if ($archivo !== false) {
        fclose($archivo);
    }
}
if ($ranura === null) {
    exit(0);
}

$contenedor = Contenedor::desdeConfiguracion(require RAIZ . '/config/app.php');
/** @var ConductorCola $conductor */
$conductor = $contenedor->obtener(ConductorCola::class);
/** @var Registro $registro */
$registro = $contenedor->obtener(Registro::class);
/** @var ServicioMantenimiento $mantenimiento */
$mantenimiento = $contenedor->obtener(ServicioMantenimiento::class);

file_put_contents(RAIZ . '/almacenamiento/latidos/trabajador.txt', gmdate('c'), LOCK_EX);
$recuperados = $conductor->recuperarVencidos();
$colas = $colaSolicitada === 'todas'
    ? array_values(array_unique(array_column($config['tipos'], 'cola')))
    : [$colaSolicitada];
$procesados = 0;

while (microtime(true) < $limite - 5 && memory_get_usage(true) < $config['memoria_max_mb'] * 1048576) {
    // La señal de mantenimiento se relee en cada vuelta: un cambio se respeta en el siguiente lote.
    $tiposPermitidos = $mantenimiento->activo()
        ? array_keys(array_filter($config['tipos'], static fn (array $definicion): bool => $definicion['tecnico'] === true))
        : null;
    $lote = $mantenimiento->enReanudacion() ? $config['lote_reanudacion'] : $config['lote'];
    $reclamados = [];
    foreach ($colas as $cola) {
        $token = bin2hex(random_bytes(16));
        foreach ($conductor->reclamar($cola, $lote, $token, $config['reserva_segundos'], $tiposPermitidos) as $fila) {
            $reclamados[] = [$fila, $token];
        }
    }
    if ($reclamados === []) {
        break;
    }

    foreach ($reclamados as [$trabajo, $token]) {
        $id = (int) $trabajo['id'];
        $definicion = $config['tipos'][$trabajo['tipo']] ?? null;
        $inicioTrabajo = microtime(true);
        try {
            if ($definicion === null) {
                throw new ErrorDefinitivo('Tipo de trabajo no registrado.');
            }
            $trabajo['carga'] = json_decode((string) $trabajo['carga'], true, 32, JSON_THROW_ON_ERROR);
            $contexto = new ContextoTrabajo($conductor, $id, $token, $limite, $definicion['reserva_segundos']);
            /** @var ManejadorTrabajo $manejador */
            $manejador = $contenedor->obtener($definicion['manejador']);
            // Fuera de la transacción de reclamo: ningún bloqueo de la base se mantiene durante llamadas
            // externas (SMTP, escritura de archivos).
            $resultado = $manejador->ejecutar($trabajo, $contexto);
            if ($contexto->debeContinuar()) {
                $conductor->continuar($id, $token);
            } else {
                $conductor->completar($id, $token, $resultado);
            }
        } catch (ReservaPerdida) {
            $registro->advertencia('cola.reserva_perdida', ['trabajo' => $trabajo['ulid']]);
        } catch (ErrorDefinitivo $error) {
            $conductor->fallar($id, $token, $error->getMessage(), 'definitivo');
        } catch (ErrorTransitorio $error) {
            if ((int) $trabajo['intentos'] >= (int) $trabajo['max_intentos']) {
                $conductor->fallar($id, $token, $error->getMessage(), 'transitorio_agotado');
            } else {
                $conductor->reprogramar($id, $token, $error->retardoSugerido ?? calcularEspera((int) $trabajo['intentos'], $config['espera']), $error->getMessage());
            }
        } catch (Throwable $error) {
            // Error no clasificado: se trata como transitorio con mensaje sanitizado; el detalle va al registro
            // técnico protegido con la correlación (RS-010, RAU-009).
            $mensaje = 'Error inesperado al procesar el trabajo. Código de soporte: ' . ($trabajo['correlacion_id'] ?? $trabajo['ulid']);
            if ((int) $trabajo['intentos'] >= (int) $trabajo['max_intentos']) {
                $conductor->fallar($id, $token, $mensaje, 'transitorio_agotado');
            } else {
                $conductor->reprogramar($id, $token, calcularEspera((int) $trabajo['intentos'], $config['espera']), $mensaje);
            }
            $registro->error('cola.error_inesperado', ['trabajo' => $trabajo['ulid'], 'correlacion' => $trabajo['correlacion_id'], 'clase' => $error::class]);
        }
        $registro->info('cola.trabajo', [
            'trabajo' => $trabajo['ulid'],
            'tipo' => $trabajo['tipo'],
            'duracion_ms' => (int) ((microtime(true) - $inicioTrabajo) * 1000),
        ]);
        $procesados++;
        if (microtime(true) >= $limite - 5) {
            break 2;
        }
    }
}

$registro->info('cola.ejecucion', [
    'procesados' => $procesados,
    'recuperados' => $recuperados,
    'duracion_ms' => (int) ((microtime(true) - $inicio) * 1000),
]);
flock($ranura, LOCK_UN);
fclose($ranura);
```

### 4.4.9 Seguimiento del estado de los trabajos

```php archivo=app/Controladores/ControladorTrabajos.php
<?php
declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Politicas\Autorizador;
use App\Servicios\ServicioArchivosPrivados;
use App\Servicios\ServicioAuditoria;

/**
 * Estado, progreso y resultado de un trabajo (equivale a JobController, RF-Q-012, RF-Q-013). Solo el actor
 * que lo originó (en su centro) o un superadministrador puede consultarlo; para los demás el trabajo no
 * existe (404). La consulta periódica usa espera creciente y se detiene en estado terminal.
 */
final class ControladorTrabajos extends ControladorBase
{
    private const ESPERAS_SEGUNDOS = [2, 3, 5, 8, 13, 15];
    private const TERMINALES = ['completado', 'fallido', 'cancelado'];

    public function __construct(
        Vista $vista,
        Autorizador $autorizador,
        ServicioAuditoria $auditoria,
        private readonly BaseDatos $bd,
        private readonly ServicioArchivosPrivados $archivos,
    ) {
        parent::__construct($vista, $autorizador, $auditoria);
    }

    public function estado(Solicitud $solicitud, string $ulid): Respuesta
    {
        $trabajo = $this->trabajoAutorizado($solicitud, $ulid);
        $terminal = in_array($trabajo['estado'], self::TERMINALES, true);
        $indice = min(count(self::ESPERAS_SEGUNDOS) - 1, max(0, (int) ($solicitud->consulta['intento'] ?? 0)));
        $respuesta = $this->renderizar($solicitud, 'trabajos/pagina_estado', 'trabajos/estado', [
            'trabajo' => $trabajo,
            'espera' => self::ESPERAS_SEGUNDOS[$indice],
            'intento' => $indice + 1,
            'terminal' => $terminal,
        ]);
        if ($terminal && $solicitud->esHtmx()) {
            // 286 indica a htmx que detenga cualquier consulta periódica; el fragmento terminal además no
            // incluye disparador. El mensaje final se emite una sola vez.
            $respuesta->estado = 286;
            $respuesta = $trabajo['estado'] === 'completado'
                ? $this->conToast($respuesta, 'success', 'El proceso terminó correctamente.')
                : $this->conToast($respuesta, 'error', 'El proceso no se pudo completar. Revisa el detalle.');
        }
        return $respuesta;
    }

    public function resultado(Solicitud $solicitud, string $ulid): Respuesta
    {
        $trabajo = $this->trabajoAutorizado($solicitud, $ulid);
        $resultado = json_decode((string) ($trabajo['resultado'] ?? 'null'), true);
        $vigente = is_array($resultado) && isset($resultado['archivo'], $resultado['expira_en'])
            && strtotime($resultado['expira_en'] . ' UTC') > time();
        if ($trabajo['estado'] !== 'completado' || !$vigente) {
            throw new ErrorHttp(404);
        }
        $this->auditoria->registrar($solicitud, 'exportacion.descargar', 'exito', null, ['trabajo' => $ulid]);
        return $this->archivos->descargar($resultado['archivo'], $resultado['nombre_descarga'] ?? 'resultado');
    }

    /** @return array<string,mixed> */
    private function trabajoAutorizado(Solicitud $solicitud, string $ulid): array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $ulid) !== 1) {
            throw new ErrorHttp(404);
        }
        $usuario = $solicitud->atributo('usuario');
        $trabajo = $this->bd->fila('SELECT * FROM trabajos WHERE ulid = :ulid', ['ulid' => $ulid]);
        if ($trabajo === null) {
            throw new ErrorHttp(404);
        }
        $propio = (int) $trabajo['actor_id'] === $usuario->id && (int) $trabajo['centro_id'] === $usuario->centroId;
        if (!$propio && !$usuario->esSuperadministrador()) {
            throw new ErrorHttp(404);
        }
        return $trabajo;
    }
}
```

```php archivo=app/Vistas/trabajos/estado.php
<?php
/**
 * Fragmento de seguimiento de un trabajo (RF-Q-012). Mientras no sea terminal se reemplaza a sí mismo con
 * espera creciente (hx-trigger "load delay"); el evento "reanudar" lo dispara retroalimentacion-ui.js al
 * volver la pestaña visible. En mantenimiento el JS retira el disparador (RF-MAN-008).
 * Variables: $trabajo, $espera, $intento, $terminal. $this es App\Nucleo\Vista.
 */
$terminal = $terminal ?? false;
$ulid = $this->e($trabajo['ulid']);
?>
<div id="trabajo-<?= $ulid ?>" class="estado-trabajo" aria-live="polite"
    <?php if (!$terminal): ?>
     data-consulta-periodica
     hx-get="/trabajos/<?= $ulid ?>?intento=<?= (int) $intento ?>"
     hx-trigger="load delay:<?= (int) $espera ?>s, reanudar"
     hx-swap="outerHTML"
    <?php endif; ?>>
    <?php if ($trabajo['estado'] === 'completado'): ?>
        <p class="estado-trabajo-titulo">Proceso terminado</p>
        <?php if (!empty($trabajo['resultado'])): ?>
            <a class="boton boton-primario" href="/trabajos/<?= $ulid ?>/resultado">Descargar resultado</a>
        <?php endif; ?>
    <?php elseif ($trabajo['estado'] === 'fallido' || $trabajo['estado'] === 'cancelado'): ?>
        <p class="estado-trabajo-titulo">No se pudo completar</p>
        <p><?= $this->e($trabajo['mensaje_error'] ?? 'Revisa el detalle o intenta de nuevo.') ?></p>
    <?php else: ?>
        <p class="estado-trabajo-titulo"><?= $trabajo['estado'] === 'pendiente' ? 'En cola' : 'Procesando' ?></p>
        <progress max="100" value="<?= (int) $trabajo['progreso'] ?>" aria-label="Avance del proceso"><?= (int) $trabajo['progreso'] ?> %</progress>
        <p class="texto-secundario"><?= $this->e($trabajo['mensaje_progreso'] ?? 'Puedes seguir trabajando; te avisaremos al terminar.') ?></p>
    <?php endif; ?>
</div>
```

### 4.4.10 Ejemplo de manejador con cortacircuitos: correo

```php archivo=app/Trabajos/EnviarCorreo.php
<?php
declare(strict_types=1);

namespace App\Trabajos;

use App\Cola\ContextoTrabajo;
use App\Cola\CortaCircuitos;
use App\Cola\ErrorDefinitivo;
use App\Cola\ErrorTransitorio;
use App\Cola\ManejadorTrabajo;
use App\Servicios\CorreoRechazado;
use App\Servicios\ServicioCorreo;
use Throwable;

/**
 * Envía un correo de plantilla. La carga lleva el id del destinatario, no su correo (minimización de PII).
 * Idempotencia: el id del mensaje se guarda como punto de control inmediatamente después de entregarlo al
 * SMTP; un reintento posterior lo detecta y no reenvía. Queda una ventana mínima de duplicado si el proceso
 * cae entre la entrega y el latido, aceptada y documentada (al-menos-una-vez, RF-Q-010).
 */
final class EnviarCorreo implements ManejadorTrabajo
{
    public function __construct(private readonly ServicioCorreo $correo, private readonly CortaCircuitos $circuitos)
    {
    }

    public function ejecutar(array $trabajo, ContextoTrabajo $contexto): ?array
    {
        $punto = json_decode((string) ($trabajo['punto_control'] ?? 'null'), true);
        if (is_array($punto) && !empty($punto['mensaje_id'])) {
            return ['mensaje_id' => $punto['mensaje_id']];
        }
        if (!$this->circuitos->permite('smtp')) {
            throw new ErrorTransitorio('El servicio de correo está en pausa por fallos recientes; se reintentará.', 60);
        }
        $carga = $trabajo['carga'];
        try {
            $mensajeId = $this->correo->enviarPlantilla((string) $carga['plantilla'], (int) $carga['destinatario_id'], (array) ($carga['datos'] ?? []));
        } catch (CorreoRechazado) {
            throw new ErrorDefinitivo('El destinatario o la plantilla del correo no son válidos.');
        } catch (Throwable) {
            $this->circuitos->registrarFallo('smtp');
            throw new ErrorTransitorio('No fue posible contactar el servidor de correo.');
        }
        $this->circuitos->registrarExito('smtp');
        $contexto->latido(100, 'Correo entregado al servidor SMTP.', ['mensaje_id' => $mensajeId]);
        return ['mensaje_id' => $mensajeId];
    }
}
```

```php archivo=app/Cola/CortaCircuitos.php
<?php
declare(strict_types=1);

namespace App\Cola;

use App\Nucleo\BaseDatos;

/**
 * Cortacircuitos con estado compartido en la base de datos, porque PHP no conserva estado entre procesos
 * (D-29, RNF-008): 3 fallos consecutivos abren el circuito 60 s; al vencer, una sola solicitud de prueba
 * (semiabierto) decide si se cierra o se vuelve a abrir.
 */
final class CortaCircuitos
{
    private const UMBRAL_FALLOS = 3;
    private const SEGUNDOS_ABIERTO = 60;

    public function __construct(private readonly BaseDatos $bd)
    {
    }

    public function permite(string $servicio): bool
    {
        $fila = $this->bd->fila('SELECT estado, abierto_hasta FROM estado_circuitos WHERE servicio = :servicio', ['servicio' => $servicio]);
        if ($fila === null || $fila['estado'] === 'cerrado') {
            return true;
        }
        if ($fila['estado'] === 'semiabierto') {
            return false;
        }
        // Abierto y vencido: solo la solicitud que gane la actualización condicional hace la prueba.
        return $this->bd->ejecutar(
            "UPDATE estado_circuitos SET estado = 'semiabierto'
              WHERE servicio = :servicio AND estado = 'abierto' AND abierto_hasta <= UTC_TIMESTAMP(3)",
            ['servicio' => $servicio]
        ) === 1;
    }

    public function registrarExito(string $servicio): void
    {
        $this->bd->ejecutar(
            "INSERT INTO estado_circuitos (servicio, estado, fallos_consecutivos) VALUES (:servicio, 'cerrado', 0)
             ON DUPLICATE KEY UPDATE estado = 'cerrado', fallos_consecutivos = 0, abierto_hasta = NULL",
            ['servicio' => $servicio]
        );
    }

    /**
     * Las asignaciones de ON DUPLICATE KEY UPDATE se evalúan de izquierda a derecha en MySQL y MariaDB
     * (sin el modo SIMULTANEOUS_ASSIGNMENT, que no se activa en config/base_datos.php): cada expresión ve
     * el valor ya actualizado de la anterior.
     */
    public function registrarFallo(string $servicio): void
    {
        $this->bd->ejecutar(
            "INSERT INTO estado_circuitos (servicio, estado, fallos_consecutivos) VALUES (:servicio, 'cerrado', 1)
             ON DUPLICATE KEY UPDATE
                 fallos_consecutivos = fallos_consecutivos + 1,
                 estado = IF(estado = 'semiabierto' OR fallos_consecutivos >= :umbral, 'abierto', estado),
                 abierto_hasta = IF(estado = 'abierto', UTC_TIMESTAMP(3) + INTERVAL :segundos SECOND, abierto_hasta)",
            ['servicio' => $servicio, 'umbral' => self::UMBRAL_FALLOS, 'segundos' => self::SEGUNDOS_ABIERTO]
        );
    }
}
```

### 4.4.11 Programación en el cron de hPanel y dimensionamiento

| Entrada de cron (hPanel → Avanzado → Tareas cron) | Frecuencia | Propósito |
|---|---|---|
| `/usr/bin/php /home/USUARIO/domains/DOMINIO/produccion_cab/current/cli/trabajador_cron.php` | Cada minuto (`* * * * *`) | Trabajador de la cola |
| `sleep 30; /usr/bin/php /home/USUARIO/domains/DOMINIO/produccion_cab/current/cli/trabajador_cron.php` | Cada minuto (opcional) | Segunda ejecución desfasada: espera efectiva de 30 s o menos |
| `/usr/bin/php /home/USUARIO/domains/DOMINIO/produccion_cab/current/cli/programador.php` | Cada minuto | Encola las tareas periódicas: limpieza, verificación de la cadena, integridad, copias y alertas |

> **Advertencia.** La ruta del binario de PHP en el cron, la versión (8.3) y la frecuencia mínima real se verifican en hPanel en la iteración 0. Hostinger documenta `* * * * *`, pero puede limitar la duración de los procesos cron. Si el plan no permitiera la frecuencia de un minuto o terminara los procesos antes de 55 s, se reduciría `COLA_PRESUPUESTO_SEGUNDOS` y se documentaría el nuevo SLO de espera. Si el negocio exigiera una espera menor de 30 s, el plan Cloud sería incompatible y haría falta un VPS con trabajador permanente (systemd), según la evolución de RNF-020.

**Dimensionamiento (supuestos de S-07, PENDIENTE DE VALIDACIÓN con PCA):**

| Concepto | Valor | Cálculo o justificación |
|---|---|---|
| Entrada sostenida prevista | Unos 200 trabajos por día y picos de 50 trabajos en 10 min (cierre de mes) | Correos, exportaciones e importaciones del perfil de carga |
| Ventana útil por ejecución | 45 s | Presupuesto de 50 s menos 5 s de margen de cierre |
| Duración típica por tipo | Correo de 1 a 2 s; exportación XLSX de 5 a 15 s; bloque de importación de 500 filas de 5 a 10 s; PDF de 10 a 30 s | Medición pendiente en la I7 |
| Capacidad de vaciado (2 ejecuciones por minuto) | Unos 45 correos por minuto, o 6 a 9 bloques de importación por minuto | 45 s / 1,5 s × 1 trabajador por ranura; ranuras = 2 |
| Margen frente al pico | Más de 10 veces | 50 trabajos en 10 min frente a una capacidad de más de 450 en 10 min |
| Importación de 5.000 filas | 10 bloques; entre 1,5 y 3 min de trabajo, más la espera en cola | Cumple el SLO de 5 min o menos (RNF-PERF-004) |
| Conexiones de base de datos | 1 por trabajador (2 como máximo), más las solicitudes web | Muy por debajo del límite por usuario del plan, que debe verificarse |

### 4.4.12 Pruebas obligatorias de la cola

| Prueba | Escenario | Resultado esperado | Requisito |
|---|---|---|---|
| Correo en segundo plano | Crear un usuario, lo que encola una invitación en la misma transacción | Responde 202 o 201 sin esperar al SMTP; el correo sale en la siguiente ejecución; si la transacción se revierte, no queda el trabajo | RF-Q-004 |
| Reporte en segundo plano | Exportación XLSX de 20.000 filas | Responde 202 en 400 ms o menos; el progreso avanza; la descarga autorizada solo funciona para el solicitante | RF-Q-002, RF-Q-013 |
| Dos trabajadores concurrentes | 1.000 trabajos, 2 procesos simultáneos | Cada trabajo se ejecuta una vez; sin interbloqueos; con la estrategia alternativa, igual resultado | RF-Q-006 |
| Solicitud duplicada | Dos POST simultáneos con la misma `X-Idempotency-Key` | Un único trabajo; ambas respuestas devuelven el mismo ulid | RF-Q-003 |
| Caída del trabajador | Terminar el proceso (`kill -9`) a mitad de un trabajo | El trabajo vuelve a pendiente en 3 min o menos; se completa sin efectos duplicados verificables | RF-Q-007, RF-Q-010 |
| Reserva perdida | Pausar un trabajador más allá de su reserva y reanudarlo | Su `completar` devuelve false; el resultado del otro trabajador se conserva | RF-Q-008 |
| Fallo del proveedor (SMTP) | Servidor SMTP de prueba que rechaza conexiones | El circuito se abre tras 3 fallos; los trabajos se reprograman con espera; la UI muestra el estado degradado; al recuperarse, se vacía la cola | RNF-008, RF-Q-009 |
| Fallo de Redis | NO APLICA: no hay Redis en Cloud (D-12). Se sustituye por: base de datos no disponible durante el reclamo | El trabajador termina sin perder trabajos; la siguiente ejecución continúa | RF-Q-018 |
| Mantenimiento | Activar el modo con trabajos pendientes y en curso | No se reclaman trabajos de negocio; los técnicos siguen; al desactivar, se reanuda con un lote reducido | RF-Q-017, RF-MAN-014 |
| Recuperación y vaciado | Pico de 200 trabajos en 2 min | La cola vuelve a 0 en 10 min o menos; la espera p95 es de 90 s o menos | RNF-PERF-004, RNF-PERF-006 |

### 4.4.13 Lista de verificación de colas

| Verificación | Estado en este SRS | Evidencia esperada |
|---|---|---|
| Cron y límites verificados | Especificado; PENDIENTE DE EJECUCIÓN (I0 e I1) | Captura de hPanel, prueba de duración de 55 s y latido |
| Reclamo atómico y reservas | Especificado (RF-Q-006 a RF-Q-008) | PI-RF-Q-006 y PI-RF-Q-008 |
| Reintentos acotados y deduplicación | Especificado (RF-Q-003, RF-Q-009) | PI-RF-Q-003 y PU-RF-Q-009 |
| Consulta periódica autorizada y detenida | Especificado (RF-Q-012) | PE-RF-Q-012 con contador de solicitudes |
| Respaldo sin pérdida | Especificado (RF-Q-001, RF-Q-018) | Prueba de caída del trabajador y de la base de datos |
| Espera y vaciado medidos | Especificado (RNF-PERF-004, RNF-PERF-006) | Informe k6 con métricas de la cola: PENDIENTE DE EJECUCIÓN |
