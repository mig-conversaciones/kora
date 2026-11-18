<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Url;
use App\Politicas\UsuarioSesion;
use DateTimeImmutable;
use Throwable;

/**
 * Modo mantenimiento (RF-MAN-005 a RF-MAN-007, RF-MAN-012 a RF-MAN-016; base: 06-03-anexo-c).
 *
 * El estado autoritativo vive en configuracion_sistema con versión optimista; la señal mínima en
 * almacenamiento/estado/mantenimiento.json permite responder 503 sin base de datos. Orden de escritura: al
 * activar, primero la señal (dirección segura) y luego la base; al desactivar, primero la base y después la
 * señal, de modo que cualquier fallo deja el sistema en mantenimiento y nunca abierto por error.
 *
 * El panel web (RF-MAN-001 a RF-MAN-004) y el acceso excepcional con 2FA (RF-MAN-009) llegan en la I2.
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

    /** @return array<string,mixed>|null */
    public function senalActual(): ?array
    {
        return self::leerSenal((string) $this->config['senal']);
    }

    public function activo(): bool
    {
        return $this->senalActual() !== null;
    }

    /** Ventana posterior a la desactivación con lote reducido en la cola (RF-MAN-016). */
    public function enReanudacion(): bool
    {
        $ruta = (string) $this->config['senal'];
        $datos = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;
        if (!is_array($datos) || ($datos['activo'] ?? false) || empty($datos['desactivado_en'])) {
            return false;
        }
        return time() - (int) strtotime((string) $datos['desactivado_en']) < (int) $this->config['minutos_reanudacion'] * 60;
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
        $config = self::configuracion();
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
     * principal o JSON. Siempre no-store; Retry-After con la estimación si es futura o con el valor por defecto.
     *
     * @param array<string,mixed> $senal
     */
    public static function respuestaAviso(Solicitud $solicitud, array $senal): Respuesta
    {
        $config = self::configuracion();
        $titulo = (string) ($senal['titulo'] ?? $config['aviso_por_defecto']['titulo']);
        $mensaje = (string) ($senal['mensaje'] ?? $config['aviso_por_defecto']['mensaje']);
        $estimado = $senal['estimado_hasta'] ?? null;
        if (str_starts_with($solicitud->ruta, '/api/') || str_contains((string) $solicitud->cabecera('Accept'), 'application/json')) {
            $respuesta = Respuesta::json(['estado' => 'mantenimiento', 'titulo' => $titulo, 'mensaje' => $mensaje], 503);
        } else {
            $soloFragmento = $solicitud->esHtmx();
            $rutaBase = Url::base();
            ob_start();
            require RAIZ . '/app/Vistas/errores/mantenimiento.php';
            $respuesta = Respuesta::html((string) ob_get_clean(), 503);
            if ($soloFragmento) {
                $respuesta->conCabecera('HX-Retarget', '#contenido-principal')->conCabecera('HX-Reswap', 'innerHTML');
            }
        }
        $respuesta->conCabecera('Cache-Control', 'no-store')->conCabecera('X-Mantenimiento', 'activo');
        $segundos = $estimado === null ? 0 : (int) strtotime((string) $estimado) - time();
        $respuesta->conCabecera('Retry-After', (string) ($segundos > 0 ? min($segundos, 86400) : (int) $config['retry_after_por_defecto']));
        return $respuesta;
    }

    /**
     * @param array<string,mixed> $entrada motivo, titulo, mensaje, estimado_hasta, version
     * @return array{exito:bool,mensaje:string}
     */
    public function activar(Solicitud $solicitud, array $entrada): array
    {
        $datos = $this->validarConfiguracion($entrada);
        $anterior = $this->estadoActual();
        $actorId = $this->actorId($solicitud);
        $nuevo = [
            'activo' => true,
            'titulo' => $datos['titulo'],
            'mensaje' => $datos['mensaje'],
            'estimado_hasta' => $datos['estimado_hasta'],
            'activado_en' => gmdate('c'),
            'activado_por' => $actorId,
            'origen' => (string) $solicitud->atributo('origen', 'web'),
        ];
        $this->escribirSenal($nuevo);
        try {
            $this->guardarEstado($nuevo, (int) ($entrada['version'] ?? $anterior['version']), $actorId);
        } catch (Throwable $error) {
            $this->escribirSenal(['activo' => false, 'desactivado_en' => gmdate('c')]);
            throw $error;
        }
        $this->auditoria->registrar($solicitud, 'mantenimiento.activar', 'exito', null, ['alcance' => 'global'], $anterior, $nuevo, $datos['motivo']);
        return ['exito' => true, 'mensaje' => 'Modo mantenimiento activado.'];
    }

    /**
     * @param array<string,mixed> $entrada motivo, version
     * @return array{exito:bool,mensaje:string,comprobaciones:list<array{nombre:string,correcta:bool,detalle:string}>}
     */
    public function desactivar(Solicitud $solicitud, array $entrada): array
    {
        $motivo = trim((string) ($entrada['motivo'] ?? ''));
        if ($motivo === '' || mb_strlen($motivo) > 500) {
            throw new ErrorValidacion(['motivo' => 'Indica el motivo de la desactivación (máximo 500 caracteres).']);
        }
        $comprobaciones = $this->verificarSalida();
        if (in_array(false, array_column($comprobaciones, 'correcta'), true)) {
            $this->auditoria->registrar($solicitud, 'mantenimiento.desactivar', 'error', null, ['comprobaciones' => $comprobaciones], null, null, $motivo);
            return ['exito' => false, 'mensaje' => 'Una comprobación crítica falló; el mantenimiento sigue activo.', 'comprobaciones' => $comprobaciones];
        }
        $anterior = $this->estadoActual();
        $nuevo = ['activo' => false, 'desactivado_en' => gmdate('c'), 'titulo' => null, 'mensaje' => null, 'estimado_hasta' => null];
        $this->guardarEstado($nuevo, (int) ($entrada['version'] ?? $anterior['version']), $this->actorId($solicitud));
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
        $edad = self::edadLatido((string) ($this->config['latido_trabajador'] ?? RAIZ . '/almacenamiento/latidos/trabajador.txt'));
        $resultado[] = ['nombre' => 'Trabajador de cola', 'correcta' => $edad < 180, 'detalle' => $edad === PHP_INT_MAX ? 'Sin latido registrado' : "Último latido hace {$edad} s"];
        return $resultado;
    }

    /**
     * Trabajos de negocio aún reservados: «listo para migrar» cuando llegan a cero (RF-MAN-014).
     *
     * @return array{en_curso:int,listo_para_migrar:bool}
     */
    public function estadoDrenaje(): array
    {
        /** @var list<string> $tecnicos */
        $tecnicos = array_values($this->config['tipos_trabajo_permitidos']);
        $marcadores = implode(', ', array_map(static fn (int $i): string => ":t{$i}", array_keys($tecnicos)));
        $parametros = [];
        foreach ($tecnicos as $i => $tipo) {
            $parametros["t{$i}"] = $tipo;
        }
        $enCurso = (int) $this->bd->valor("SELECT COUNT(*) FROM trabajos WHERE estado = 'reservado' AND tipo NOT IN ({$marcadores})", $parametros);
        return ['en_curso' => $enCurso, 'listo_para_migrar' => $enCurso === 0];
    }

    /**
     * Solicitud sintética para la CLI: sin usuario web; la auditoría registra el origen y el motivo (RF-MAN-012).
     */
    public function solicitudDeSistema(string $origen, string $motivo): Solicitud
    {
        return (new Solicitud('CLI', '/cli/mantenimiento', [], ['motivo' => $motivo], [], [], ''))
            ->conAtributo('request_id', bin2hex(random_bytes(16)))
            ->conAtributo('origen', $origen);
    }

    /** @return array<string,mixed> estado guardado más su versión optimista */
    public function estadoActual(): array
    {
        $this->asegurarFila();
        $fila = $this->bd->fila("SELECT valor, version FROM configuracion_sistema WHERE clave = 'mantenimiento'");
        $valor = $fila === null ? [] : json_decode((string) $fila['valor'], true);
        return (is_array($valor) ? $valor : []) + ['version' => (int) ($fila['version'] ?? 0)];
    }

    /** Segundos desde el último latido escrito en $archivo; PHP_INT_MAX si no existe. */
    public static function edadLatido(string $archivo): int
    {
        if (!is_file($archivo)) {
            return PHP_INT_MAX;
        }
        $marca = strtotime(trim((string) file_get_contents($archivo)));
        return $marca === false ? PHP_INT_MAX : max(0, time() - $marca);
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
            $errores['estimado_hasta'] = 'Usa fecha y hora válidas con zona horaria (por ejemplo 2027-04-29T22:00:00-05:00).';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        return ['motivo' => $motivo, 'titulo' => $titulo, 'mensaje' => $mensaje, 'estimado_hasta' => $estimado === '' ? null : $estimado];
    }

    /** La fila inicial la crea la semilla de producción; aquí se garantiza para entornos sin semilla completa. */
    private function asegurarFila(): void
    {
        $this->bd->ejecutar(
            "INSERT IGNORE INTO configuracion_sistema (clave, valor) VALUES ('mantenimiento', :valor)",
            ['valor' => '{"activo": false, "titulo": null, "mensaje": null, "estimado_hasta": null}']
        );
    }

    /** @param array<string,mixed> $estado */
    private function guardarEstado(array $estado, int $versionEsperada, ?int $usuarioId): void
    {
        $this->asegurarFila();
        $filas = $this->bd->ejecutar(
            "UPDATE configuracion_sistema SET valor = :valor, version = version + 1, actualizado_por = :usuario
              WHERE clave = 'mantenimiento' AND version = :version",
            ['valor' => json_encode($estado, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'usuario' => $usuarioId, 'version' => $versionEsperada]
        );
        if ($filas !== 1) {
            throw new ErrorHttp(409, 'Otra persona cambió el estado del mantenimiento. Recarga el panel.');
        }
    }

    /**
     * Escritura atómica: archivo temporal en el mismo directorio y rename (RF-MAN-005).
     *
     * @param array<string,mixed> $datos
     */
    private function escribirSenal(array $datos): void
    {
        self::escribirSenalEn((string) $this->config['senal'], $datos);
    }

    /** @param array<string,mixed> $datos */
    public static function escribirSenalEn(string $ruta, array $datos): void
    {
        $temporal = $ruta . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporal, json_encode($datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new ErrorHttp(500, 'No fue posible escribir la señal de mantenimiento.');
        }
        @chmod($temporal, 0640);
        // En Windows rename() no reemplaza de forma atómica si el destino está abierto; se reintenta brevemente.
        for ($intento = 0; $intento < 5; $intento++) {
            if (@rename($temporal, $ruta)) {
                return;
            }
            usleep(50000);
        }
        @unlink($temporal);
        throw new ErrorHttp(500, 'No fue posible escribir la señal de mantenimiento.');
    }

    private function actorId(Solicitud $solicitud): ?int
    {
        $usuario = $solicitud->atributo('usuario');
        return $usuario instanceof UsuarioSesion ? $usuario->id : null;
    }

    /** @return array{senal:string,rutas_exceptuadas:list<string>,prefijos_exceptuados:list<string>,retry_after_por_defecto:int,aviso_por_defecto:array{titulo:string,mensaje:string}} */
    private static function configuracion(): array
    {
        /** @var array{senal:string,rutas_exceptuadas:list<string>,prefijos_exceptuados:list<string>,retry_after_por_defecto:int,aviso_por_defecto:array{titulo:string,mensaje:string}} */
        return require RAIZ . '/config/mantenimiento.php';
    }
}
