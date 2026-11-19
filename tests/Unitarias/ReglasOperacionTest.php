<?php

declare(strict_types=1);

namespace Pruebas\Unitarias;

use App\Nucleo\Entrada;
use App\Servicios\ReglasHato;
use App\Servicios\ReglasOperacion;
use App\Servicios\ServicioTransformaciones;
use PHPUnit\Framework\TestCase;

/**
 * Reglas puras de la operación productiva (RF-040, RF-043, RF-046, RF-049) y del hato (RF-054 a RF-056), con los
 * mensajes del prototipo y los límites exactos de los semáforos.
 */
final class ReglasOperacionTest extends TestCase
{
    private const LOTE = ['fecha_recepcion' => '2026-10-05', 'cantidad' => '100.000', 'unidad_id' => 1, 'medida' => 'L', 'producto_id' => 7, 'producto_codigo' => '5013170101', 'producto_nombre' => 'Leche de vaca'];
    private const SIN_TRANSFORMACIONES = ['litros' => '0', 'primera_fecha' => null, 'cantidad' => 0];

    public function testValorDeSalidaRedondeadoYConCotaDeDesbordamiento(): void
    {
        self::assertSame('3334.83', Entrada::valorSalida('3.333', '1000.55'));
        self::assertSame('0.01', Entrada::valorSalida('0.005', '1.00'), 'Medio centavo redondea hacia arriba.');
        self::assertSame('0.00', Entrada::valorSalida('0.004', '1.00'));
        self::assertNull(Entrada::valorSalida('9999999999.999', '9999999999999.99'), 'Fuera de DECIMAL(15,2).');
        self::assertSame('12.500', Entrada::sumarCon(3, '10.250', '2.25'));
        self::assertSame('-0.500', Entrada::desdeUnidades(Entrada::aUnidades('1.5', 3) - Entrada::aUnidades('2', 3), 3));
    }

    public function testReglasDeLaRecepcion(): void
    {
        $nueva = ['unidad_id' => 1, 'producto_id' => 7, 'medida' => 'L', 'numero_interno' => 'GAN-REC-051026-001', 'fecha_recepcion' => '2026-10-05', 'cantidad' => '80.000'];
        $contexto = ['medida_producto' => 'L', 'duplicado' => false, 'salidas' => 2, 'entregado' => '90.000', 'primera_salida' => '2026-10-04', 'fecha_transformacion' => null];
        $errores = ReglasOperacion::recepcion($nueva, $contexto, ['producto_id' => 8] + $nueva);
        self::assertSame('La recepción no puede ser menor que las cantidades ya entregadas.', $errores['cantidad']);
        self::assertSame('La recepción no puede ser posterior a sus salidas.', $errores['fecha_recepcion']);
        self::assertSame('Este lote tiene salidas. Conserva su producto, unidad, medida y número interno.', $errores['producto_id']);
        self::assertSame(['medida' => 'La medida de la recepción debe coincidir con la del catálogo.', 'producto_id' => 'El lote ya contiene una recepción de este producto.'], ReglasOperacion::recepcion(['medida' => 'kg', 'cantidad' => '100'] + $nueva, ['duplicado' => true, 'salidas' => 0, 'entregado' => '0', 'primera_salida' => null] + $contexto, null));
        self::assertSame('GAN-REC-210126-007', ReglasOperacion::numeroInterno('gan', '2026-01-21', 7));
    }

    public function testReglasDeLaSalida(): void
    {
        $salida = ['fecha' => '2026-10-04', 'cantidad' => '30.500', 'canal' => 'VENTA_DIRECTA'];
        $errores = ReglasOperacion::salida($salida, self::LOTE, '70', null, self::SIN_TRANSFORMACIONES);
        self::assertSame('La salida no puede ser anterior a la recepción.', $errores['fecha']);
        self::assertSame('La entrega supera el saldo del lote (30 L).', $errores['cantidad']);
        self::assertSame([], ReglasOperacion::salida(['fecha' => '2026-10-05', 'cantidad' => '30'] + $salida, self::LOTE, '70', null, self::SIN_TRANSFORMACIONES), 'Agotar el saldo exacto es válido.');

        $autoconsumo = ['fecha' => '2026-10-06', 'cantidad' => '10', 'canal' => 'AUTOCONSUMO'];
        self::assertSame('Vincula la entrega con un proceso de autoconsumo.', ReglasOperacion::salida($autoconsumo, self::LOTE, '0', null, self::SIN_TRANSFORMACIONES)['proceso_id']);
        $proceso = ['unidad_id' => 1, 'medida' => 'L', 'vigencia' => 2026, 'producto_id' => null, 'producto_texto' => 'Leche'];
        self::assertSame([], ReglasOperacion::salida($autoconsumo, self::LOTE, '0', $proceso, self::SIN_TRANSFORMACIONES), '«Leche» equivale al 5013170101.');
        self::assertSame('El proceso debe coincidir con la unidad origen, medida y vigencia de la entrega.', ReglasOperacion::salida($autoconsumo, self::LOTE, '0', ['vigencia' => 2025] + $proceso, self::SIN_TRANSFORMACIONES)['proceso_id']);
        self::assertSame('El producto de la entrega no coincide con el del proceso.', ReglasOperacion::salida($autoconsumo, self::LOTE, '0', ['producto_texto' => 'Queso'] + $proceso, self::SIN_TRANSFORMACIONES)['proceso_id']);
        self::assertSame([], ReglasOperacion::salida($autoconsumo, self::LOTE, '0', ['producto_texto' => '  LÉCHE de vaca '] + $proceso, self::SIN_TRANSFORMACIONES), 'El nombre se compara sin tildes, mayúsculas ni espacios extra.');

        $conTransformaciones = ['litros' => '40', 'primera_fecha' => '2026-10-05', 'cantidad' => 1];
        $errores = ReglasOperacion::salida(['cantidad' => '30', 'canal' => 'VENTA_DIRECTA'] + $autoconsumo, self::LOTE, '0', null, $conTransformaciones);
        self::assertSame('Esta entrega alimenta una transformación láctea. Conserva su origen de leche.', $errores['canal']);
        self::assertSame('La cantidad es menor que la leche ya asignada a transformaciones.', $errores['cantidad']);
        self::assertSame('La entrega no puede ser posterior a sus transformaciones.', $errores['fecha']);
    }

