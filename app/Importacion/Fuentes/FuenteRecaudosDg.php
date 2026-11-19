<?php

declare(strict_types=1);

namespace App\Importacion\Fuentes;

use App\Importacion\ContextoImportacion;
use App\Importacion\FuenteImportacion;
use App\Importacion\Normalizador;
use App\Nucleo\BaseDatos;

/**
 * Fuente 2: recaudos de la Dirección General («Ingresos_Centro…», G.1, RF-030, RF-034). Los nombres del pagador no se
 * leen; la identificación se guarda como HMAC y sus 4 últimos dígitos. Clave natural: cupón + prefactura + factura
 * electrónica + valor + fecha. Un recaudo ya registrado no se modifica (su clasificación pudo corregirse a mano).
 */
final class FuenteRecaudosDg extends FuenteImportacion
{
    public function clave(): string
    {
        return 'recaudos_dg';
    }

    public function nombre(): string
    {
        return 'Recaudos de la DG (Ingresos_Centro…)';
    }

    public function permiso(): string
    {
        return 'recaudos.importar';
    }

    public function patronHoja(): string
    {
        return '/^ingresos centro/';
    }

    public function tabla(): string
    {
        return 'recaudos';
    }

    public function campoSuma(): string
    {
        return 'valor';
    }

    /** @return list<string> */
    public function camposSensibles(): array
    {
        return ['identificacion'];
    }

    public function columnas(): array
    {
        return [
            'codigo' => ['alias' => ['codigo'], 'obligatoria' => true],
            'identificacion' => ['alias' => ['identificacion'], 'obligatoria' => false],
            'valor' => ['alias' => ['valor pago', 'valor'], 'obligatoria' => true],
            'cupon_pago' => ['alias' => ['cuponpago', 'cupon pago', 'cupon'], 'obligatoria' => true],
            'fecha' => ['alias' => ['fecha'], 'obligatoria' => true],
            'producto' => ['alias' => ['producto'], 'obligatoria' => true],
            'unidad' => ['alias' => ['unidad productiva'], 'obligatoria' => true],
            'prefactura' => ['alias' => ['prefactura'], 'obligatoria' => false],
            'tipo_ingreso' => ['alias' => ['tipoingreso', 'tipo ingreso'], 'obligatoria' => true],
            'pasarela' => ['alias' => ['origenpago', 'origen pago'], 'obligatoria' => false],
            'factura' => ['alias' => ['nrofacelec', 'factura electronica'], 'obligatoria' => false],
        ];
    }

    public function camposComparables(): array
    {
        return [];
    }

    public function validar(array $valores, ContextoImportacion $contexto): ?array
    {
        if (Normalizador::texto($valores['codigo'] ?? null) === null && Normalizador::texto($valores['cupon_pago'] ?? null) === null) {
            return null;
        }
        $errores = [];
        if (Normalizador::soloDigitos($valores['codigo'] ?? '') !== '9124') {
            self::error($errores, 'codigo', 'El recaudo no es del centro 9124.');
        }
        $valor = Normalizador::numero($valores['valor'] ?? null);
        if ($valor === null) {
            self::error($errores, 'valor', 'El valor del pago no es un número mayor o igual que 0.');
        }
        $fecha = Normalizador::fecha($valores['fecha'] ?? null);
        if ($fecha === null) {
            self::error($errores, 'fecha', 'La fecha del pago no es válida.');
        }
        $tipo = Normalizador::texto($valores['tipo_ingreso'] ?? null);
        if ($tipo === null) {
            self::error($errores, 'tipo_ingreso', 'Falta el tipo de ingreso.');
        }
        $concepto = Normalizador::texto($valores['producto'] ?? null);
        $unidadFuente = Normalizador::texto($valores['unidad'] ?? null);
        $codigoUnidad = Normalizador::unidad($unidadFuente);
        $unidad = in_array($codigoUnidad, [null, 'SIN', 'AMBIGUA'], true) ? null : $contexto->unidadId($codigoUnidad);
        $producto = $contexto->homologar($concepto);
        $identificacion = Normalizador::soloDigitos($valores['identificacion'] ?? '');
        $cupon = Normalizador::texto($valores['cupon_pago'] ?? null);
        $prefactura = Normalizador::texto($valores['prefactura'] ?? null);
        $factura = Normalizador::texto($valores['factura'] ?? null);
        $datos = [
            'unidad_id' => $unidad,
            'producto_id' => $producto,
            'concepto_fuente' => $concepto === null ? null : mb_substr($concepto, 0, 200),
            'unidad_fuente' => $unidadFuente === null ? null : mb_substr($unidadFuente, 0, 100),
            'fecha_pago' => $fecha,
            'valor' => $valor,
            'cupon_pago' => $cupon === null ? null : mb_substr($cupon, 0, 60),
            'prefactura' => $prefactura === null ? null : mb_substr($prefactura, 0, 40),
            'factura_electronica' => $factura === null ? null : mb_substr($factura, 0, 40),
            'pasarela' => Normalizador::texto($valores['pasarela'] ?? null),
            'tipo_ingreso' => $tipo === null ? '' : mb_substr($tipo, 0, 40),
            'identificacion_hmac' => $identificacion === '' ? null : $contexto->hmacPii($identificacion),
            'identificacion_ultimos4' => $identificacion === '' ? null : substr($identificacion, -4),
            'estado_clasificacion' => $codigoUnidad === 'AMBIGUA' ? 'ambiguo' : ($unidad !== null && ($producto !== null || $concepto !== null) ? 'clasificado' : 'pendiente'),
        ];
        $clave = hash('sha256', implode('|', [$datos['cupon_pago'] ?? '', $datos['prefactura'] ?? '', $datos['factura_electronica'] ?? '', (string) $valor, (string) $fecha]));
        $advertencias = [];
        if ($codigoUnidad === 'AMBIGUA') {
            $advertencias[] = "La unidad «{$unidadFuente}» nombra varias unidades: queda por clasificar, sin prorratear.";
        } elseif ($unidad === null && $unidadFuente !== null) {
            $advertencias[] = "La unidad «{$unidadFuente}» no se reconoce: queda por clasificar.";
        }
        return ['datos' => $datos, 'clave' => $clave, 'errores' => $errores, 'advertencias' => $advertencias];
    }

    public function actual(ContextoImportacion $contexto, array $datos): ?array
    {
        return $contexto->bd->fila('SELECT id FROM recaudos WHERE centro_id = :c AND clave_natural = :k', ['c' => $contexto->centroId, 'k' => $this->claveNatural($datos)]);
    }

    public function aplicar(ContextoImportacion $contexto, array $datos, ?array $actual, array $procedencia): int
    {
        if ($actual !== null) {
            return (int) $actual['id'];
        }
        return $this->insertarFila($contexto->bd, $datos + ['centro_id' => $contexto->centroId, 'clave_natural' => $this->claveNatural($datos)], $procedencia, $contexto->usuarioId);
    }

    public function dependencias(BaseDatos $bd, int $id, int $loteId): ?string
    {
        return (int) $bd->valor('SELECT COUNT(*) FROM conciliaciones_recaudo WHERE recaudo_id = :r AND archivado_en IS NULL', ['r' => $id]) > 0
            ? "El recaudo {$id} ya está conciliado con ventas." : null;
    }

    /** @param array<string,mixed> $datos */
    private function claveNatural(array $datos): string
    {
        return hash('sha256', implode('|', [$datos['cupon_pago'] ?? '', $datos['prefactura'] ?? '', $datos['factura_electronica'] ?? '', (string) $datos['valor'], (string) $datos['fecha_pago']]));
    }
}
