<?php

declare(strict_types=1);

namespace Pruebas\Unitarias;

use App\Nucleo\Enrutador;
use App\Nucleo\ErrorHttp;
use LogicException;
use PHPUnit\Framework\TestCase;

final class EnrutadorTest extends TestCase
{
    public function testResuelveRutaConParametros(): void
    {
        $r = new Enrutador();
        $r->agregar('GET', '/trabajos/{ulid}', [self::class, 'accion'], 'trabajos.consultar_propios');
        $ruta = $r->resolver('get', '/trabajos/01JABCDEF');
        self::assertSame([self::class, 'accion'], $ruta['accion']);
        self::assertSame(['ulid' => '01JABCDEF'], $ruta['parametros']);
        self::assertSame('trabajos.consultar_propios', $ruta['permiso']);
    }

    public function testRutaSinPermisoNiPublicaSeRechazaAlRegistrarla(): void
    {
        $this->expectException(LogicException::class);
        (new Enrutador())->agregar('GET', '/secreto', [self::class, 'accion'], '');
    }

    public function testRutaInexistenteDevuelve404(): void
    {
        $r = new Enrutador();
        $r->agregar('GET', '/', [self::class, 'accion'], '', ['publica' => true]);
        try {
            $r->resolver('GET', '/no-existe');
            self::fail('Se esperaba ErrorHttp');
        } catch (ErrorHttp $error) {
            self::assertSame(404, $error->estado);
        }
    }

    public function testMetodoNoPermitidoDevuelve405(): void
    {
        $r = new Enrutador();
        $r->agregar('POST', '/csp-reportes', [self::class, 'accion'], '', ['publica' => true]);
        try {
            $r->resolver('GET', '/csp-reportes');
            self::fail('Se esperaba ErrorHttp');
        } catch (ErrorHttp $error) {
            self::assertSame(405, $error->estado);
        }
    }

    public function testParametroNoAceptaCaracteresFueraDeLaListaBlanca(): void
    {
        $r = new Enrutador();
        $r->agregar('GET', '/trabajos/{ulid}', [self::class, 'accion'], 'trabajos.consultar_propios');
        $this->expectException(ErrorHttp::class);
        $r->resolver('GET', '/trabajos/../../etc');
    }

    public function accion(): void
    {
    }
}
