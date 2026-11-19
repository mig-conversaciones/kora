<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Cola\ConductorColaMysql;
use App\Cola\ContextoTrabajo;
use App\Cola\CortaCircuitos;
use App\Cola\DespachadorCola;
use App\Cola\ErrorDefinitivo;
use App\Nucleo\BaseDatos;
use App\Nucleo\Cache;
use App\Nucleo\ErrorHttp;
use App\Nucleo\Migrador;
use App\Nucleo\Registro;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Servicios\Programador;
use App\Servicios\ServicioAdministracionCola;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCorreo;
use App\Servicios\ServicioMantenimiento;
use App\Servicios\ServicioMonitorCola;
use App\Trabajos\EnviarCorreo;
use App\Trabajos\LimpiezaPeriodica;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Servicios de plataforma de la I1: mantenimiento con estado y señal (RF-MAN-005, RF-MAN-012, RF-MAN-016),
 * programador y alertas (ROB-010, RF-Q-016), correo idempotente (RF-Q-010), limpieza (RF-Q-015), caché (ADR-010),
 * auditoría mínima (RAU-001, RAU-002) y administración de la cola (RF-Q-014).
 */
final class PlataformaTest extends TestCase
{
    private static BaseDatos $bd;
    private string $temporal;
    private Registro $registro;
    private ServicioAuditoria $auditoria;

    public static function setUpBeforeClass(): void
    {
        self::$bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
        (new Migrador(self::$bd, RAIZ . '/bd/migraciones'))->aplicar();
    }

    protected function setUp(): void
    {
        foreach (['trabajos_fallidos', 'trabajos', 'estado_circuitos', 'eventos_auditoria', 'configuracion_sistema', 'notificaciones', 'usuarios_roles', 'usuarios', 'roles'] as $tabla) {
            self::$bd->ejecutar("DELETE FROM {$tabla}");
        }
        $this->temporal = sys_get_temp_dir() . '/kora-plataforma-' . bin2hex(random_bytes(4));
        mkdir($this->temporal . '/estado', 0777, true);
        mkdir($this->temporal . '/latidos', 0777, true);
        mkdir($this->temporal . '/registros', 0777, true);
        $this->registro = new Registro($this->temporal . '/registros');
        $this->auditoria = new ServicioAuditoria(self::$bd, $this->registro);
    }

    protected function tearDown(): void
    {
        $this->borrarDirectorio($this->temporal);
    }

    public function testActivarYDesactivarMantenimientoConSenalEstadoYAuditoria(): void
    {
        $servicio = $this->mantenimiento();
        $solicitud = $servicio->solicitudDeSistema('cli', 'Migración de prueba');
        $servicio->activar($solicitud, ['motivo' => 'Migración de prueba', 'titulo' => 'Volvemos pronto', 'mensaje' => 'Actualizando KORA.']);
        self::assertTrue($servicio->activo());
        self::assertTrue($servicio->estadoActual()['activo']);

        // Sin latido del trabajador la salida se bloquea y el sistema sigue en mantenimiento (RF-MAN-016).
        $fallida = $servicio->desactivar($solicitud, ['motivo' => 'Fin']);
        self::assertFalse($fallida['exito']);
        self::assertTrue($servicio->activo());

        $this->escribirLatidoDelTrabajadorReal();
        $exitosa = $servicio->desactivar($solicitud, ['motivo' => 'Fin']);
        self::assertTrue($exitosa['exito'], json_encode($exitosa['comprobaciones']) ?: '');
        self::assertFalse($servicio->activo());
        self::assertTrue($servicio->enReanudacion(), 'Tras desactivar, la cola se reanuda con lote reducido.');

        $acciones = array_column(self::$bd->consultar('SELECT accion, resultado, origen, motivo FROM eventos_auditoria ORDER BY id'), 'resultado', 'accion');
        self::assertSame(['mantenimiento.activar' => 'exito', 'mantenimiento.desactivar' => 'exito'], $acciones);
        self::assertSame('cli', self::$bd->valor('SELECT origen FROM eventos_auditoria LIMIT 1'));
    }

    public function testMantenimientoConVersionDesactualizadaDevuelve409(): void
    {
        $servicio = $this->mantenimiento();
        $solicitud = $servicio->solicitudDeSistema('cli', 'Prueba');
        $version = (int) $servicio->estadoActual()['version'];
        $servicio->activar($solicitud, ['motivo' => 'Primera', 'version' => $version]);
        $this->expectException(ErrorHttp::class);
        $servicio->activar($solicitud, ['motivo' => 'Segunda con versión vieja', 'version' => $version]);
    }

