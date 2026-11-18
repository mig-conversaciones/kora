<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Cola\ConductorColaMysql;
use App\Cola\ContextoTrabajo;
use App\Cola\CortaCircuitos;
use App\Cola\DespachadorCola;
use App\Cola\ErrorDefinitivo;
use App\Cola\ErrorTransitorio;
use App\Cola\ManejadorTrabajo;
use App\Cola\Trabajador;
use App\Nucleo\BaseDatos;
use App\Nucleo\Contenedor;
use App\Nucleo\Migrador;
use App\Nucleo\Registro;
use App\Nucleo\Vista;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioMantenimiento;
use PHPUnit\Framework\TestCase;

/**
 * Cola durable sobre la base de pruebas (RF-Q-001 a RF-Q-011, RF-Q-017). En MySQL 5.7 se prueba la estrategia
 * de reclamo «actualizacion_condicional»; en MariaDB 10.6 o MySQL 8 (CI), también «skip_locked».
 */
final class ColaTest extends TestCase
{
    private static BaseDatos $bd;
    private string $senal;

    public static function setUpBeforeClass(): void
    {
        self::$bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
        (new Migrador(self::$bd, RAIZ . '/bd/migraciones'))->aplicar();
        self::$bd->ejecutar("INSERT IGNORE INTO centros (id, codigo, nombre, regional, codigo_regional) VALUES (1, '9124', 'Centro de pruebas', 'Valle', '76')");
    }

    protected function setUp(): void
    {
        self::$bd->ejecutar('DELETE FROM trabajos_fallidos');
        self::$bd->ejecutar('DELETE FROM trabajos');
        self::$bd->ejecutar('DELETE FROM estado_circuitos');
        $this->senal = sys_get_temp_dir() . '/kora-senal-' . bin2hex(random_bytes(4)) . '.json';
        ManejadorDePrueba::$comportamiento = 'exito';
        ManejadorDePrueba::$ejecuciones = 0;
    }

    protected function tearDown(): void
    {
        if (is_file($this->senal)) {
            unlink($this->senal);
        }
    }

    /** @return array<string,array{0:string}> */
    public static function estrategias(): array
    {
        $bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
        $version = (string) $bd->valor('SELECT VERSION()');
        preg_match('/^\d+\.\d+\.\d+/', $version, $m);
        $soporta = stripos($version, 'mariadb') !== false ? version_compare($m[0], '10.6.0', '>=') : version_compare($m[0], '8.0.1', '>=');
        $estrategias = ['actualizacion_condicional' => ['actualizacion_condicional']];
        if ($soporta) {
            $estrategias['skip_locked'] = ['skip_locked'];
        }
        return $estrategias;
    }

    public function testEncolarDeduplicaPorClaveDeIdempotencia(): void
    {
        $despachador = $this->despachador();
        $clave = str_repeat('a', 24);
        $primero = $despachador->encolar('demostracion.progreso', ['pasos' => 1], ['clave_idempotencia' => $clave, 'centro_id' => 1]);
        $segundo = $despachador->encolar('demostracion.progreso', ['pasos' => 1], ['clave_idempotencia' => $clave, 'centro_id' => 1]);
        self::assertFalse($primero['duplicado']);
        self::assertTrue($segundo['duplicado']);
        self::assertSame($primero['ulid'], $segundo['ulid']);

        // Sin centro (trabajos del sistema) también se deduplica, aunque el índice único no cubra los NULL.
        $a = $despachador->encolar('demostracion.progreso', ['pasos' => 1], ['clave_idempotencia' => $clave]);
        $b = $despachador->encolar('demostracion.progreso', ['pasos' => 1], ['clave_idempotencia' => $clave]);
        self::assertSame($a['ulid'], $b['ulid']);
        self::assertSame(2, (int) self::$bd->valor('SELECT COUNT(*) FROM trabajos'));
    }

    public function testTipoNoRegistradoNoSePersiste(): void
    {
        $this->expectException(ErrorDefinitivo::class);
        $this->despachador()->encolar('tipo.inexistente', []);
    }

