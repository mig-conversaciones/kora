<?php

declare(strict_types=1);

namespace Pruebas\Unitarias;

use App\Importacion\Fuentes\FuenteCatalogo;
use App\Importacion\Fuentes\FuentePresupuesto;
use App\Importacion\Normalizador;
use App\Importacion\RegistroFuentes;
use PHPUnit\Framework\TestCase;

/** Normalización de las fuentes Excel (Anexo G, RF-086) y mapeo de columnas por nombre normalizado con alias. */
final class NormalizadorTest extends TestCase
{
    public function testUnidadesUpConPrefijoTildesYAmbiguedad(): void
    {
        self::assertSame('GAN', Normalizador::unidad('UP_Ganaderia'));
        self::assertSame('AGR', Normalizador::unidad('up_Agricola'));
        self::assertSame('GAN', Normalizador::unidad('  Ganadería '));
        self::assertSame('GOU', Normalizador::unidad('UP_Gourmet - Café'));
        self::assertSame('CAF', Normalizador::unidad('UP_Cafeteria Aprendices'));
        self::assertSame('AMBIGUA', Normalizador::unidad('Ganadería y Granja'), 'No se prorratea: queda por clasificar.');
        self::assertSame('SIN', Normalizador::unidad('Bodega central'));
        self::assertNull(Normalizador::unidad(''));
    }

    public function testMedidasFechasYNumeros(): void
    {
        foreach (['Litros' => 'L', 'Litro' => 'L', 'Kilos' => 'kg', 'Kilogramo' => 'kg', 'KG' => 'kg', 'KILO ' => 'kg', 'LIBRA' => 'lb', 'Unidad' => 'ud', 'Unidades' => 'ud', 'Cubeta 30U' => 'cubeta'] as $texto => $medida) {
            self::assertSame($medida, Normalizador::medida($texto), $texto);
        }
        self::assertNull(Normalizador::medida('Hectárea'), 'Las medidas de servicios no caben en el esquema.');
        self::assertSame('2026-01-01', Normalizador::fecha(46023), 'Serial de Excel con época 1899-12-30.');
        self::assertSame('2026-01-21', Normalizador::fecha('21/01/2026'));
        self::assertSame('2026-11-09', Normalizador::fecha(new \DateTimeImmutable('2026-11-09 00:00:00')));
        self::assertNull(Normalizador::fecha('2026-02-30'));
        self::assertSame('3045000.00', Normalizador::numero('$ 3.045.000'));
        self::assertSame('3045000.50', Normalizador::numero('3.045.000,50'));
        self::assertSame('19000.00', Normalizador::numero(19000.0));
        self::assertSame('12.500', Normalizador::numero(12.5, 3));
        self::assertNull(Normalizador::numero(-5));
        self::assertSame('leche de vaca', Normalizador::encabezado('  Leche   de VACA '));
        self::assertSame('up destino y o cliente', Normalizador::encabezado('UP_Destino y/o Cliente'));
        self::assertSame('# servicios post parto', Normalizador::encabezado('# SERVICIOS POST PARTO'));
    }

    public function testMapeoExactoAntesQueParcialYColumnasObligatorias(): void
    {
        $catalogo = RegistroFuentes::mapear(new FuenteCatalogo(), array_map([Normalizador::class, 'encabezado'], ['Nombre', 'Codigo SGAC', 'Unidad  Productiva', 'Precio Minimo', 'Precio Maximo', 'Precio de Venta', 'Medida', 'Clase', 'CodigoFamilia', 'NombreFamilia']));
        self::assertSame([], $catalogo['faltantes']);
        self::assertSame(0, $catalogo['mapa']['nombre'], '«Nombre» exacto antes que «NombreFamilia».');
        self::assertSame(9, $catalogo['mapa']['nombre_familia']);
        $presupuesto = RegistroFuentes::mapear(new FuentePresupuesto(), array_map([Normalizador::class, 'encabezado'], ['ID', 'Valor Total Proyecto', 'GASTOS: Mantenimiento Maquinaria y Equipo', 'GASTOS: Alimentación para semovientes', 'GASTOS: Materiales Formación']));
        self::assertSame([], $presupuesto['faltantes'], 'Los encabezados largos se reconocen por inclusión.');
        self::assertSame(['codigo'], RegistroFuentes::mapear(new FuenteCatalogo(), ['nombre', 'medida'])['faltantes']);
        // RF-096: un archivo con las columnas del contrato de integración se mapea sin errores.
        $contrato = RegistroFuentes::mapear(new \App\Importacion\Fuentes\FuenteVentas(), array_map([Normalizador::class, 'encabezado'], ['id_externo', 'numero_interno', 'codigo_sgac', 'fecha', 'cantidad', 'medida', 'precio_unitario', 'canal', 'proceso', 'factura', 'referencia_transaccion']));
        self::assertSame([], $contrato['faltantes']);
        self::assertSame(0, $contrato['mapa']['id_externo']);
        $recepciones = RegistroFuentes::mapear(new \App\Importacion\Fuentes\FuenteProduccion(), array_map([Normalizador::class, 'encabezado'], ['numero_interno', 'fecha_recepcion', 'unidad', 'codigo_sgac', 'cantidad', 'medida', 'precio_sugerido', 'responsable']));
        self::assertSame([], $recepciones['faltantes']);
        $detectadas = (new RegistroFuentes())->detectar([
            ['nombre' => 'Autoconsumo_2026', 'visible' => true, 'filas' => 7, 'encabezados' => [array_map([Normalizador::class, 'encabezado'], ['ID_proceso', 'UP_Origen', 'UP_Destino y/o Cliente', 'Producto', 'Cant_Autoconsumo', 'Unidad de medida', 'Valor'])]],
            ['nombre' => 'Hoja1', 'visible' => true, 'filas' => 3, 'encabezados' => [['a', 'b']]],
        ]);
        self::assertCount(1, $detectadas);
        self::assertSame('cupos_autoconsumo', $detectadas[0]['fuente']->clave());
    }
}