    public function testCliDeMantenimientoSinBaseOperaSobreLaSenalYElEventoSeVuelcaDespues(): void
    {
        $pendientes = $this->temporal . '/estado/eventos_pendientes.jsonl';
        file_put_contents($pendientes, json_encode(['accion' => 'mantenimiento.activar', 'motivo' => 'Base caída', 'operador' => 'titular', 'ocurrido_en' => gmdate('c')]) . PHP_EOL);
        self::assertSame(1, $this->auditoria->volcarPendientes($pendientes));
        self::assertFileDoesNotExist($pendientes);
        self::assertSame('Base caída', self::$bd->valor("SELECT motivo FROM eventos_auditoria WHERE accion = 'mantenimiento.activar'"));
    }

    public function testProgramadorEncolaLimpiezaUnaVezPorPeriodoYAlertaConSilencio(): void
    {
        $programador = $this->programador();
        $ahora = new DateTimeImmutable('2027-01-12 08:10:00', new DateTimeZone('America/Bogota'));
        $primera = $programador->ejecutar($ahora);
        self::assertArrayHasKey('limpieza_horaria', $primera);
        self::assertArrayHasKey('limpieza_diaria', $primera);
        $segunda = $programador->ejecutar($ahora->modify('+1 minute'));
        self::assertArrayNotHasKey('limpieza_horaria', $segunda, 'La misma hora no se repite.');
        self::assertSame(2, (int) self::$bd->valor("SELECT COUNT(*) FROM trabajos WHERE tipo = 'mantenimiento.limpieza'"));

        // Sin latido del trabajador: alerta una vez y silencio de 30 minutos (RF-Q-016).
        self::assertContains('trabajador_sin_latido', $primera['alertas']);
        self::assertNotContains('trabajador_sin_latido', $segunda['alertas']);
        $despues = $programador->vigilarCola($ahora->modify('+31 minutes'));
        self::assertContains('trabajador_sin_latido', $despues);
        self::assertGreaterThan(0, (int) self::$bd->valor("SELECT COUNT(*) FROM trabajos WHERE tipo = 'correo.sistema'"), 'La alerta va también al buzón de operaciones.');
    }

    public function testAlertaDeColaAtascada(): void
    {
        $this->despachador()->encolar('demostracion.progreso', ['pasos' => 1]);
        self::$bd->ejecutar('UPDATE trabajos SET disponible_en = UTC_TIMESTAMP(3) - INTERVAL 10 MINUTE');
        $alertas = $this->programador()->vigilarCola(new DateTimeImmutable());
        self::assertContains('cola_atascada', $alertas);
    }

    public function testCorreoSeGuardaEnArchivoYNoSeReenviaEnUnReintento(): void
    {
        self::$bd->ejecutar("INSERT INTO usuarios (ulid, correo, nombre, estado) VALUES ('01JPRUEBA0000000000000000A', 'lider@kora.local', 'Líder de Ganadería', 'activo')");
        $usuarioId = (int) self::$bd->valor('SELECT id FROM usuarios');
        $correo = new ServicioCorreo(self::$bd, $this->configCorreo());
        $manejador = new EnviarCorreo($correo, new CortaCircuitos(self::$bd), \App\Nucleo\Cifrador::desdeEntorno(\App\Nucleo\Entorno::texto('CLAVES_CIFRADO')));
        $trabajo = $this->despachador()->encolar('correo.enviar', ['plantilla' => 'prueba', 'destinatario_id' => $usuarioId]);
        $conductor = new ConductorColaMysql(self::$bd, 'actualizacion_condicional');
        [$fila] = $conductor->reclamar('correo', 1, str_repeat('c', 32), 60);
        $fila['carga'] = json_decode((string) $fila['carga'], true);
        $contexto = new ContextoTrabajo($conductor, (int) $fila['id'], str_repeat('c', 32), microtime(true) + 30, 60);

        $resultado = $manejador->ejecutar($fila, $contexto);
        $archivos = glob($this->temporal . '/correos/*.eml') ?: [];
        self::assertCount(1, $archivos);
        $mensaje = (string) file_get_contents($archivos[0]);
        self::assertStringContainsString('lider@kora.local', $mensaje);
        self::assertStringContainsString('Prueba de correo de KORA', $mensaje);

        // Reintento tras el envío: el punto de control evita el duplicado (RF-Q-010).
        $fila['punto_control'] = self::$bd->valor('SELECT punto_control FROM trabajos WHERE ulid = :u', ['u' => $trabajo['ulid']]);
        $segunda = $manejador->ejecutar($fila, $contexto);
        self::assertSame($resultado['mensaje_id'], $segunda['mensaje_id']);
        self::assertCount(1, glob($this->temporal . '/correos/*.eml') ?: []);
    }

