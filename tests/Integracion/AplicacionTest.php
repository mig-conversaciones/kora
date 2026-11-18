<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Nucleo\Aplicacion;
use App\Nucleo\BaseDatos;
use App\Nucleo\Entorno;
use App\Nucleo\Migrador;
use App\Nucleo\Solicitud;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioMantenimiento;
use App\Servicios\ServicioMonitorCola;
use PHPUnit\Framework\TestCase;

/**
 * La aplicación completa, sin servidor web: rutas, cadena de intermediarios, CSP con nonce, fragmentos HTMX,
 * errores controlados, salud, autorización provisional, registro técnico y mantenimiento (RI-003, RS-007,
 * RUI-015, ROB-011, RAU-008, RF-MAN-006).
 */
final class AplicacionTest extends TestCase
{
    private string $senal;
    private string $latidos;

    public static function setUpBeforeClass(): void
    {
        $bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
        (new Migrador($bd, RAIZ . '/bd/migraciones'))->aplicar();
        $bd->ejecutar('DELETE FROM trabajos_fallidos');
        $bd->ejecutar('DELETE FROM trabajos');
    }

    protected function setUp(): void
    {
        $this->senal = sys_get_temp_dir() . '/kora-mantenimiento-' . bin2hex(random_bytes(4)) . '.json';
        $this->latidos = sys_get_temp_dir() . '/kora-latidos-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_file($this->senal)) {
            unlink($this->senal);
        }
        array_map('unlink', glob($this->latidos . '/*') ?: []);
        if (is_dir($this->latidos)) {
            rmdir($this->latidos);
        }
    }

    public function testInicioEsPaginaCompletaConCspYCorrelacion(): void
    {
        $respuesta = $this->aplicacion()->manejar($this->solicitud('GET', '/'));
        self::assertSame(200, $respuesta->estado);
        self::assertStringContainsString('<!doctype html>', $respuesta->cuerpo);
        self::assertStringContainsString('Bienvenido a KORA', $respuesta->cuerpo);
        $csp = $respuesta->cabeceras['Content-Security-Policy'];
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
        preg_match("/'nonce-([^']+)'/", $csp, $nonce);
        self::assertNotEmpty($nonce[1]);
        self::assertStringContainsString('nonce="' . $nonce[1] . '"', $respuesta->cuerpo, 'Cada script lleva el nonce de la respuesta.');
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bnonce=)/', $respuesta->cuerpo, 'No hay scripts sin nonce.');
        self::assertDoesNotMatchRegularExpression('/\sstyle="/', $respuesta->cuerpo, 'No hay estilos en línea.');
        self::assertDoesNotMatchRegularExpression('/\shx-on[:-]/', $respuesta->cuerpo, 'No se usa hx-on (ADR-004).');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $respuesta->cabeceras['X-Request-ID']);
    }

    public function testRespetaElIdDeSolicitudDelCliente(): void
    {
        $id = str_repeat('ab', 16);
        $respuesta = $this->aplicacion()->manejar($this->solicitud('GET', '/', ['x-request-id' => $id]));
        self::assertSame($id, $respuesta->cabeceras['X-Request-ID']);
    }

    public function testHtmxRecibeSoloElFragmento(): void
    {
        $respuesta = $this->aplicacion()->manejar($this->solicitud('GET', '/sistema-diseno', ['hx-request' => 'true']));
        self::assertSame(200, $respuesta->estado);
        self::assertStringNotContainsString('<!doctype html>', $respuesta->cuerpo);
        self::assertStringContainsString('Sistema de diseño de KORA', $respuesta->cuerpo);
        self::assertSame('HX-Request', $respuesta->cabeceras['Vary']);
    }

    public function testRutaInexistenteDevuelve404ControladoConCsp(): void
    {
        $respuesta = $this->aplicacion()->manejar($this->solicitud('GET', '/no-existe'));
        self::assertSame(404, $respuesta->estado);
        self::assertStringContainsString('No encontramos esta página', $respuesta->cuerpo);
        self::assertArrayHasKey('Content-Security-Policy', $respuesta->cabeceras);
    }

    public function testErrorInternoNoExponeDetalles(): void
    {
        $respuesta = $this->aplicacion()->manejar($this->solicitud('GET', '/sistema-diseno/demostracion/error', ['hx-request' => 'true']));
        self::assertSame(500, $respuesta->estado);
        self::assertStringNotContainsString('RuntimeException', $respuesta->cuerpo);
        self::assertStringNotContainsString('demostración del sistema', $respuesta->cuerpo);
        self::assertStringContainsString('Código de soporte', $respuesta->cuerpo);
    }

    public function testToastViajaEnHxTriggerEnAscii(): void
    {
        $respuesta = $this->aplicacion()->manejar($this->solicitud('GET', '/sistema-diseno/demostracion/toast-exito', ['hx-request' => 'true']));
        self::assertSame(204, $respuesta->estado);
        $disparador = $respuesta->cabeceras['HX-Trigger'];
        self::assertSame(1, preg_match('/^[\x20-\x7E]+$/', $disparador), 'La cabecera solo contiene ASCII (D-34).');
        self::assertSame('success', json_decode($disparador, true)['mostrarToast']['tipo']);
    }

    public function testValidacionDevuelve422ConservandoLoEscrito(): void
    {
        $solicitud = new Solicitud('GET', '/sistema-diseno/demostracion/validacion', ['valor' => '100', 'componente_academico' => '300'], [], ['hx-request' => 'true'], [], '127.0.0.1');
        $respuesta = $this->aplicacion()->manejar($solicitud);
        self::assertSame(422, $respuesta->estado);
        self::assertStringContainsString('data-resumen-errores', $respuesta->cuerpo);
        self::assertStringContainsString('value="300"', $respuesta->cuerpo);
    }

    public function testSaludListoExigeTokenYCompruebaEsquemaColaYLatidos(): void
    {
        $sinToken = $this->aplicacion()->manejar($this->solicitud('GET', '/health/ready'));
        self::assertSame(404, $sinToken->estado);

        $cabeceras = ['x-token-monitoreo' => Entorno::texto('TOKEN_MONITOREO')];
        // Sin latidos del trabajador ni del programador el sistema no está listo (ROB-010).
        $sinLatidos = $this->aplicacion()->manejar($this->solicitud('GET', '/health/ready', $cabeceras));
        $datos = json_decode($sinLatidos->cuerpo, true);
        self::assertSame(503, $sinLatidos->estado);
        self::assertFalse($datos['comprobaciones']['trabajador']);
        self::assertFalse($datos['comprobaciones']['programador']);

        $aplicacion = $this->aplicacion();
        $monitor = $aplicacion->contenedor()->obtener(ServicioMonitorCola::class);
        $monitor->registrarLatido(ServicioMonitorCola::LATIDO_TRABAJADOR);
        $monitor->registrarLatido(ServicioMonitorCola::LATIDO_PROGRAMADOR);
        $conLatidos = $aplicacion->manejar($this->solicitud('GET', '/health/ready', $cabeceras));
        $datos = json_decode($conLatidos->cuerpo, true);
        self::assertSame(200, $conLatidos->estado, $conLatidos->cuerpo);
        self::assertSame('listo', $datos['estado']);
        self::assertTrue($datos['comprobaciones']['esquema']);
        self::assertTrue($datos['comprobaciones']['cola']);
    }

    public function testRutaConPermisoSinSesionLlevaAlIngresoConservandoElDestino(): void
    {
        $respuesta = $this->aplicacion()->manejar($this->solicitud('GET', '/trabajos/01JABCDEFGHJKMNPQRSTVWXYZ0'));
        self::assertSame(303, $respuesta->estado);
        self::assertStringEndsWith('/ingresar?volver=%2Ftrabajos%2F01JABCDEFGHJKMNPQRSTVWXYZ0', $respuesta->cabeceras['Location']);
        self::assertArrayHasKey('Content-Security-Policy', $respuesta->cabeceras);

        $htmx = $this->aplicacion()->manejar($this->solicitud('GET', '/trabajos/01JABCDEFGHJKMNPQRSTVWXYZ0', ['hx-request' => 'true']));
        self::assertArrayHasKey('HX-Redirect', $htmx->cabeceras, 'HTMX recibe HX-Redirect en lugar de un 303 que intercambiaría la página de ingreso.');
    }

    public function testCadaSolicitudDejaUnaLineaEnElRegistroTecnico(): void
    {
        $id = str_repeat('cd', 16);
        $this->aplicacion()->manejar($this->solicitud('GET', '/health/live', ['x-request-id' => $id]));
        $lineas = file(RAIZ . '/almacenamiento/registros/app-' . gmdate('Y-m-d') . '.log') ?: [];
        $linea = null;
        foreach (array_reverse($lineas) as $candidata) {
            if (str_contains($candidata, $id)) {
                $linea = json_decode($candidata, true);
                break;
            }
        }
        self::assertIsArray($linea, 'La solicitud dejó su línea JSON con el request_id (RAU-008, RAU-009).');
        self::assertSame('solicitud', $linea['evento']);
        foreach (['user_id', 'ruta', 'status', 'duration_ms', 'memoria_mb', 'consultas'] as $campo) {
            self::assertArrayHasKey($campo, $linea);
        }
        self::assertSame(200, $linea['status']);
    }

    public function testMantenimientoDevuelve503SalvoRutasExceptuadas(): void
    {
        file_put_contents($this->senal, json_encode(['activo' => true, 'titulo' => 'Mantenimiento de prueba', 'mensaje' => 'Volvemos pronto.']));

        $pagina = $this->aplicacion()->manejar($this->solicitud('GET', '/'));
        self::assertSame(503, $pagina->estado);
        self::assertSame('activo', $pagina->cabeceras['X-Mantenimiento']);
        self::assertStringContainsString('Mantenimiento de prueba', $pagina->cuerpo);

        $fragmento = $this->aplicacion()->manejar($this->solicitud('GET', '/sistema-diseno', ['hx-request' => 'true']));
        self::assertSame('#contenido-principal', $fragmento->cabeceras['HX-Retarget']);

        $vivo = $this->aplicacion()->manejar($this->solicitud('GET', '/health/live'));
        self::assertSame(200, $vivo->estado);
    }

    /** Aplicación con la señal de mantenimiento y los latidos en directorios temporales de la prueba. */
    private function aplicacion(): Aplicacion
    {
        $aplicacion = Aplicacion::crear(require RAIZ . '/config/app.php');
        $contenedor = $aplicacion->contenedor();
        $contenedor->establecer(ServicioMantenimiento::class, new ServicioMantenimiento(
            $contenedor->obtener(BaseDatos::class),
            $contenedor->obtener(ServicioAuditoria::class),
            ['senal' => $this->senal] + require RAIZ . '/config/mantenimiento.php',
            '006',
        ));
        $contenedor->establecer(ServicioMonitorCola::class, new ServicioMonitorCola($contenedor->obtener(BaseDatos::class), $this->latidos));
        return $aplicacion;
    }

    /** @param array<string,string> $cabeceras */
    private function solicitud(string $metodo, string $ruta, array $cabeceras = []): Solicitud
    {
        return new Solicitud($metodo, $ruta, [], [], $cabeceras, [], '127.0.0.1');
    }
}