    public function testReglasDeLaTransformacion(): void
    {
        $leche = ['fecha' => '2026-10-06', 'cantidad' => '100.000', 'unidad_id' => 1, 'canal' => 'AUTOCONSUMO', 'medida' => 'L', 'producto_codigo' => '5013170101'];
        $nueva = ['fecha' => '2026-10-07', 'litros' => '60', 'unidad_id' => 1];
        self::assertSame([], ReglasOperacion::transformacion($nueva, $leche, ['fecha_recepcion' => '2026-10-07', 'vinculada' => false], '40'), 'Asignar exactamente lo disponible es válido.');
        $errores = ReglasOperacion::transformacion(['fecha' => '2026-10-05'] + $nueva, $leche, ['fecha_recepcion' => '2026-10-07', 'vinculada' => true], '40.001');
        self::assertSame('Los litros asignados superan la entrega de leche disponible.', $errores['litros_asignados']);
        self::assertSame('La transformación debe tener la fecha de recepción del derivado y ser posterior a la entrega de leche.', $errores['fecha']);
        self::assertSame('El lote del derivado ya está vinculado a una transformación.', $errores['recepcion_derivado_id']);
        self::assertSame('La unidad debe coincidir con el origen de la entrega de leche.', ReglasOperacion::transformacion(['unidad_id' => 2] + $nueva, $leche, ['fecha_recepcion' => '2026-10-07', 'vinculada' => false], '0')['salida_leche_id']);
        self::assertSame('10.00', ServicioTransformaciones::rendimiento('60', '6'));
        self::assertSame('10.17', ServicioTransformaciones::rendimiento('61', '6'), '10,1666… redondea a 10,17.');
        self::assertNull(ServicioTransformaciones::rendimiento('60', '0'));
    }

    public function testCalculosYFechasDelHato(): void
    {
        self::assertSame(440, ReglasHato::iep('2024-06-01', '2025-08-15'));
        self::assertNull(ReglasHato::iep(null, '2025-08-15'));
        self::assertSame(127, ReglasHato::diasAbiertos('2025-08-15', '2025-12-20', '2026-11-09'));
        self::assertSame(451, ReglasHato::diasAbiertos('2025-08-15', null, '2026-11-09'), 'Sin servicio efectivo cuenta hasta la fecha del corte.');
        self::assertSame(['penultimo_parto' => 'Los eventos reproductivos deben ser posteriores al nacimiento.'], ReglasHato::validarFechas('2019-03-01', ['penultimo_parto' => '2018-01-01'], '2026-11-09'));
        self::assertSame(['penultimo_parto' => 'El penúltimo parto debe ser anterior al último.'], ReglasHato::validarFechas(null, ['penultimo_parto' => '2025-08-15', 'ultimo_parto' => '2025-08-15'], '2026-11-09'));
        self::assertSame([], ReglasHato::validarFechas('2019-03-01', ['penultimo_parto' => '2024-06-01', 'ultimo_parto' => '2025-08-15'], '2026-11-09'));
        self::assertTrue(ReglasHato::difiere(442, 440));
        self::assertFalse(ReglasHato::difiere(441, 440), 'Tolerancia de ±1 día.');
        self::assertSame('PREÑADA', ReglasHato::normalizarEstado('  preñada '));
    }

    /** RF-056: límites exactos de 2.10. */
    public function testLimitesExactosDeLosSemaforos(): void
    {
        $tono = static fn (array $s): string => $s['tono'] . ':' . $s['texto'];
        self::assertSame('neutra:Sin dato', $tono(ReglasHato::semaforoIep(null)));
        self::assertSame('exito:En meta', $tono(ReglasHato::semaforoIep(365)));
        self::assertSame('advertencia:Vigilar', $tono(ReglasHato::semaforoIep(366)));
        self::assertSame('advertencia:Vigilar', $tono(ReglasHato::semaforoIep(400)));
        self::assertSame('error:Fuera de meta', $tono(ReglasHato::semaforoIep(401)));

        self::assertSame('neutra:Sin dato', $tono(ReglasHato::semaforoDiasAbiertos(null)));
        self::assertSame('neutra:Sin umbral', $tono(ReglasHato::semaforoDiasAbiertos(90)));
        self::assertSame('exito:En meta', $tono(ReglasHato::semaforoDiasAbiertos(91)));
        self::assertSame('exito:En meta', $tono(ReglasHato::semaforoDiasAbiertos(120)));
        self::assertSame('advertencia:Vigilar', $tono(ReglasHato::semaforoDiasAbiertos(121)));
        self::assertSame('advertencia:Vigilar', $tono(ReglasHato::semaforoDiasAbiertos(135)));
        self::assertSame('error:Fuera de meta', $tono(ReglasHato::semaforoDiasAbiertos(136)));
        self::assertSame('alerta', ReglasHato::semaforoIep(370)['icono'], 'El semáforo combina color, texto e icono.');
    }
}