    public function testCorreoAOperacionesSoloDesdeCorreoDelSistema(): void
    {
        $manejador = new EnviarCorreo(new ServicioCorreo(self::$bd, $this->configCorreo()), new CortaCircuitos(self::$bd), \App\Nucleo\Cifrador::desdeEntorno(\App\Nucleo\Entorno::texto('CLAVES_CIFRADO')));
        $conductor = new ConductorColaMysql(self::$bd, 'actualizacion_condicional');
        $contexto = new ContextoTrabajo($conductor, 0, 'x', microtime(true) + 30, 60);
        $this->expectException(ErrorDefinitivo::class);
        $manejador->ejecutar(['tipo' => 'correo.enviar', 'carga' => ['plantilla' => 'prueba', 'destinatario_id' => 'operaciones']], $contexto);
    }

    public function testLimpiezaDiariaRespetaLaRetencion(): void
    {
        $despachador = $this->despachador();
        $viejo = $despachador->encolar('demostracion.progreso', ['pasos' => 1]);
        $reciente = $despachador->encolar('demostracion.progreso', ['pasos' => 1]);
        self::$bd->ejecutar("UPDATE trabajos SET estado = 'completado', finalizado_en = UTC_TIMESTAMP(3) - INTERVAL 15 DAY WHERE ulid = :u", ['u' => $viejo['ulid']]);
        self::$bd->ejecutar("UPDATE trabajos SET estado = 'completado', finalizado_en = UTC_TIMESTAMP(3) - INTERVAL 1 DAY WHERE ulid = :u", ['u' => $reciente['ulid']]);
        $config = require RAIZ . '/config/cola.php';
        $limpieza = new LimpiezaPeriodica(self::$bd, new Cache($this->temporal . '/cache'), $config, 1048576, $this->temporal . '/correos');
        $resultado = $limpieza->ejecutar(['carga' => ['alcance' => 'diaria']], $this->contextoReservado());
        self::assertSame(1, $resultado['trabajos_completados']);
        self::assertSame([$reciente['ulid']], array_column(self::$bd->consultar("SELECT ulid FROM trabajos WHERE tipo = 'demostracion.progreso'"), 'ulid'));
    }

    public function testCacheRecuerdaYLimpiaLosVencidos(): void
    {
        $cache = new Cache($this->temporal . '/cache');
        $calculos = 0;
        $calcular = static function () use (&$calculos): array {
            $calculos++;
            return ['total' => 42];
        };
        self::assertSame(['total' => 42], $cache->recordar('tablero:1:v7', 60, $calcular));
        $cache->recordar('tablero:1:v7', 60, $calcular);
        self::assertSame(1, $calculos, 'El segundo acceso es un acierto.');
        $cache->recordar('tablero:1:v8', 60, $calcular);
        self::assertSame(2, $calculos, 'Otra versión de datos es otra clave (ADR-010).');
        foreach (glob($this->temporal . '/cache/*/*.json') ?: [] as $archivo) {
            file_put_contents($archivo, json_encode(['expira' => time() - 3600, 'valor' => 1]));
        }
        self::assertSame(2, $cache->limpiar(1048576));
    }

    public function testAuditoriaTrataLasIp(): void
    {
        self::assertSame('190.85.12.0', ServicioAuditoria::tratarIp('190.85.12.201'));
        self::assertSame('2001:db8:85a3::/48', ServicioAuditoria::tratarIp('2001:db8:85a3:8d3:1319:8a2e:370:7348'));
        self::assertNull(ServicioAuditoria::tratarIp('no-es-ip'));
        $solicitud = (new Solicitud('GET', '/', [], [], [], [], '190.85.12.201'))->conAtributo('request_id', str_repeat('a', 32));
        $this->auditoria->registrar($solicitud, 'prueba.ip', 'exito');
        $fila = self::$bd->fila('SELECT ip_tratada, ip_hmac, request_id FROM eventos_auditoria');
        self::assertSame('190.85.12.0', $fila['ip_tratada']);
        self::assertSame(64, strlen((string) $fila['ip_hmac']));
        self::assertSame(str_repeat('a', 32), $fila['request_id']);
    }

