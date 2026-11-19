<?php

declare(strict_types=1);

namespace Pruebas\Unitarias;

use App\Integracion\FirmaIntegracion;
use App\Integracion\ValidadorLoteVentas;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Contrato de integración con el software de ventas (RF-095, RF-097; anexo E): firma HMAC con ventana de 5 minutos y
 * validación del lote. Las pruebas de contrato con Schemathesis contra el simulador corren en la CI (trabajo
 * «Contrato de integración»).
 */
final class ContratoVentasTest extends TestCase
{
    private const SECRETO = 'secreto-de-prueba-con-mas-de-32-caracteres';

    /** @return array<string,mixed> */
    private static function lote(): array
    {
        return [
            'centro' => '9124',
            'recepciones' => [['numero_interno' => 'GAN-REC-210126-001', 'fecha_recepcion' => '2026-01-21', 'unidad' => 'GAN', 'codigo_sgac' => '5013170101', 'cantidad' => '100.5', 'medida' => 'L']],
            'salidas' => [
                ['id_externo' => 'V-1', 'numero_interno' => 'GAN-REC-210126-001', 'fecha' => '2026-01-22', 'cantidad' => '10', 'medida' => 'L', 'precio_unitario' => '2200', 'canal' => 'VENTA_DIRECTA'],
                ['id_externo' => 'V-2', 'numero_interno' => 'GAN-REC-210126-001', 'fecha' => '2026-01-22', 'cantidad' => '800', 'medida' => 'L', 'precio_unitario' => '1800', 'canal' => 'AUTOCONSUMO', 'proceso' => 'AUTO_001'],
            ],
        ];
    }

    public function testUnLoteDelContratoEsValido(): void
    {
        self::assertSame([], (new ValidadorLoteVentas())->validar(self::lote()));
    }

    public function testUnaSalidaSinIdExternoSeRechaza(): void
    {
        $lote = self::lote();
        unset($lote['salidas'][0]['id_externo']);
        $errores = (new ValidadorLoteVentas())->validar($lote);
        self::assertSame([['indice' => 0, 'campo' => 'salidas.id_externo', 'mensaje' => 'Una salida sin id_externo se rechaza: es su identificador único (RF-097).']], $errores);
    }

    public function testReglasDelEsquema(): void
    {
        $validador = new ValidadorLoteVentas();
        $casos = [
            'autoconsumo sin proceso' => [static function (array &$l): void {
                unset($l['salidas'][1]['proceso']);
            }, 'salidas.proceso'],
            'propiedad no admitida' => [static function (array &$l): void {
                $l['salidas'][0]['nombre_cliente'] = 'Ana';
            }, 'salidas.nombre_cliente'],
            'número como texto' => [static function (array &$l): void {
                $l['recepciones'][0]['cantidad'] = 100;
            }, 'recepciones.cantidad'],
            'cantidad con coma' => [static function (array &$l): void {
                $l['recepciones'][0]['cantidad'] = '1.000,5';
            }, 'recepciones.cantidad'],
            'fecha inexistente' => [static function (array &$l): void {
                $l['salidas'][0]['fecha'] = '2026-02-30';
            }, 'salidas.fecha'],
            'medida desconocida' => [static function (array &$l): void {
                $l['salidas'][0]['medida'] = 'bulto';
            }, 'salidas.medida'],
            'canal desconocido' => [static function (array &$l): void {
                $l['salidas'][0]['canal'] = 'TRANSFERENCIA';
            }, 'salidas.canal'],
            'otro centro' => [static function (array &$l): void {
                $l['centro'] = '9999';
            }, 'centro'],
            'lote interno mal formado' => [static function (array &$l): void {
                $l['recepciones'][0]['numero_interno'] = 'gan-rec-1';
            }, 'recepciones.numero_interno'],
            'dígitos no ASCII' => [static function (array &$l): void {
                $l['recepciones'][0]['codigo_sgac'] = '٥٠١٣١٧٠١٠١';
            }, 'recepciones.codigo_sgac'],
        ];
        foreach ($casos as $caso => [$cambio, $campo]) {
            $lote = self::lote();
            $cambio($lote);
            $campos = array_column($validador->validar($lote), 'campo');
            self::assertContains($campo, $campos, $caso);
        }
        self::assertNotSame([], $validador->validar('no es un objeto'));
        self::assertNotSame([], $validador->validar(['centro' => '9124', 'recepciones' => [], 'salidas' => array_fill(0, 5001, self::lote()['salidas'][0])]));
    }

    public function testFirmaConVentanaDeCincoMinutosYRotacionDeClaves(): void
    {
        $firma = FirmaIntegracion::desdeTexto('ventas-2026:' . self::SECRETO . ',ventas-2025:otro-secreto-anterior-de-mas-de-32-caracteres');
        $ahora = new DateTimeImmutable('2026-11-19T15:00:00Z', new DateTimeZone('UTC'));
        $cuerpo = json_encode(self::lote(), JSON_THROW_ON_ERROR);
        $ruta = '/api/v1/integracion/ventas/lotes';
        $valida = FirmaIntegracion::firmar(self::SECRETO, 'post', $ruta, '2026-11-19T14:57:00Z', $cuerpo);

        self::assertNull($firma->verificar('POST', $ruta, 'ventas-2026', '2026-11-19T14:57:00Z', $valida, $cuerpo, $ahora));
        self::assertNotNull($firma->verificar('POST', $ruta, 'ventas-2026', '2026-11-19T14:57:00Z', $valida, $cuerpo . ' ', $ahora), 'Otro cuerpo.');
        self::assertNotNull($firma->verificar('POST', $ruta . '/x', 'ventas-2026', '2026-11-19T14:57:00Z', $valida, $cuerpo, $ahora), 'Otra ruta.');
        self::assertNotNull($firma->verificar('GET', $ruta, 'ventas-2026', '2026-11-19T14:57:00Z', $valida, $cuerpo, $ahora), 'Otro método.');
        self::assertNotNull($firma->verificar('POST', $ruta, 'ventas-2025', '2026-11-19T14:57:00Z', $valida, $cuerpo, $ahora), 'Otra clave.');
        self::assertNotNull($firma->verificar('POST', $ruta, 'desconocida', '2026-11-19T14:57:00Z', $valida, $cuerpo, $ahora));
        $vieja = FirmaIntegracion::firmar(self::SECRETO, 'POST', $ruta, '2026-11-19T14:54:59Z', $cuerpo);
        self::assertNotNull($firma->verificar('POST', $ruta, 'ventas-2026', '2026-11-19T14:54:59Z', $vieja, $cuerpo, $ahora), 'Más de 5 minutos: repetición.');
        self::assertNotNull($firma->verificar('POST', $ruta, 'ventas-2026', '19/11/2026 14:57', $valida, $cuerpo, $ahora), 'Fecha no ISO.');
        self::assertNotNull($firma->verificar('POST', $ruta, null, null, null, $cuerpo, $ahora));
        self::assertSame([], FirmaIntegracion::desdeTexto('corta:abc')->verificar('POST', $ruta, 'corta', '2026-11-19T14:57:00Z', $valida, $cuerpo, $ahora) === null ? ['aceptó'] : [], 'Un secreto de menos de 32 caracteres no se carga.');
    }
}
