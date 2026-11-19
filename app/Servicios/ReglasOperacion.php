<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\Entrada;

/**
 * Reglas de la operación productiva (RF-040, RF-043, RF-046, RF-049), con los mensajes del prototipo
 * (referencias/js/schemas.js). Son funciones puras: reciben los datos ya leídos de la base y devuelven los errores
 * por campo, de modo que cada regla tiene su prueba unitaria sin base de datos. Las cantidades son decimales
 * canónicos de 3 decimales y nunca pasan por float.
 */
final class ReglasOperacion
{
    /** Código SGAC de la leche de vaca: la única entrada válida de una transformación láctea (RF-046, RF-047). */
    public const CODIGO_LECHE = '5013170101';

    /**
     * Recepción de un lote (RF-040).
     *
     * @param array{unidad_id:int,producto_id:int,medida:string,numero_interno:string,fecha_recepcion:string,cantidad:string} $nueva
     * @param array{medida_producto:string,duplicado:bool,salidas:int,entregado:string,primera_salida:?string,fecha_transformacion:?string} $contexto
     * @param array<string,mixed>|null $actual
     * @return array<string,string>
     */
    public static function recepcion(array $nueva, array $contexto, ?array $actual): array
    {
        $errores = [];
        if ($nueva['medida'] !== $contexto['medida_producto']) {
            $errores['medida'] = 'La medida de la recepción debe coincidir con la del catálogo.';
        }
        if ($contexto['duplicado']) {
            $errores['producto_id'] = 'El lote ya contiene una recepción de este producto.';
        }
        if ($actual !== null && $contexto['salidas'] > 0) {
            foreach (['producto_id', 'medida', 'unidad_id', 'numero_interno'] as $campo) {
                if ((string) $nueva[$campo] !== (string) $actual[$campo]) {
                    $errores[$campo] = 'Este lote tiene salidas. Conserva su producto, unidad, medida y número interno.';
                }
            }
        }
        if (Entrada::comparar($contexto['entregado'], $nueva['cantidad']) > 0) {
            $errores['cantidad'] = 'La recepción no puede ser menor que las cantidades ya entregadas.';
        }
        if ($contexto['primera_salida'] !== null && $contexto['primera_salida'] < $nueva['fecha_recepcion']) {
            $errores['fecha_recepcion'] = 'La recepción no puede ser posterior a sus salidas.';
        }
        if ($contexto['fecha_transformacion'] !== null && $contexto['fecha_transformacion'] !== $nueva['fecha_recepcion']) {
            $errores['fecha_recepcion'] = 'El derivado está vinculado a una transformación: conserva la fecha de la transformación.';
        }
        return $errores;
    }

    /**
     * Salida de un lote (RF-043, RF-046).
     *
     * @param array{fecha:string,cantidad:string,canal:string} $nueva
     * @param array{fecha_recepcion:string,cantidad:string,unidad_id:int,medida:string,producto_id:int,producto_codigo:string,producto_nombre:string} $lote
     * @param string $otras cantidad de las demás salidas activas del lote
     * @param array{unidad_id:int,medida:string,vigencia:int,producto_id:?int,producto_texto:string}|null $proceso
     * @param array{litros:string,primera_fecha:?string,cantidad:int} $transformaciones de esta salida (si ya existe)
     * @return array<string,string>
     */
    public static function salida(array $nueva, array $lote, string $otras, ?array $proceso, array $transformaciones): array
    {
        $errores = [];
        if ($nueva['fecha'] < $lote['fecha_recepcion']) {
            $errores['fecha'] = 'La salida no puede ser anterior a la recepción.';
        }
        $saldo = Entrada::desdeUnidades(max(0, Entrada::aUnidades($lote['cantidad'], 3) - Entrada::aUnidades($otras, 3)), 3);
        if (Entrada::comparar(Entrada::sumarCon(3, $otras, $nueva['cantidad']), $lote['cantidad']) > 0) {
            $errores['cantidad'] = 'La entrega supera el saldo del lote (' . self::cantidadLegible($saldo) . ' ' . $lote['medida'] . ').';
        }
        if ($nueva['canal'] === 'AUTOCONSUMO') {
            if ($proceso === null) {
                $errores['proceso_id'] = 'Vincula la entrega con un proceso de autoconsumo.';
            } elseif ($proceso['unidad_id'] !== $lote['unidad_id'] || $proceso['medida'] !== $lote['medida'] || $proceso['vigencia'] !== (int) substr($nueva['fecha'], 0, 4)) {
                $errores['proceso_id'] = 'El proceso debe coincidir con la unidad origen, medida y vigencia de la entrega.';
            } elseif (!self::mismoProducto($proceso, $lote)) {
                $errores['proceso_id'] = 'El producto de la entrega no coincide con el del proceso.';
            }
        }
        if ($transformaciones['cantidad'] > 0) {
            if ($nueva['canal'] !== 'AUTOCONSUMO' || $lote['producto_codigo'] !== self::CODIGO_LECHE || $lote['medida'] !== 'L') {
                $errores['canal'] = 'Esta entrega alimenta una transformación láctea. Conserva su origen de leche.';
            }
            if (Entrada::comparar($transformaciones['litros'], $nueva['cantidad']) > 0) {
                $errores['cantidad'] = 'La cantidad es menor que la leche ya asignada a transformaciones.';
            }
            if ($transformaciones['primera_fecha'] !== null && $transformaciones['primera_fecha'] < $nueva['fecha']) {
                $errores['fecha'] = 'La entrega no puede ser posterior a sus transformaciones.';
            }
        }
        return $errores;
    }