    public function testAdministracionDeLaColaReintentaUnFallidoConAuditoria(): void
    {
        $trabajo = $this->despachador()->encolar('demostracion.progreso', ['pasos' => 1]);
        $conductor = new ConductorColaMysql(self::$bd, 'actualizacion_condicional');
        [$fila] = $conductor->reclamar('predeterminada', 1, str_repeat('f', 32), 60);
        $conductor->fallar((int) $fila['id'], str_repeat('f', 32), 'Fallo de prueba', 'definitivo');
        $administracion = new ServicioAdministracionCola(self::$bd, $this->auditoria);
        $solicitud = (new Solicitud('CLI', '/cli/cola', [], [], [], [], ''))->conAtributo('origen', 'cli');
        $administracion->reintentar($solicitud, $trabajo['ulid'], 'Proveedor restablecido');
        $estado = self::$bd->fila('SELECT estado, intentos FROM trabajos');
        self::assertSame(['estado' => 'pendiente', 'intentos' => 0], ['estado' => $estado['estado'], 'intentos' => (int) $estado['intentos']]);
        self::assertSame('cola.reintentar', self::$bd->valor('SELECT accion FROM eventos_auditoria'));
        $this->expectException(ErrorHttp::class);
        $administracion->reintentar($solicitud, $trabajo['ulid'], 'Otra vez');
    }

    private function mantenimiento(): ServicioMantenimiento
    {
        return new ServicioMantenimiento(self::$bd, $this->auditoria, [
            'senal' => $this->temporal . '/estado/mantenimiento.json',
            'latido_trabajador' => $this->temporal . '/latidos/trabajador.txt',
        ] + require RAIZ . '/config/mantenimiento.php', (require RAIZ . '/config/app.php')['version_esquema']);
    }

    private function escribirLatidoDelTrabajadorReal(): void
    {
        file_put_contents($this->temporal . '/latidos/trabajador.txt', gmdate('c'));
    }

    /** Contexto sobre un trabajo realmente reservado (los latidos exigen una reserva vigente). */
    private function contextoReservado(): ContextoTrabajo
    {
        $this->despachador()->encolar('mantenimiento.limpieza', ['alcance' => 'prueba']);
        $conductor = new ConductorColaMysql(self::$bd, 'actualizacion_condicional');
        [$fila] = $conductor->reclamar('predeterminada', 1, str_repeat('r', 32), 60, ['mantenimiento.limpieza']);
        return new ContextoTrabajo($conductor, (int) $fila['id'], str_repeat('r', 32), microtime(true) + 30, 60);
    }

    private function despachador(): DespachadorCola
    {
        $vista = new Vista(['directorio' => RAIZ . '/app/Vistas', 'manifiesto' => '', 'nombre_app' => 'KORA', 'entorno' => 'local', 'menu' => [], 'config_htmx' => []]);
        return new DespachadorCola(new ConductorColaMysql(self::$bd, 'actualizacion_condicional'), $vista, require RAIZ . '/config/cola.php');
    }

    private function programador(): Programador
    {
        return new Programador(
            self::$bd,
            $this->despachador(),
            new ServicioMonitorCola(self::$bd, $this->temporal . '/latidos'),
            $this->auditoria,
            $this->registro,
            require RAIZ . '/config/cola.php',
            $this->temporal . '/estado',
            $this->temporal . '/registros',
            'America/Bogota',
            true,
        );
    }

    /** @return array{transporte:string,host:string,puerto:int,cifrado:string,usuario:string,contrasena:string,remitente:string,nombre_remitente:string,buzon_operaciones:string,directorio_archivo:string,url_aplicacion:string} */
    private function configCorreo(): array
    {
        return [
            'transporte' => 'archivo', 'host' => '', 'puerto' => 465, 'cifrado' => 'ssl', 'usuario' => '', 'contrasena' => '',
            'remitente' => 'notificaciones@kora.local', 'nombre_remitente' => 'KORA', 'buzon_operaciones' => 'operaciones@kora.local',
            'directorio_archivo' => $this->temporal . '/correos', 'url_aplicacion' => 'http://localhost/dashboard_laila',
        ];
    }

    private function borrarDirectorio(string $directorio): void
    {
        if (!is_dir($directorio)) {
            return;
        }
        $elementos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directorio, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($elementos as $elemento) {
            $elemento->isDir() ? rmdir($elemento->getPathname()) : unlink($elemento->getPathname());
        }
        rmdir($directorio);
    }
}
