<?php

declare(strict_types=1);

namespace Pruebas\Unitarias;

use App\Nucleo\Contenedor;
use App\Nucleo\Entorno;
use App\Nucleo\Funcionalidades;
use App\Nucleo\GeneradorUlid;
use App\Nucleo\Migrador;
use App\Nucleo\Registro;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Url;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NucleoTest extends TestCase
{
    public function testEntornoExigeLasClavesSinValorPorDefecto(): void
    {
        $this->expectException(RuntimeException::class);
        Entorno::texto('CLAVE_QUE_NO_EXISTE_' . bin2hex(random_bytes(4)));
    }

    public function testEntornoInterpretaBooleanosYEnteros(): void
    {
        Entorno::establecer('PRUEBA_BOOLEANO', 'true');
        Entorno::establecer('PRUEBA_ENTERO', '42');
        self::assertTrue(Entorno::booleano('PRUEBA_BOOLEANO', false));
        self::assertSame(42, Entorno::entero('PRUEBA_ENTERO', 0));
        self::assertFalse(Entorno::booleano('PRUEBA_INEXISTENTE_BOOL', false));
    }

    public function testSolicitudRetiraLaRutaBaseYLaBarraFinal(): void
    {
        self::assertSame('/sistema-diseno', Solicitud::normalizarRuta('/dashboard_laila/sistema-diseno/', '/dashboard_laila'));
        self::assertSame('/', Solicitud::normalizarRuta('/dashboard_laila', '/dashboard_laila'));
        self::assertSame('/', Solicitud::normalizarRuta('/dashboard_laila/', '/dashboard_laila'));
        // Un prefijo parecido no se confunde con la ruta base.
        self::assertSame('/dashboard_lailax/a', Solicitud::normalizarRuta('/dashboard_lailax/a', '/dashboard_laila'));
        self::assertSame('/health/live', Solicitud::normalizarRuta('/health/live', ''));
    }

    public function testUrlAnteponeLaRutaBase(): void
    {
        Url::establecerBase('/dashboard_laila');
        self::assertSame('/dashboard_laila/sistema-diseno', Url::a('/sistema-diseno'));
        Url::establecerBase('');
        self::assertSame('/sistema-diseno', Url::a('/sistema-diseno'));
        Url::establecerBase(null);
    }

    public function testRespuestaJsonYServicioNoDisponible(): void
    {
        $json = Respuesta::json(['estado' => 'vivo']);
        self::assertSame('{"estado":"vivo"}', $json->cuerpo);
        self::assertSame('application/json', $json->cabeceras['Content-Type']);

        $htmx = new Solicitud('GET', '/', [], [], ['hx-request' => 'true'], [], '');
        $respuesta = Respuesta::servicioNoDisponible($htmx);
        self::assertSame(503, $respuesta->estado);
        self::assertSame('#contenedor-errores', $respuesta->cabeceras['HX-Retarget']);
        self::assertSame('no-store', $respuesta->cabeceras['Cache-Control']);
    }

    public function testContenedorResuelveDependenciasYReutilizaInstancias(): void
    {
        $contenedor = new Contenedor([Registro::class => static fn (): Registro => new Registro(sys_get_temp_dir())]);
        $a = $contenedor->obtener(ServicioDePrueba::class);
        self::assertSame($a, $contenedor->obtener(ServicioDePrueba::class));
        self::assertInstanceOf(Registro::class, $a->registro);
    }

    public function testContenedorFallaConEscalaresSinFabrica(): void
    {
        $this->expectException(LogicException::class);
        (new Contenedor())->obtener(Funcionalidades::class);
    }

    public function testFuncionalidadDesconocidaEstaInactiva(): void
    {
        $f = new Funcionalidades(['vista_soporte' => ['activa' => false, 'descripcion' => '', 'responsable' => '', 'creada' => '2026-11-17', 'retiro_previsto' => null]]);
        self::assertFalse($f->activa('no_existe'));
        self::assertFalse($f->activa('vista_soporte'));
        Entorno::establecer('FUNCIONALIDAD_VISTA_SOPORTE', 'true');
        self::assertTrue($f->activa('vista_soporte'));
        Entorno::establecer('FUNCIONALIDAD_VISTA_SOPORTE', 'false');
    }

    public function testUlidTieneFormatoYOrdenTemporal(): void
    {
        $primero = GeneradorUlid::generar(1_700_000_000_000);
        $segundo = GeneradorUlid::generar(1_700_000_000_001);
        self::assertSame(26, strlen($primero));
        self::assertTrue(GeneradorUlid::esValido($primero));
        self::assertLessThan(0, strcmp(substr($primero, 0, 10), substr($segundo, 0, 10)));
        self::assertFalse(GeneradorUlid::esValido('01JABCDEFGHIJKLMNOPQRSTUVW'));
    }

    public function testSeparaSentenciasPorPuntoYComaAlFinalDeLinea(): void
    {
        $sql = "-- comentario\nCREATE TABLE a (\n  x INT COMMENT 'uno; dos'\n);\n\nINSERT INTO a VALUES (1);\n";
        self::assertSame(["CREATE TABLE a (\n  x INT COMMENT 'uno; dos'\n)", 'INSERT INTO a VALUES (1)'], Migrador::separarSentencias($sql));
    }
}

final class ServicioDePrueba
{
    public function __construct(public readonly Registro $registro)
    {
    }
}
