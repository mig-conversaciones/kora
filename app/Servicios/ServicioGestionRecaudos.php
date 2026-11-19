<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Importacion\ContextoImportacion;
use App\Importacion\Normalizador;
use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;
use App\Politicas\RecursoUnidad;

/**
 * Clasificación y conciliación de recaudos (RF-031, RF-032), solo para el Grupo de Recaudo (PoliticaRecaudos).
 *   - Las sugerencias (producto homologado, unidad declarada en el archivo, unidad y producto de la venta con la misma
 *     prefactura) nunca se aplican sin confirmación. Un recaudo ambiguo se decide a mano; no se prorratea.
 *   - Clasificar en lote crea un evento de auditoría por recaudo con el valor anterior y el nuevo.
 *   - La conciliación vincula un recaudo con una o varias ventas directas. Si los valores difieren queda «con
 *     diferencia» y aparece en el informe del periodo. Recaudos y ventas nunca se suman en IND_001 (RF-065).
 */
final class ServicioGestionRecaudos
{
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly AutorizacionRecurso $autorizacion,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioVersionDatos $versiones,
    ) {
    }

    /**
     * Recaudos por clasificar (sin unidad, sin producto ni concepto, o ambiguos) con sus sugerencias.
     *
     * @return list<array<string,mixed>>
     */
    public function porClasificar(Solicitud $solicitud): array
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $filas = $this->bd->consultar(
            "SELECT id, fecha_pago, valor, concepto_fuente, unidad_fuente, unidad_id, producto_id, prefactura, factura_electronica, estado_clasificacion, version
               FROM recaudos WHERE centro_id = :c AND archivado_en IS NULL AND estado_clasificacion IN ('pendiente', 'ambiguo')
              ORDER BY fecha_pago, id LIMIT 200",
            ['c' => $alcance->centroId]
        );
        $contexto = new ContextoImportacion($this->bd, $alcance->centroId, AutorizacionRecurso::usuario($solicitud)->id, [], $alcance, []);
        foreach ($filas as &$fila) {
            $sugerencias = [];
            $codigo = Normalizador::unidad($fila['unidad_fuente']);
            if (!in_array($codigo, [null, 'SIN', 'AMBIGUA'], true) && ($unidad = $contexto->unidadId($codigo)) !== null) {
                $sugerencias['unidad_id'] = ['valor' => $unidad, 'razon' => 'Unidad declarada en el archivo'];
            }
            if (($producto = $contexto->homologar($fila['concepto_fuente'] === null ? null : (string) $fila['concepto_fuente'])) !== null) {
                $sugerencias['producto_id'] = ['valor' => $producto, 'razon' => 'Producto homologado'];
            }
            $venta = $this->ventaDeReferencia($alcance->centroId, $fila);
            if ($venta !== null) {
                $sugerencias['unidad_id'] ??= ['valor' => (int) $venta['unidad_id'], 'razon' => 'Venta con la misma prefactura o factura'];
                if ($venta['producto_id'] !== null) {
                    $sugerencias['producto_id'] ??= ['valor' => (int) $venta['producto_id'], 'razon' => 'Venta con la misma prefactura o factura'];
                }
            }
            $fila['sugerencias'] = $sugerencias;
        }
        return $filas;
    }

    /**
     * Asigna unidad y producto a uno o varios recaudos, con un evento de auditoría por recaudo.
     *
     * @param list<int> $ids
     */
    public function clasificar(Solicitud $solicitud, array $ids, ?int $unidad, ?int $producto): int
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $errores = [];
        if ($ids === []) {
            $errores['ids'] = 'Elige al menos un recaudo.';
        }
        if ($unidad === null || (int) $this->bd->valor("SELECT COUNT(*) FROM unidades_productivas WHERE id = :u AND centro_id = :c AND activa = 1 AND codigo <> 'SIN'", ['u' => $unidad, 'c' => $alcance->centroId]) === 0) {
            $errores['unidad_id'] = 'Elige la unidad a la que corresponde el recaudo.';
        }
        if ($producto !== null && (int) $this->bd->valor('SELECT COUNT(*) FROM productos WHERE id = :p AND centro_id = :c AND archivado_en IS NULL', ['p' => $producto, 'c' => $alcance->centroId]) === 0) {
            $errores['producto_id'] = 'Elige un producto del catálogo.';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        /** @var int $unidad */
        $this->autorizacion->exigir($solicitud, 'recaudos.clasificar', new RecursoUnidad($alcance->centroId, $unidad));
        $usuario = AutorizacionRecurso::usuario($solicitud);
        return $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $alcance, $ids, $unidad, $producto, $usuario): int {
            $hechos = 0;
            foreach (array_values(array_unique($ids)) as $id) {
                $actual = $bd->fila('SELECT id, unidad_id, producto_id, concepto_fuente, estado_clasificacion FROM recaudos WHERE id = :id AND centro_id = :c AND archivado_en IS NULL FOR UPDATE', ['id' => $id, 'c' => $alcance->centroId]);
                if ($actual === null) {
                    continue;
                }
                $nuevo = [
                    'unidad_id' => $unidad,
                    'producto_id' => $producto ?? ($actual['producto_id'] === null ? null : (int) $actual['producto_id']),
                    'estado_clasificacion' => $producto !== null || $actual['producto_id'] !== null || $actual['concepto_fuente'] !== null ? 'clasificado' : 'pendiente',
                ];
                $bd->ejecutar(
                    'UPDATE recaudos SET unidad_id = :u, producto_id = :p, estado_clasificacion = :e, version = version + 1, actualizado_por = :a WHERE id = :id',
                    ['u' => $nuevo['unidad_id'], 'p' => $nuevo['producto_id'], 'e' => $nuevo['estado_clasificacion'], 'a' => $usuario->id, 'id' => $id]
                );
                $this->auditoria->registrar($solicitud, 'recaudos.clasificar', 'exito', new RecursoAuditado('recaudos', $id, $unidad), [], ServicioCatalogos::extraer($actual, ['unidad_id', 'producto_id', 'estado_clasificacion']), $nuevo);
                $hechos++;
            }
            $this->versiones->incrementar($alcance->centroId);
            return $hechos;
        });
    }

    /**
     * Ventas directas candidatas para conciliar un recaudo: misma prefactura o factura, o la referencia de la transacción
     * igual al cupón. Solo por claves, nunca por nombre ni por fecha (RF-097).
     *
     * @return array{recaudo:array<string,mixed>,candidatas:list<array<string,mixed>>,vinculadas:list<array<string,mixed>>}
     */
    public function candidatas(Solicitud $solicitud, int $recaudoId): array
    {
        $recaudo = $this->recaudo($solicitud, $recaudoId);
        $claves = array_values(array_filter([$recaudo['prefactura'], $recaudo['factura_electronica']], static fn (mixed $v): bool => $v !== null && $v !== ''));
        $condiciones = [];
        $parametros = ['c' => $recaudo['centro_id'], 'r' => $recaudoId];
        foreach ($claves as $i => $clave) {
            $condiciones[] = "s.factura = :k{$i}";
            $parametros["k{$i}"] = $clave;
        }
        if ($recaudo['cupon_pago'] !== null) {
            $condiciones[] = 's.referencia_transaccion = :cupon';
            $parametros['cupon'] = $recaudo['cupon_pago'];
        }
        $candidatas = $condiciones === [] ? [] : $this->bd->consultar(
            "SELECT s.id, s.fecha, s.valor, s.factura, s.referencia_transaccion, s.unidad_id FROM salidas s
              WHERE s.centro_id = :c AND s.archivado_en IS NULL AND s.canal = 'VENTA_DIRECTA' AND (" . implode(' OR ', $condiciones) . ')
                AND NOT EXISTS (SELECT 1 FROM conciliaciones_recaudo x WHERE x.salida_id = s.id AND x.recaudo_id = :r AND x.archivado_en IS NULL)
              ORDER BY s.fecha LIMIT 50',
            $parametros
        );
        $vinculadas = $this->bd->consultar(
            'SELECT s.id, s.fecha, s.valor, s.factura, x.criterio FROM conciliaciones_recaudo x JOIN salidas s ON s.id = x.salida_id WHERE x.recaudo_id = :r AND x.archivado_en IS NULL',
            ['r' => $recaudoId]
        );
        return ['recaudo' => $recaudo, 'candidatas' => $candidatas, 'vinculadas' => $vinculadas];
    }

    /**
     * Concilia el recaudo con las ventas elegidas. Devuelve el estado resultante.
     *
     * @param list<int> $salidas
     */
    public function conciliar(Solicitud $solicitud, int $recaudoId, array $salidas, string $criterio): string
    {
        $recaudo = $this->recaudo($solicitud, $recaudoId);
        $this->autorizacion->exigir($solicitud, 'recaudos.conciliar', $recaudo['unidad_id'] === null ? null : new RecursoUnidad((int) $recaudo['centro_id'], (int) $recaudo['unidad_id']));
        if ($salidas === []) {
            throw new ErrorValidacion(['salidas' => 'Elige al menos una venta para conciliar.']);
        }
        if (!in_array($criterio, ['prefactura', 'factura', 'referencia', 'manual'], true)) {
            throw new ErrorValidacion(['criterio' => 'Elige el criterio de la conciliación.']);
        }
        $usuario = AutorizacionRecurso::usuario($solicitud);
        return $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $recaudo, $salidas, $criterio, $usuario): string {
            $marcadores = implode(',', array_map('intval', array_values(array_unique($salidas))));
            $validas = $bd->consultar("SELECT id, valor FROM salidas WHERE id IN ({$marcadores}) AND centro_id = :c AND archivado_en IS NULL AND canal = 'VENTA_DIRECTA'", ['c' => $recaudo['centro_id']]);
            if (count($validas) !== count(array_unique($salidas))) {
                throw new ErrorValidacion(['salidas' => 'Solo se concilian ventas directas activas del centro.']);
            }
            $previas = $bd->consultar('SELECT s.valor FROM conciliaciones_recaudo x JOIN salidas s ON s.id = x.salida_id WHERE x.recaudo_id = :r AND x.archivado_en IS NULL', ['r' => $recaudo['id']]);
            $suma = Entrada::sumar('0', ...array_map(static fn (array $f): string => (string) $f['valor'], array_merge($validas, $previas)));
            $diferencia = Entrada::desdeUnidades(Entrada::aUnidades((string) $recaudo['valor'], 2) - Entrada::aUnidades($suma, 2), 2);
            foreach ($validas as $venta) {
                $bd->ejecutar(
                    'INSERT INTO conciliaciones_recaudo (centro_id, recaudo_id, salida_id, criterio, diferencia, creado_por) VALUES (:c, :r, :s, :k, :d, :u)
                     ON DUPLICATE KEY UPDATE archivado_en = NULL, criterio = VALUES(criterio), diferencia = VALUES(diferencia)',
                    ['c' => $recaudo['centro_id'], 'r' => $recaudo['id'], 's' => $venta['id'], 'k' => $criterio, 'd' => $diferencia, 'u' => $usuario->id]
                );
            }
            $bd->ejecutar('UPDATE conciliaciones_recaudo SET diferencia = :d WHERE recaudo_id = :r AND archivado_en IS NULL', ['d' => $diferencia, 'r' => $recaudo['id']]);
            $estado = Entrada::comparar(ltrim($diferencia, '-'), '0.00') === 0 ? 'conciliado' : 'con_diferencia';
            $bd->ejecutar('UPDATE recaudos SET estado_conciliacion = :e, version = version + 1, actualizado_por = :u WHERE id = :id', ['e' => $estado, 'u' => $usuario->id, 'id' => $recaudo['id']]);
            $this->auditoria->registrar(
                $solicitud,
                'recaudos.conciliar',
                'exito',
                new RecursoAuditado('recaudos', (int) $recaudo['id'], $recaudo['unidad_id'] === null ? null : (int) $recaudo['unidad_id']),
                ['salidas' => array_map(static fn (array $v): int => (int) $v['id'], $validas), 'criterio' => $criterio],
                ['estado_conciliacion' => $recaudo['estado_conciliacion']],
                ['estado_conciliacion' => $estado, 'diferencia' => $diferencia]
            );
            return $estado;
        });
    }

    /**
     * Informe de diferencias del periodo (RF-032): recaudos conciliados con un valor distinto al de sus ventas.
     *
     * @return list<array<string,mixed>>
     */
    public function diferencias(Solicitud $solicitud, int $anio, int $mes): array
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        [$condicion, $parametros] = $alcance->condicionSql('rc');
        return $this->bd->consultar(
            "SELECT rc.id, rc.fecha_pago, rc.valor, rc.cupon_pago, rc.prefactura, rc.unidad_id, MAX(x.diferencia) AS diferencia, COUNT(x.id) AS ventas,
                    SUM(s.valor) AS valor_ventas
               FROM recaudos rc JOIN conciliaciones_recaudo x ON x.recaudo_id = rc.id AND x.archivado_en IS NULL JOIN salidas s ON s.id = x.salida_id
              WHERE {$condicion} AND rc.archivado_en IS NULL AND rc.estado_conciliacion = 'con_diferencia' AND rc.fecha_pago >= :desde AND rc.fecha_pago < :hasta
              GROUP BY rc.id, rc.fecha_pago, rc.valor, rc.cupon_pago, rc.prefactura, rc.unidad_id ORDER BY rc.fecha_pago",
            $parametros + ['desde' => sprintf('%04d-%02d-01', $anio, $mes), 'hasta' => (new \DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes)))->modify('+1 month')->format('Y-m-d')]
        );
    }

    /** @return array<string,mixed> */
    private function recaudo(Solicitud $solicitud, int $id): array
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $fila = $this->bd->fila('SELECT * FROM recaudos WHERE id = :id AND centro_id = :c AND archivado_en IS NULL', ['id' => $id, 'c' => $alcance->centroId]);
        if ($fila === null || !$alcance->permiteUnidad($fila['unidad_id'] === null ? null : (int) $fila['unidad_id'])) {
            throw new ErrorHttp(404);
        }
        unset($fila['identificacion_hmac']);
        return $fila;
    }

    /**
     * @param array<string,mixed> $recaudo
     * @return array<string,mixed>|null
     */
    private function ventaDeReferencia(int $centro, array $recaudo): ?array
    {
        foreach ([$recaudo['prefactura'], $recaudo['factura_electronica']] as $clave) {
            if ($clave === null || $clave === '') {
                continue;
            }
            $venta = $this->bd->fila("SELECT unidad_id, producto_id FROM salidas WHERE centro_id = :c AND factura = :f AND archivado_en IS NULL AND canal = 'VENTA_DIRECTA' LIMIT 1", ['c' => $centro, 'f' => $clave]);
            if ($venta !== null) {
                return $venta;
            }
        }
        return null;
    }
}