    /**
     * Transformación láctea (RF-049).
     *
     * @param array{fecha:string,litros:string,unidad_id:int} $nueva
     * @param array{fecha:string,cantidad:string,unidad_id:int,canal:string,medida:string,producto_codigo:string} $leche
     * @param array{fecha_recepcion:string,vinculada:bool} $derivado
     * @param string $asignadoOtras litros de las demás transformaciones activas de la misma entrega
     * @return array<string,string>
     */
    public static function transformacion(array $nueva, array $leche, array $derivado, string $asignadoOtras): array
    {
        $errores = [];
        if ($leche['canal'] !== 'AUTOCONSUMO' || $leche['producto_codigo'] !== self::CODIGO_LECHE || $leche['medida'] !== 'L') {
            $errores['salida_leche_id'] = 'La entrada de una transformación es una entrega de leche por autoconsumo, en litros.';
        }
        if ($nueva['unidad_id'] !== $leche['unidad_id']) {
            $errores['salida_leche_id'] = 'La unidad debe coincidir con el origen de la entrega de leche.';
        }
        if ($nueva['fecha'] < $leche['fecha'] || $derivado['fecha_recepcion'] < $leche['fecha'] || $nueva['fecha'] !== $derivado['fecha_recepcion']) {
            $errores['fecha'] = 'La transformación debe tener la fecha de recepción del derivado y ser posterior a la entrega de leche.';
        }
        if ($derivado['vinculada']) {
            $errores['recepcion_derivado_id'] = 'El lote del derivado ya está vinculado a una transformación.';
        }
        if (Entrada::comparar(Entrada::sumarCon(3, $asignadoOtras, $nueva['litros']), $leche['cantidad']) > 0) {
            $errores['litros_asignados'] = 'Los litros asignados superan la entrega de leche disponible.';
        }
        return $errores;
    }

    /** Número interno sugerido: {PREFIJO}-REC-{DDMMAA}-{NNN} (RF-039). */
    public static function numeroInterno(string $prefijo, string $fecha, int $consecutivo): string
    {
        return sprintf('%s-REC-%s%s%s-%03d', strtoupper($prefijo), substr($fecha, 8, 2), substr($fecha, 5, 2), substr($fecha, 2, 2), $consecutivo);
    }

    /** «1234.500» → «1.234,5» para los mensajes. */
    public static function cantidadLegible(string $cantidad): string
    {
        [$entero, $fraccion] = array_pad(explode('.', $cantidad, 2), 2, '');
        $fraccion = rtrim($fraccion, '0');
        return number_format((float) $entero, 0, ',', '.') . ($fraccion === '' ? '' : ',' . $fraccion);
    }

    /**
     * @param array{producto_id:?int,producto_texto:string} $proceso
     * @param array{producto_id:int,producto_codigo:string,producto_nombre:string} $lote
     */
    private static function mismoProducto(array $proceso, array $lote): bool
    {
        if ($proceso['producto_id'] !== null && $proceso['producto_id'] === $lote['producto_id']) {
            return true;
        }
        $textoProceso = ServicioCatalogos::normalizarConcepto($proceso['producto_texto']);
        if ($textoProceso === ServicioCatalogos::normalizarConcepto($lote['producto_nombre'])) {
            return true;
        }
        return $lote['producto_codigo'] === self::CODIGO_LECHE && $textoProceso === 'leche';
    }
}
