<?php

declare(strict_types=1);

namespace Pruebas\Unitarias;

use App\Nucleo\Entrada;
use App\Politicas\AlcanceDatos;
use App\Politicas\PoliticaAlcanceUnidad;
use App\Politicas\PoliticaUsuarios;
use App\Politicas\RecursoUnidad;
use App\Politicas\RecursoUsuario;
use App\Politicas\UsuarioSesion;
use App\Servicios\ReglasDependencias;
use App\Servicios\ServicioCatalogos;
use App\Servicios\ServicioGastos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Reglas puras de la I3: lectura de cifras, políticas ABAC, separación de funciones y normalización de conceptos. */
final class ReglasNegocioTest extends TestCase
{
    /** @return iterable<string,array{0:string,1:int,2:?string}> */
    public static function decimales(): iterable
    {
        yield 'campo numérico' => ['3045000.50', 2, '3045000.50'];
        yield 'formato local' => ['3.045.000,50', 2, '3045000.50'];
        yield 'miles sin decimales' => ['12.500.000', 2, '12500000.00'];
        yield 'con signo y espacios' => [' $ 2.200 ', 2, '2200.00'];
        yield 'factor no toma puntos como miles' => ['8.500', 4, '8.5000'];
        yield 'entero' => ['15', 0, '15'];
        yield 'negativo' => ['-5', 2, null];
        yield 'demasiados decimales' => ['1.2345', 2, null];
        yield 'punto de miles en un monto' => ['1.234', 2, '1234.00'];
        yield 'texto' => ['doce', 2, null];
        yield 'vacío' => ['', 2, null];
    }

    #[DataProvider('decimales')]
    public function testDecimalCanonico(string $texto, int $decimales, ?string $esperado): void
    {
        self::assertSame($esperado, Entrada::decimal($texto, $decimales));
    }

    public function testComparaYSumaSinFloat(): void
    {
        self::assertSame(1, Entrada::comparar('10000000.01', '10000000.00'));
        self::assertSame(0, Entrada::comparar('2200.00', '2200'));
        self::assertSame(-1, Entrada::comparar('999.99', '1000.00'));
        self::assertSame('12500000.00', Entrada::sumar('4000000.00', '6000000.00', '2500000.00'));
        self::assertSame('0.30', Entrada::sumar('0.10', '0.20'), 'Sin el error de 0,1 + 0,2 en coma flotante.');
    }

    public function testFechaReal(): void
    {
        self::assertSame('2026-02-28', Entrada::fecha('2026-02-28'));
        self::assertNull(Entrada::fecha('2026-02-30'));
        self::assertNull(Entrada::fecha('15/02/2026'));
    }

    public function testRangoDeMesYAnio(): void
    {
        self::assertSame(['2026-12-01', '2027-01-01'], ServicioGastos::rango(2026, 12));
        self::assertSame(['2026-01-01', '2027-01-01'], ServicioGastos::rango(2026, null));
    }

    public function testPoliticaAlcanceUnidadDeniegaOtraUnidadYOtroCentro(): void
    {
        $politica = new PoliticaAlcanceUnidad();
        $lider = self::usuario(10, 1, 'lider_unidad', ['gastos.gestionar'], [5]);
        $alcance = AlcanceDatos::deUsuario(1, [5], false);
        self::assertTrue($politica->decidir($lider, 'gastos.gestionar', new RecursoUnidad(1, 5), $alcance)->permitida());
        self::assertFalse($politica->decidir($lider, 'gastos.gestionar', new RecursoUnidad(1, 6), $alcance)->permitida());
        self::assertFalse($politica->decidir($lider, 'gastos.gestionar', new RecursoUnidad(2, 5), $alcance)->permitida(), 'Mismo id de unidad en otro centro.');
        self::assertFalse($politica->decidir($lider, 'asignaciones.gestionar', new RecursoUnidad(1, 5), $alcance)->permitida(), 'Sin el permiso RBAC.');
        $coordinacion = self::usuario(11, 1, 'coordinacion_administrativa', ['gastos.gestionar'], [], true);
        self::assertTrue($politica->decidir($coordinacion, 'gastos.gestionar', new RecursoUnidad(1, 6), AlcanceDatos::deUsuario(1, [], true))->permitida());
    }

    public function testPoliticaUsuariosAplicaLaSeparacionDeFunciones(): void
    {
        $politica = new PoliticaUsuarios();
        $alcance = AlcanceDatos::deUsuario(1, [], true);
        $admin = self::usuario(1, 1, 'administrador', ['usuarios.gestionar'], [], true);
        self::assertTrue($politica->decidir($admin, 'usuarios.gestionar', new RecursoUsuario(2, 1, false), $alcance)->permitida());
        self::assertFalse($politica->decidir($admin, 'usuarios.gestionar', new RecursoUsuario(1, 1, false), $alcance)->permitida(), 'A sí mismo.');
        self::assertFalse($politica->decidir($admin, 'usuarios.gestionar', new RecursoUsuario(null, null, true), $alcance)->permitida(), 'Crear un superadministrador.');
        $otroCentro = $politica->decidir($admin, 'usuarios.gestionar', new RecursoUsuario(3, 2, false), $alcance);
        self::assertTrue($otroCentro->fueraDeAlcanceDelUsuario(), 'Otro centro responde 404.');
        $super = self::usuario(9, null, 'superadministrador', ['usuarios.gestionar']);
        self::assertTrue($politica->decidir($super, 'usuarios.gestionar', new RecursoUsuario(null, null, true), $alcance)->permitida());
        self::assertFalse($politica->decidir($super, 'usuarios.gestionar', new RecursoUsuario(9, null, true), $alcance)->permitida(), 'Tampoco el superadministrador se modifica a sí mismo.');
    }

    public function testNormalizacionDeConceptos(): void
    {
        self::assertSame('leche de vaca', ServicioCatalogos::normalizarConcepto("  Léche \t de   VACA "));
        self::assertSame('pina oromiel', ServicioCatalogos::normalizarConcepto('Piña Oromiel'));
    }

    public function testDescripcionDeBloqueos(): void
    {
        self::assertSame('3 gastos', ReglasDependencias::describir(['gastos' => 3]));
        self::assertSame('1 asignaciones, 3 gastos y 2 recaudos', ReglasDependencias::describir(['asignaciones' => 1, 'gastos' => 3, 'recaudos' => 2]));
    }

    /**
     * @param list<string> $permisos
     * @param list<int> $unidades
     */
    private static function usuario(int $id, ?int $centro, string $rol, array $permisos, array $unidades = [], bool $alcanceCentro = false): UsuarioSesion
    {
        return new UsuarioSesion(id: $id, centroId: $centro, rol: $rol, permisos: $permisos, roles: [$rol], unidades: $unidades, alcanceCentro: $alcanceCentro);
    }
}