    public function testOutboxElTrabajoSeRevierteConLaTransaccion(): void
    {
        try {
            self::$bd->transaccion(function (): void {
                $this->despachador()->encolar('demostracion.progreso', ['pasos' => 1]);
                throw new \RuntimeException('Falla del caso de uso');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(0, (int) self::$bd->valor('SELECT COUNT(*) FROM trabajos'), 'Revertir el negocio revierte el trabajo (RF-Q-004).');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('estrategias')]
    public function testReclamoAtomicoNoEntregaElMismoTrabajoDosVeces(string $estrategia): void
    {
        $conductor = new ConductorColaMysql(self::$bd, $estrategia);
        for ($i = 0; $i < 5; $i++) {
            $this->despachador()->encolar('demostracion.progreso', ['pasos' => 1]);
        }
        $lote1 = $conductor->reclamar('predeterminada', 3, str_repeat('1', 32), 60);
        $lote2 = $conductor->reclamar('predeterminada', 3, str_repeat('2', 32), 60);
        self::assertCount(3, $lote1);
        self::assertCount(2, $lote2);
        self::assertSame([], array_intersect(array_column($lote1, 'id'), array_column($lote2, 'id')));
        self::assertSame([], $conductor->reclamar('predeterminada', 3, str_repeat('3', 32), 60));
    }

    public function testFinalizarConUnaReservaPerdidaNoSobrescribe(): void
    {
        $conductor = new ConductorColaMysql(self::$bd, 'actualizacion_condicional');
        $this->despachador()->encolar('demostracion.progreso', ['pasos' => 1]);
        [$trabajo] = $conductor->reclamar('predeterminada', 1, str_repeat('a', 32), 60);
        // La reserva vence y la recupera otra ejecución: vuelve a pendiente.
        self::$bd->ejecutar('UPDATE trabajos SET bloqueado_hasta = UTC_TIMESTAMP(3) - INTERVAL 1 SECOND');
        self::assertSame(1, $conductor->recuperarVencidos());
        self::assertFalse($conductor->completar((int) $trabajo['id'], str_repeat('a', 32), ['ok' => true]), 'El trabajador lento no puede completar (RF-Q-008).');
        self::assertSame('pendiente', self::$bd->valor('SELECT estado FROM trabajos'));
    }

    public function testCicloCompletoDelTrabajadorConProgreso(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->despachador()->encolar('demostracion.progreso', ['pasos' => 2, 'ms_por_paso' => 0]);
        }
        $estadisticas = $this->trabajador()->ejecutar(null, 10);
        self::assertSame(3, $estadisticas['completados']);
        $filas = self::$bd->consultar('SELECT estado, progreso, intentos, resultado FROM trabajos');
        foreach ($filas as $fila) {
            self::assertSame('completado', $fila['estado']);
            self::assertSame(100, (int) $fila['progreso']);
            self::assertSame(1, (int) $fila['intentos']);
            self::assertSame(2, json_decode((string) $fila['resultado'], true)['pasos']);
        }
    }

    public function testTrabajoPorBloquesContinuaSinConsumirIntentos(): void
    {
        $this->despachador()->encolar('demostracion.progreso', ['pasos' => 6, 'ms_por_paso' => 300]);
        // Presupuesto corto: no alcanza para los 6 pasos y el trabajo pide continuar (RF-Q-011).
        $primera = $this->trabajador()->ejecutar(null, 2.5);
        $fila = self::$bd->fila('SELECT estado, intentos, punto_control FROM trabajos');
        self::assertSame('pendiente', $fila['estado']);
        self::assertSame(0, (int) $fila['intentos'], 'La continuación no consume intentos.');
        self::assertGreaterThan(0, (int) json_decode((string) $fila['punto_control'], true)['paso']);
        self::assertSame(1, $primera['procesados']);

        $this->trabajador()->ejecutar(null, 10);
        self::assertSame('completado', self::$bd->valor('SELECT estado FROM trabajos'));
    }

    public function testAlCortarLaEjecucionLosReclamadosSinProcesarSeLiberanSinGastarIntentos(): void
    {
        $this->despachador()->encolar('demostracion.progreso', ['pasos' => 6, 'ms_por_paso' => 300]);
        $this->despachador()->encolar('demostracion.progreso', ['pasos' => 1, 'ms_por_paso' => 0]);
        $this->despachador()->encolar('demostracion.progreso', ['pasos' => 1, 'ms_por_paso' => 0]);
        $estadisticas = $this->trabajador()->ejecutar(null, 2.5);
        self::assertSame(1, $estadisticas['continuados']);
        self::assertSame(2, $estadisticas['liberados']);
        self::assertSame(0, (int) self::$bd->valor("SELECT COUNT(*) FROM trabajos WHERE estado = 'reservado'"));
        self::assertSame(0, (int) self::$bd->valor('SELECT SUM(intentos) FROM trabajos'));
    }

    public function testFalloTransitorioSeReprogramaYElDefinitivoVaAFallidos(): void
    {
        $config = $this->configCola();
        ManejadorDePrueba::$comportamiento = 'transitorio';
        $this->despachador($config)->encolar('prueba.manejador', []);
        $this->trabajador($config)->ejecutar(null, 5);
        $fila = self::$bd->fila('SELECT estado, intentos, mensaje_error, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(3), disponible_en) AS espera FROM trabajos');
        self::assertSame('pendiente', $fila['estado']);
        self::assertSame(1, (int) $fila['intentos']);
        self::assertGreaterThanOrEqual(25, (int) $fila['espera'], 'Espera exponencial: base de 30 s con variación (RF-Q-009).');

        self::$bd->ejecutar('DELETE FROM trabajos');
        ManejadorDePrueba::$comportamiento = 'definitivo';
        $this->despachador($config)->encolar('prueba.manejador', []);
        $this->trabajador($config)->ejecutar(null, 5);
        self::assertSame('fallido', self::$bd->valor('SELECT estado FROM trabajos'));
        self::assertSame('definitivo', self::$bd->valor('SELECT clase_error FROM trabajos_fallidos'));
    }

    public function testErrorNoClasificadoNoExponeDetalles(): void
    {
        $config = $this->configCola();
        ManejadorDePrueba::$comportamiento = 'excepcion';
        $this->despachador($config)->encolar('prueba.manejador', [], ['correlacion_id' => str_repeat('e', 32)]);
        $this->trabajador($config)->ejecutar(null, 5);
        $mensaje = (string) self::$bd->valor('SELECT mensaje_error FROM trabajos');
        self::assertStringNotContainsString('secreto', $mensaje);
        self::assertStringContainsString(str_repeat('e', 32), $mensaje, 'El mensaje sanitizado lleva el código de soporte.');
    }

    public function testEnMantenimientoSoloSeEjecutanTiposTecnicos(): void
    {
        file_put_contents($this->senal, json_encode(['activo' => true]));
        $this->despachador()->encolar('demostracion.progreso', ['pasos' => 1, 'ms_por_paso' => 0]);
        $this->despachador()->encolar('mantenimiento.limpieza', ['alcance' => 'horaria']);
        $this->trabajador()->ejecutar(null, 5);
        $estados = [];
        foreach (self::$bd->consultar('SELECT tipo, estado FROM trabajos') as $fila) {
            $estados[$fila['tipo']] = $fila['estado'];
        }
        self::assertSame('pendiente', $estados['demostracion.progreso'], 'El trabajo de negocio espera (RF-Q-017).');
        self::assertSame('completado', $estados['mantenimiento.limpieza']);
    }

    public function testDosTrabajadoresConcurrentesProcesanCadaTrabajoUnaSolaVez(): void
    {
        $total = 24;
        for ($i = 0; $i < $total; $i++) {
            $this->despachador()->encolar('demostracion.progreso', ['pasos' => 1, 'ms_por_paso' => 150]);
        }
        $procesos = [];
        $tuberias = [];
        for ($i = 0; $i < 2; $i++) {
            $comando = [PHP_BINARY];
            if (($ini = php_ini_loaded_file()) !== false) {
                array_push($comando, '-c', $ini);
            }
            array_push($comando, RAIZ . '/cli/trabajador_cron.php', '--presupuesto=25', '--detalle');
            $entorno = getenv();
            $entorno['KORA_BD_PRUEBAS'] = (string) (getenv('BD_NOMBRE_PRUEBAS') ?: 'kora_pruebas');
            $procesos[$i] = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias[$i], RAIZ, $entorno);
            self::assertIsResource($procesos[$i]);
        }
        $salidas = [];
        foreach ($procesos as $i => $proceso) {
            $salidas[$i] = json_decode(trim((string) stream_get_contents($tuberias[$i][1])), true);
            $error = (string) stream_get_contents($tuberias[$i][2]);
            proc_close($proceso);
            self::assertIsArray($salidas[$i], "Salida del trabajador {$i}: {$error}");
        }
        self::assertSame($total, $salidas[0]['procesados'] + $salidas[1]['procesados'], 'Ningún trabajo se procesó dos veces.');
        self::assertGreaterThan(0, $salidas[0]['procesados']);
        self::assertGreaterThan(0, $salidas[1]['procesados'], 'Los dos trabajadores participaron.');
        self::assertSame($total, (int) self::$bd->valor("SELECT COUNT(*) FROM trabajos WHERE estado = 'completado' AND intentos = 1"));
    }

    public function testCortaCircuitosAbreTrasTresFallosYPruebaUnaVez(): void
    {
        $circuitos = new CortaCircuitos(self::$bd);
        self::assertTrue($circuitos->permite('smtp'));
        for ($i = 0; $i < 3; $i++) {
            $circuitos->registrarFallo('smtp');
        }
        self::assertSame('abierto', self::$bd->valor("SELECT estado FROM estado_circuitos WHERE servicio = 'smtp'"));
        self::assertFalse($circuitos->permite('smtp'));

        self::$bd->ejecutar("UPDATE estado_circuitos SET abierto_hasta = UTC_TIMESTAMP(3) - INTERVAL 1 SECOND WHERE servicio = 'smtp'");
        self::assertTrue($circuitos->permite('smtp'), 'Al vencer, una sola solicitud de prueba pasa (semiabierto).');
        self::assertFalse($circuitos->permite('smtp'));
        $circuitos->registrarExito('smtp');
        self::assertTrue($circuitos->permite('smtp'));
    }

    public function testCalculoDeEsperaExponencialConTope(): void
    {
        $espera = ['base_segundos' => 30, 'maximo_segundos' => 1800, 'variacion' => 0.30];
        for ($intento = 1; $intento <= 10; $intento++) {
            $base = min(1800, 30 * 2 ** ($intento - 1));
            $valor = Trabajador::calcularEspera($intento, $espera);
            self::assertGreaterThanOrEqual($base, $valor);
            self::assertLessThanOrEqual((int) round($base * 1.3), $valor);
        }
    }

    /** @return array<string,mixed> */
    private function configCola(): array
    {
        $config = require RAIZ . '/config/cola.php';
        $config['tipos']['prueba.manejador'] = [
            'manejador' => ManejadorDePrueba::class, 'cola' => 'predeterminada', 'max_intentos' => 3,
            'reserva_segundos' => 60, 'tecnico' => false, 'prioridad' => 5,
        ];
        return $config;
    }

    /** @param array<string,mixed>|null $config */
    private function despachador(?array $config = null): DespachadorCola
    {
        $vista = new Vista(['directorio' => RAIZ . '/app/Vistas', 'manifiesto' => '', 'nombre_app' => 'KORA', 'entorno' => 'local', 'menu' => [], 'config_htmx' => []]);
        return new DespachadorCola(new ConductorColaMysql(self::$bd, 'actualizacion_condicional'), $vista, $config ?? $this->configCola());
    }

    /** @param array<string,mixed>|null $config */
    private function trabajador(?array $config = null): Trabajador
    {
        $config ??= $this->configCola();
        $registro = new Registro(sys_get_temp_dir() . '/kora-registros-pruebas');
        $contenedor = new Contenedor([BaseDatos::class => static fn (): BaseDatos => self::$bd, Registro::class => static fn (): Registro => $registro]);
        $mantenimiento = new ServicioMantenimiento(
            self::$bd,
            new ServicioAuditoria(self::$bd, $registro),
            ['senal' => $this->senal] + require RAIZ . '/config/mantenimiento.php',
            '006'
        );
        $contenedor->establecer(\App\Nucleo\Cache::class, new \App\Nucleo\Cache(sys_get_temp_dir() . '/kora-cache-pruebas'));
        $contenedor->establecer(\App\Trabajos\LimpiezaPeriodica::class, new \App\Trabajos\LimpiezaPeriodica(
            self::$bd,
            $contenedor->obtener(\App\Nucleo\Cache::class),
            $config,
            1048576,
            sys_get_temp_dir() . '/kora-correos-pruebas'
        ));
        return new Trabajador(new ConductorColaMysql(self::$bd, 'actualizacion_condicional'), $contenedor, $registro, $mantenimiento, $config);
    }
}

/** Manejador controlable para probar reintentos y fallos. */
final class ManejadorDePrueba implements ManejadorTrabajo
{
    public static string $comportamiento = 'exito';
    public static int $ejecuciones = 0;

    public function ejecutar(array $trabajo, ContextoTrabajo $contexto): array
    {
        self::$ejecuciones++;
        return match (self::$comportamiento) {
            'transitorio' => throw new ErrorTransitorio('Servicio externo no disponible.'),
            'definitivo' => throw new ErrorDefinitivo('Datos inválidos.'),
            'excepcion' => throw new \LogicException('Detalle interno secreto: /ruta/servidor'),
            default => ['ok' => true],
        };
    }
}
