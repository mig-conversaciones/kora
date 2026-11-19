<?php

declare(strict_types=1);

namespace App\Integracion;

/**
 * Validación de un lote del contrato de integración con el software de ventas (anexo E, esquema LoteIntegracion de
 * docs/api/integracion-ventas.yaml; RF-095, RF-097). Aplica las mismas reglas que el esquema, en el servidor y sin
 * dependencias: tipos, obligatorios, propiedades no admitidas, patrones, enumeraciones, longitudes y fechas reales.
 * Una salida sin id_externo se rechaza (RF-097) y una de autoconsumo exige su proceso (RF-043).
 *
 * Los errores siguen el formato del contrato (RFC 9457): [{indice?, campo, mensaje}].
 */
final class ValidadorLoteVentas
{
    public const MAXIMO_ELEMENTOS = 5000;
    private const MEDIDAS = ['L', 'kg', 'ud', 'lb', 'cubeta'];
    private const CANALES = ['VENTA_DIRECTA', 'AUTOCONSUMO'];

    /** campo => [obligatorio, validación] */
    private const RECEPCION = [
        'numero_interno' => [true, 'patron:/^[A-Z0-9]{2,8}-(REC|DEV)-[0-9]{6}-[0-9]{3,4}$/'],
        'fecha_recepcion' => [true, 'fecha'],
        'unidad' => [true, 'patron:/^[A-Z]{3}$/'],
        'codigo_sgac' => [true, 'patron:/^[0-9]{6,20}$/'],
        'cantidad' => [true, 'patron:/^[0-9]{1,11}(\.[0-9]{1,3})?$/'],
        'medida' => [true, 'medida'],
        'precio_sugerido' => [false, 'patron:/^[0-9]{1,13}(\.[0-9]{1,2})?$/'],
        'responsable' => [false, 'texto:0:150'],
    ];

    private const SALIDA = [
        'id_externo' => [true, 'texto:1:80'],
        'numero_interno' => [true, 'texto:0:'],
        'codigo_sgac' => [false, 'patron:/^[0-9]{6,20}$/'],
        'fecha' => [true, 'fecha'],
        'cantidad' => [true, 'patron:/^[0-9]{1,11}(\.[0-9]{1,3})?$/'],
        'medida' => [true, 'medida'],
        'precio_unitario' => [true, 'patron:/^[0-9]{1,13}(\.[0-9]{1,2})?$/'],
        'canal' => [true, 'canal'],
        'proceso' => [false, 'patron:/^AUTO_[0-9]+$/'],
        'factura' => [false, 'texto:0:40'],
        'referencia_transaccion' => [false, 'texto:0:80'],
    ];

    /** @return list<array{indice?:int,campo:string,mensaje:string}> vacío si el lote cumple el contrato */
    public function validar(mixed $lote): array
    {
        if (!is_array($lote) || array_is_list($lote) && $lote !== []) {
            return [['campo' => '', 'mensaje' => 'El cuerpo debe ser un objeto JSON con centro, recepciones y salidas.']];
        }
        $errores = [];
        foreach (array_diff(array_keys($lote), ['centro', 'recepciones', 'salidas']) as $sobrante) {
            $errores[] = ['campo' => (string) $sobrante, 'mensaje' => 'Propiedad no admitida por el contrato.'];
        }
        if (($lote['centro'] ?? null) !== '9124') {
            $errores[] = ['campo' => 'centro', 'mensaje' => 'El centro debe ser «9124».'];
        }
        foreach (['recepciones' => self::RECEPCION, 'salidas' => self::SALIDA] as $lista => $reglas) {
            $elementos = $lote[$lista] ?? null;
            if (!is_array($elementos) || !array_is_list($elementos)) {
                $errores[] = ['campo' => $lista, 'mensaje' => 'Debe ser una lista.'];
                continue;
            }
            if (count($elementos) > self::MAXIMO_ELEMENTOS) {
                $errores[] = ['campo' => $lista, 'mensaje' => 'Admite hasta 5.000 elementos por lote.'];
                continue;
            }
            foreach ($elementos as $indice => $elemento) {
                array_push($errores, ...$this->elemento($elemento, $reglas, $lista, $indice));
            }
        }
        return $errores;
    }

    /**
     * @param array<string,array{0:bool,1:string}> $reglas
     * @return list<array{indice:int,campo:string,mensaje:string}>
     */
    private function elemento(mixed $elemento, array $reglas, string $lista, int $indice): array
    {
        if (!is_array($elemento) || (array_is_list($elemento) && $elemento !== [])) {
            return [['indice' => $indice, 'campo' => $lista, 'mensaje' => 'Cada elemento debe ser un objeto.']];
        }
        $errores = [];
        foreach (array_diff(array_keys($elemento), array_keys($reglas)) as $sobrante) {
            $errores[] = ['indice' => $indice, 'campo' => "{$lista}.{$sobrante}", 'mensaje' => 'Propiedad no admitida por el contrato.'];
        }
        foreach ($reglas as $campo => [$obligatorio, $regla]) {
            if (!array_key_exists($campo, $elemento)) {
                if ($obligatorio) {
                    $errores[] = ['indice' => $indice, 'campo' => "{$lista}.{$campo}", 'mensaje' => $campo === 'id_externo'
                        ? 'Una salida sin id_externo se rechaza: es su identificador único (RF-097).'
                        : 'Es obligatorio.'];
                }
                continue;
            }
            $mensaje = self::regla($elemento[$campo], $regla);
            if ($mensaje !== null) {
                $errores[] = ['indice' => $indice, 'campo' => "{$lista}.{$campo}", 'mensaje' => $mensaje];
            }
        }
        if ($lista === 'salidas' && ($elemento['canal'] ?? null) === 'AUTOCONSUMO' && !array_key_exists('proceso', $elemento)) {
            $errores[] = ['indice' => $indice, 'campo' => 'salidas.proceso', 'mensaje' => 'Una entrega de autoconsumo exige su proceso (RF-043).'];
        }
        return $errores;
    }

    private static function regla(mixed $valor, string $regla): ?string
    {
        if (!is_string($valor)) {
            return 'Debe ser texto.';
        }
        [$tipo, $detalle] = array_pad(explode(':', $regla, 2), 2, '');
        return match ($tipo) {
            'patron' => preg_match($detalle, $valor) === 1 ? null : 'No tiene el formato del contrato.',
            'fecha' => self::fecha($valor) ? null : 'Debe ser una fecha AAAA-MM-DD real.',
            'medida' => in_array($valor, self::MEDIDAS, true) ? null : 'La medida debe ser L, kg, ud, lb o cubeta.',
            'canal' => in_array($valor, self::CANALES, true) ? null : 'El canal debe ser VENTA_DIRECTA o AUTOCONSUMO.',
            'texto' => self::longitud($valor, $detalle),
            default => 'Regla desconocida.',
        };
    }

    private static function fecha(string $valor): bool
    {
        return preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $valor, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function longitud(string $valor, string $detalle): ?string
    {
        [$minimo, $maximo] = array_pad(explode(':', $detalle), 2, '');
        $largo = mb_strlen($valor);
        if ($largo < (int) $minimo) {
            return "Debe tener al menos {$minimo} caracteres.";
        }
        return $maximo !== '' && $largo > (int) $maximo ? "Admite hasta {$maximo} caracteres." : null;
    }
}
