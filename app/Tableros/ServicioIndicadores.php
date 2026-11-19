<?php

declare(strict_types=1);

namespace App\Tableros;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use App\Politicas\AlcanceDatos;
use App\Servicios\ReglasHato;
use App\Servicios\ServicioParametros;

/**
 * Cálculo de los indicadores (RF-063 a RF-078) con las fórmulas del prototipo (js/metrics.js), que son la referencia
 * funcional (2.10). Cada consulta lleva la condición de alcance de AlcanceDatos: un líder de unidad nunca suma datos
 * de otra unidad (RF-072, Obs. 4). Los importes se suman en SQL sobre DECIMAL y se operan como enteros de centavos:
 * no hay floats en los valores monetarios, solo en los porcentajes.
 *
 * Reglas que se conservan (2.10):
 *   - El presupuesto es anual: no se prorratea al filtrar por fechas.
 *   - Venta directa = recaudos «Producción»; autoconsumo = salidas por autoconsumo. Las ventas por transferencia
 *     (salidas de venta directa) solo se muestran para conciliar y no se suman.
 *   - Los recaudos sin unidad inequívoca no se reparten entre unidades.
 *   - Nunca se suman cantidades con medidas distintas.
 *   - Una división por cero da null, que la vista muestra como «—».
 */
final class ServicioIndicadores
{
    /** Código SGAC de la leche cruda (RF-077). */
    public const LECHE = '5013170101';

    public function __construct(private readonly BaseDatos $bd, private readonly ServicioParametros $parametros)
    {
    }

    // ---------------------------------------------------------------- Tablero central (IND_001, IND_002)

    /** @return array<string,mixed> */
    public function central(AlcanceDatos $alcance, FiltrosTablero $f): array
    {
        $unidades = $this->unidadesDelAlcance($alcance, $f->unidadId);
        $porUnidad = [];
        foreach ($unidades as $u) {
            $porUnidad[(int) $u['id']] = ['id' => (int) $u['id'], 'codigo' => (string) $u['codigo'], 'nombre' => (string) $u['nombre'],
                'asignacion' => '0.00', 'mantenimiento' => '0.00', 'alimentacion' => '0.00', 'materiales_formacion' => '0.00',
                'venta_directa' => '0.00', 'autoconsumo' => '0.00', 'recaudos' => 0, 'salidas' => 0, 'asignaciones' => 0];
        }

        // Asignación: anual, sin prorrateo (RF-065).
        [$condicion, $p] = $this->alcance($alcance, 'a', $f->unidadId);
        foreach ($this->bd->consultar(
            "SELECT a.unidad_id, COUNT(*) AS n, SUM(a.valor_total) AS total, SUM(a.mantenimiento) AS mantenimiento,
                    SUM(a.alimentacion) AS alimentacion, SUM(a.materiales_formacion) AS materiales
               FROM asignaciones_presupuestales a
              WHERE {$condicion} AND a.archivado_en IS NULL AND a.vigencia = :vigencia
              GROUP BY a.unidad_id",
            $p + ['vigencia' => $f->vigencia]
        ) as $fila) {
            $id = (int) $fila['unidad_id'];
            if (!isset($porUnidad[$id])) {
                continue;
            }
            $porUnidad[$id] = ['asignacion' => (string) $fila['total'], 'mantenimiento' => (string) $fila['mantenimiento'],
                'alimentacion' => (string) $fila['alimentacion'], 'materiales_formacion' => (string) $fila['materiales'],
                'asignaciones' => (int) $fila['n']] + $porUnidad[$id];
        }

        // Venta directa: recaudos de tipo «Producción» del periodo. Con el canal Autoconsumo es 0.
        $sinUnidad = ['recaudos' => 0, 'valor' => '0.00'];
        if ($f->canal !== 'autoconsumo') {
            [$condicion, $p] = $this->alcance($alcance, 'r', $f->unidadId);
            [$producto, $pp] = $this->filtroProducto('r', 'concepto_fuente', $f);
            foreach ($this->bd->consultar(
                "SELECT r.unidad_id, COUNT(*) AS n, SUM(r.valor) AS total FROM recaudos r
                  WHERE {$condicion} AND r.archivado_en IS NULL AND r.tipo_ingreso = 'Producción'
                    AND r.fecha_pago BETWEEN :inicio AND :fin {$producto}
                  GROUP BY r.unidad_id",
                $p + $pp + ['inicio' => $f->inicio(), 'fin' => $f->fin()]
            ) as $fila) {
                if ($fila['unidad_id'] === null) {
                    $sinUnidad = ['recaudos' => (int) $fila['n'], 'valor' => (string) $fila['total']];
                    continue;
                }
                $id = (int) $fila['unidad_id'];
                if (isset($porUnidad[$id])) {
                    $porUnidad[$id]['venta_directa'] = (string) $fila['total'];
                    $porUnidad[$id]['recaudos'] = (int) $fila['n'];
                }
            }
        }

        // Autoconsumo: salidas por autoconsumo del periodo. Con el canal Venta directa es 0.
        $sinProceso = ['salidas' => 0, 'valor' => '0.00'];
        if ($f->canal !== 'venta_directa') {
            [$condicion, $p] = $this->alcance($alcance, 's', $f->unidadId);
            [$producto, $pp] = $this->filtroProducto('s', 'nombre_producto_fuente', $f);
            foreach ($this->bd->consultar(
                "SELECT s.unidad_id, COUNT(*) AS n, SUM(s.valor) AS total,
                        SUM(s.proceso_id IS NULL) AS sin_proceso, SUM(IF(s.proceso_id IS NULL, s.valor, 0)) AS valor_sin_proceso
                   FROM salidas s
                  WHERE {$condicion} AND s.archivado_en IS NULL AND s.canal = 'AUTOCONSUMO'
                    AND s.fecha BETWEEN :inicio AND :fin {$producto}
                  GROUP BY s.unidad_id",
                $p + $pp + ['inicio' => $f->inicio(), 'fin' => $f->fin()]
            ) as $fila) {
                $id = (int) $fila['unidad_id'];
                if (isset($porUnidad[$id])) {
                    $porUnidad[$id]['autoconsumo'] = (string) $fila['total'];
                    $porUnidad[$id]['salidas'] = (int) $fila['n'];
                }
                $sinProceso = ['salidas' => $sinProceso['salidas'] + (int) $fila['sin_proceso'],
                    'valor' => Entrada::sumarCon(2, $sinProceso['valor'], (string) $fila['valor_sin_proceso'])];
            }
        }

        $kpi = ['asignacion' => '0.00', 'asignaciones' => 0, 'venta_directa' => $sinUnidad['valor'], 'recaudos' => $sinUnidad['recaudos'], 'autoconsumo' => '0.00', 'salidas' => 0];
        $filas = [];
        foreach ($porUnidad as $u) {
            $u['total'] = Entrada::sumarCon(2, $u['venta_directa'], $u['autoconsumo']);
            $u['cobertura'] = self::porcentaje($u['total'], $u['asignacion']);
            $u['participacion_venta'] = self::porcentaje($u['venta_directa'], $u['total']);
            $u['participacion_autoconsumo'] = self::porcentaje($u['autoconsumo'], $u['total']);
            $kpi['asignacion'] = Entrada::sumarCon(2, $kpi['asignacion'], $u['asignacion']);
            $kpi['asignaciones'] += $u['asignaciones'];
            $kpi['venta_directa'] = Entrada::sumarCon(2, $kpi['venta_directa'], $u['venta_directa']);
            $kpi['recaudos'] += $u['recaudos'];
            $kpi['autoconsumo'] = Entrada::sumarCon(2, $kpi['autoconsumo'], $u['autoconsumo']);
            $kpi['salidas'] += $u['salidas'];
            // Solo las unidades con asignación o con movimientos aparecen en el gráfico.
            if (self::distintoDeCero($u['asignacion']) || self::distintoDeCero($u['total'])) {
                $filas[] = $u;
            }
        }
        $kpi['total'] = Entrada::sumarCon(2, $kpi['venta_directa'], $kpi['autoconsumo']);
        $kpi['cobertura'] = self::porcentaje($kpi['total'], $kpi['asignacion']);
        $kpi['saldo'] = self::restar($kpi['asignacion'], $kpi['total']);

        return [
            'periodo' => ['inicio' => $f->inicio(), 'fin' => $f->fin()],
            'kpi' => $kpi,
            'unidades' => $filas,
            'sin_unidad' => $sinUnidad,
            'sin_proceso' => $sinProceso,
            'conciliacion' => $this->conciliacion($alcance, $f),
            'procesos' => $this->procesos($alcance, $f),
        ];
    }

    /**
     * Aviso de conciliación (RF-070): ventas por transferencia registradas y recaudo externo del periodo. Son fuentes
     * con coberturas distintas: se muestran, no se suman.
     *
     * @return array<string,mixed>
     */
    private function conciliacion(AlcanceDatos $alcance, FiltrosTablero $f): array
    {
        [$condicion, $p] = $this->alcance($alcance, 's', $f->unidadId);
        $ventas = $this->bd->fila(
            "SELECT COUNT(*) AS n, COALESCE(SUM(s.valor), 0) AS total FROM salidas s
              WHERE {$condicion} AND s.archivado_en IS NULL AND s.canal = 'VENTA_DIRECTA' AND s.fecha BETWEEN :inicio AND :fin",
            $p + ['inicio' => $f->inicio(), 'fin' => $f->fin()]
        ) ?? ['n' => 0, 'total' => '0.00'];
        [$condicion, $p] = $this->alcance($alcance, 'r', $f->unidadId);
        $recaudos = $this->bd->fila(
            "SELECT COUNT(*) AS n, COALESCE(SUM(r.valor), 0) AS total FROM recaudos r
              WHERE {$condicion} AND r.archivado_en IS NULL AND r.fecha_pago BETWEEN :inicio AND :fin",
            $p + ['inicio' => $f->inicio(), 'fin' => $f->fin()]
        ) ?? ['n' => 0, 'total' => '0.00'];
        return ['transferencias' => (string) $ventas['total'], 'transferencias_n' => (int) $ventas['n'],
            'recaudo_externo' => (string) $recaudos['total'], 'recaudo_externo_n' => (int) $recaudos['n']];
    }

    /**
     * IND_002 (RF-067, RF-068): ejecución de cada proceso de autoconsumo. Las entregas son las salidas por autoconsumo
     * del proceso, con su unidad de origen y su medida, dentro del periodo; las de otra medida no suman y se cuentan
     * aparte. Las entregas sin proceso no cuentan en ninguna meta.
     *
     * @return list<array<string,mixed>>
     */
    public function procesos(AlcanceDatos $alcance, FiltrosTablero $f): array
    {
        [$condicion, $p] = $this->alcance($alcance, 'p', $f->unidadId);
        $producto = '';
        if ($f->productoId !== null) {
            $producto = 'AND p.producto_id = :producto';
            $p['producto'] = $f->productoId;
        }
        $procesos = $this->bd->consultar(
            "SELECT p.id, p.codigo, p.unidad_id, u.codigo AS unidad_codigo, u.nombre AS unidad_nombre,
                    COALESCE(d.nombre, p.destino_texto) AS destino, COALESCE(pr.nombre, p.producto_texto) AS producto,
                    p.cupo_cantidad, p.medida, p.cupo_valor
               FROM procesos_autoconsumo p
               JOIN unidades_productivas u ON u.id = p.unidad_id
               LEFT JOIN destinos_internos d ON d.id = p.destino_id
               LEFT JOIN productos pr ON pr.id = p.producto_id
              WHERE {$condicion} AND p.archivado_en IS NULL AND p.vigencia = :vigencia {$producto}
              ORDER BY p.codigo",
            $p + ['vigencia' => $f->vigencia]
        );
        if ($procesos === []) {
            return [];
        }
        $entregas = [];
        if ($f->canal !== 'venta_directa') {
            $ids = array_map(static fn (array $x): int => (int) $x['id'], $procesos);
            $marcadores = [];
            $pe = ['inicio' => $f->inicio(), 'fin' => $f->fin()];
            foreach ($ids as $i => $id) {
                $marcadores[] = ":proceso_{$i}";
                $pe["proceso_{$i}"] = $id;
            }
            foreach ($this->bd->consultar(
                'SELECT s.proceso_id, s.unidad_id, s.medida, COUNT(*) AS n, SUM(s.cantidad) AS cantidad, SUM(s.valor) AS valor,
                        COUNT(DISTINCT s.recepcion_id) AS lotes
                   FROM salidas s
                  WHERE s.proceso_id IN (' . implode(', ', $marcadores) . ") AND s.archivado_en IS NULL AND s.canal = 'AUTOCONSUMO'
                    AND s.fecha BETWEEN :inicio AND :fin
                  GROUP BY s.proceso_id, s.unidad_id, s.medida",
                $pe
            ) as $fila) {
                $entregas[(int) $fila['proceso_id']][] = $fila;
            }
        }
        $resultado = [];
        foreach ($procesos as $proceso) {
            $entregado = '0.000';
            $valor = '0.00';
            $lotes = 0;
            $n = 0;
            $incompatibles = 0;
            foreach ($entregas[(int) $proceso['id']] ?? [] as $e) {
                if ((int) $e['unidad_id'] !== (int) $proceso['unidad_id'] || (string) $e['medida'] !== (string) $proceso['medida']) {
                    $incompatibles += (int) $e['n'];
                    continue;
                }
                $entregado = Entrada::sumarCon(3, $entregado, (string) $e['cantidad']);
                $valor = Entrada::sumarCon(2, $valor, (string) $e['valor']);
                $lotes += (int) $e['lotes'];
                $n += (int) $e['n'];
            }
            $resultado[] = [
                'id' => (int) $proceso['id'], 'codigo' => (string) $proceso['codigo'], 'unidad_codigo' => (string) $proceso['unidad_codigo'],
                'unidad_nombre' => (string) $proceso['unidad_nombre'], 'destino' => (string) $proceso['destino'], 'producto' => (string) $proceso['producto'],
                'medida' => (string) $proceso['medida'], 'cupo_cantidad' => (string) $proceso['cupo_cantidad'], 'cupo_valor' => (string) $proceso['cupo_valor'],
                'entregado' => $entregado, 'valor' => $valor, 'entregas' => $n, 'lotes' => $lotes, 'incompatibles' => $incompatibles,
                'saldo_cantidad' => self::restar($proceso['cupo_cantidad'], $entregado, 3),
                'saldo_valor' => self::restar($proceso['cupo_valor'], $valor),
                'avance_fisico' => self::porcentaje($entregado, (string) $proceso['cupo_cantidad'], 3),
                'avance_monetario' => self::porcentaje($valor, (string) $proceso['cupo_valor']),
            ];
        }
        return $resultado;
    }

    /**
     * Estado de la información (RF-069): cobertura de las fuentes, independiente de los filtros de fecha.
     *
     * @return array<string,mixed>
     */
    public function estadoInformacion(AlcanceDatos $alcance): array
    {
        [$condicion, $p] = $alcance->condicionSql('r');
        $recaudos = $this->bd->fila(
            "SELECT MIN(r.fecha_pago) AS desde, MAX(r.fecha_pago) AS hasta, COUNT(*) AS n, SUM(r.unidad_id IS NULL) AS sin_unidad
               FROM recaudos r WHERE {$condicion} AND r.archivado_en IS NULL",
            $p
        ) ?? [];
        [$condicion, $p] = $alcance->condicionSql('s');
        $salidas = $this->bd->fila(
            "SELECT MIN(s.fecha) AS desde, MAX(s.fecha) AS hasta, COUNT(*) AS n, SUM(s.recepcion_id IS NULL) AS sin_recepcion,
                    SUM(s.canal = 'AUTOCONSUMO' AND s.proceso_id IS NULL) AS sin_proceso
               FROM salidas s WHERE {$condicion} AND s.archivado_en IS NULL",
            $p
        ) ?? [];
        [$condicion, $p] = $alcance->condicionSql('c');
        $corte = $this->bd->valor("SELECT MAX(c.fecha_corte) FROM cortes_hato c WHERE {$condicion} AND c.vigente = 1", $p);
        return [
            'recaudos_desde' => $recaudos['desde'] ?? null, 'recaudos_hasta' => $recaudos['hasta'] ?? null, 'recaudos' => (int) ($recaudos['n'] ?? 0),
            'recaudos_sin_unidad' => (int) ($recaudos['sin_unidad'] ?? 0),
            'salidas_desde' => $salidas['desde'] ?? null, 'salidas_hasta' => $salidas['hasta'] ?? null, 'salidas' => (int) ($salidas['n'] ?? 0),
            'salidas_sin_recepcion' => (int) ($salidas['sin_recepcion'] ?? 0), 'entregas_sin_proceso' => (int) ($salidas['sin_proceso'] ?? 0),
            'corte_hato' => $corte === null ? null : (string) $corte,
        ];
    }

    /**
     * Opciones del filtro de producto: productos del catálogo con movimientos en el alcance y la vigencia, y los
     * conceptos fuente sin homologar («Queso» no se homologa solo, 2.10).
     *
     * @return array<string,string>
     */
    public function opcionesProducto(AlcanceDatos $alcance, int $vigencia): array
    {
        $ids = [];
        $conceptos = [];
        foreach ([['recaudos', 'r', 'fecha_pago', 'concepto_fuente'], ['salidas', 's', 'fecha', 'nombre_producto_fuente']] as [$tabla, $alias, $fecha, $texto]) {
            [$condicion, $p] = $alcance->condicionSql($alias);
            $p += ['inicio' => sprintf('%04d-01-01', $vigencia), 'fin' => sprintf('%04d-12-31', $vigencia)];
            foreach ($this->bd->consultar(
                "SELECT DISTINCT {$alias}.producto_id AS id, IF({$alias}.producto_id IS NULL, {$alias}.{$texto}, NULL) AS concepto
                   FROM {$tabla} {$alias}
                  WHERE {$condicion} AND {$alias}.archivado_en IS NULL AND {$alias}.{$fecha} BETWEEN :inicio AND :fin LIMIT 500",
                $p
            ) as $fila) {
                if ($fila['id'] !== null) {
                    $ids[(int) $fila['id']] = true;
                } elseif ($fila['concepto'] !== null && trim((string) $fila['concepto']) !== '') {
                    $conceptos[(string) $fila['concepto']] = true;
                }
            }
        }
        $opciones = [];
        if ($ids !== []) {
            $marcadores = [];
            $pp = [];
            foreach (array_keys($ids) as $i => $id) {
                $marcadores[] = ":p{$i}";
                $pp["p{$i}"] = $id;
            }
            foreach ($this->bd->consultar('SELECT id, nombre FROM productos WHERE id IN (' . implode(', ', $marcadores) . ') ORDER BY nombre', $pp) as $fila) {
                $opciones['p' . $fila['id']] = (string) $fila['nombre'];
            }
        }
        ksort($conceptos, SORT_NATURAL | SORT_FLAG_CASE);
        foreach (array_keys($conceptos) as $concepto) {
            $opciones['c:' . $concepto] = $concepto . ' (concepto fuente)';
        }
        return $opciones;
    }

    /**
     * Unidades del alcance para el filtro y el gráfico: activas y distintas de «SIN».
     *
     * @return list<array<string,mixed>>
     */
    public function unidadesDelAlcance(AlcanceDatos $alcance, ?int $soloUnidad = null): array
    {
        if ($alcance->estaVacio()) {
            return [];
        }
        $p = ['centro' => $alcance->centroId];
        $condicion = '';
        if (!$alcance->todoElCentro) {
            $marcadores = [];
            foreach ($alcance->unidades() as $i => $u) {
                $marcadores[] = ":u{$i}";
                $p["u{$i}"] = $u;
            }
            $condicion = ' AND id IN (' . implode(', ', $marcadores) . ')';
        }
        if ($soloUnidad !== null) {
            $condicion .= ' AND id = :solo';
            $p['solo'] = $soloUnidad;
        }
        return $this->bd->consultar(
            "SELECT id, codigo, nombre, tipo FROM unidades_productivas WHERE centro_id = :centro AND activa = 1 AND codigo <> 'SIN'{$condicion} ORDER BY id",
            $p
        );
    }

    // ---------------------------------------------------------------- Tablero técnico de unidad (RF-073 a RF-078)

    /**
     * IND_003 y panel de salud reproductiva del corte vigente (RF-073, RF-074, RF-078). El corte es independiente
     * del filtro de fechas: el hato es una fotografía fechada y no se reconstruye su historia (2.10).
     *
     * @return array<string,mixed>
     */
    public function hato(AlcanceDatos $alcance, int $unidadId, bool $incluirPractica, ?string $fechaParametros = null): array
    {
        $meta = $this->parametro($alcance->centroId, 'meta_vacas_ordeno', $fechaParametros);
        $vacio = ['corte' => null, 'inventario' => 0, 'produccion' => 0, 'horra' => 0, 'practica' => 0, 'poblacion' => 0,
            'prenadas' => 0, 'tasa' => null, 'tasa_completa' => null, 'prenez' => null, 'disponibilidad' => null, 'meta' => $meta,
            'semaforo' => self::semaforoMeta(null, $meta), 'animales' => [], 'incluir_practica' => $incluirPractica];
        if (!$alcance->permiteUnidad($unidadId)) {
            return $vacio;
        }
        $corte = $this->bd->fila(
            'SELECT id, fecha_corte FROM cortes_hato WHERE centro_id = :c AND unidad_id = :u AND vigente = 1 ORDER BY fecha_corte DESC LIMIT 1',
            ['c' => $alcance->centroId, 'u' => $unidadId]
        );
        if ($corte === null) {
            return $vacio;
        }
        $filas = $this->bd->consultar(
            'SELECT a.id, a.identificador, r.lote_hato, r.estado_reproductivo, r.ultimo_parto, r.iep_dias, r.dias_abiertos
               FROM registros_reproductivos r JOIN animales a ON a.id = r.animal_id
              WHERE r.corte_id = :corte AND r.centro_id = :c AND r.unidad_id = :u AND a.archivado_en IS NULL AND a.activo = 1
              ORDER BY CAST(a.identificador AS UNSIGNED), a.identificador',
            ['corte' => (int) $corte['id'], 'c' => $alcance->centroId, 'u' => $unidadId]
        );
        $cuenta = ['PRODUCCION' => 0, 'HORRA' => 0, 'PRACTICA' => 0];
        $prenadas = 0;
        $animales = [];
        foreach ($filas as $fila) {
            $lote = (string) $fila['lote_hato'];
            $cuenta[$lote] = ($cuenta[$lote] ?? 0) + 1;
            $operativa = $incluirPractica || $lote !== 'PRACTICA';
            $prenada = self::esPrenada((string) ($fila['estado_reproductivo'] ?? ''));
            if ($operativa && $prenada) {
                $prenadas++;
            }
            $iep = $fila['iep_dias'] === null ? null : (int) $fila['iep_dias'];
            $abiertos = $fila['dias_abiertos'] === null ? null : (int) $fila['dias_abiertos'];
            $semIep = ReglasHato::semaforoIep($iep);
            $semAbiertos = ReglasHato::semaforoDiasAbiertos($abiertos);
            $animales[] = ['id' => (int) $fila['id'], 'identificador' => (string) $fila['identificador'], 'lote_hato' => $lote,
                'estado_reproductivo' => $fila['estado_reproductivo'], 'ultimo_parto' => $fila['ultimo_parto'], 'iep_dias' => $iep,
                'dias_abiertos' => $abiertos, 'semaforo_iep' => $semIep, 'semaforo_abiertos' => $semAbiertos,
                'operativa' => $operativa, 'prenada' => $prenada, 'alerta' => $semIep['tono'] === 'error' || $semAbiertos['tono'] === 'error'];
        }
        $inventario = count($filas);
        $poblacion = $incluirPractica ? $inventario : $inventario - $cuenta['PRACTICA'];
        $tasa = self::cociente($cuenta['PRODUCCION'], $poblacion);
        return [
            'corte' => (string) $corte['fecha_corte'], 'inventario' => $inventario, 'produccion' => $cuenta['PRODUCCION'],
            'horra' => $cuenta['HORRA'], 'practica' => $cuenta['PRACTICA'], 'poblacion' => $poblacion, 'prenadas' => $prenadas,
            'tasa' => $tasa, 'tasa_completa' => self::cociente($cuenta['PRODUCCION'], $inventario),
            'prenez' => self::cociente($prenadas, $poblacion), 'disponibilidad' => self::cociente($cuenta['PRACTICA'], $inventario),
            'meta' => $meta, 'semaforo' => self::semaforoMeta($tasa, $meta), 'animales' => $animales, 'incluir_practica' => $incluirPractica,
        ];
    }

    /**
     * IND_08 (RF-075): eficiencia de transformación láctea de la leche que salió de la unidad. Solo cuentan las
     * transformaciones con vínculos verificables (salida de leche por autoconsumo en L y lote del derivado vigentes)
     * y factor mayor que 0. El global compara litros equivalentes con litros asignados; no mezcla kg con L.
     *
     * @return array<string,mixed>
     */
    public function transformacion(AlcanceDatos $alcance, int $unidadId, FiltrosTablero $f): array
    {
        $meta = $this->parametro($alcance->centroId, 'meta_aprovechamiento', $f->fin());
        if (!$alcance->permiteUnidad($unidadId)) {
            return ['lotes' => [], 'derivados' => [], 'global' => null, 'meta' => $meta, 'litros' => '0.000', 'equivalentes' => '0.000'];
        }
        $filas = $this->bd->consultar(
            "SELECT t.id, t.fecha, t.litros_asignados, r.numero_interno, r.cantidad, r.medida, p.id AS producto_id, p.nombre AS producto,
                    p.factor_leche, p.meta_rendimiento_min, p.meta_rendimiento_max
               FROM transformaciones t
               JOIN salidas s ON s.id = t.salida_leche_id AND s.archivado_en IS NULL AND s.canal = 'AUTOCONSUMO' AND s.medida = 'L'
               JOIN recepciones_lote r ON r.id = t.recepcion_derivado_id AND r.archivado_en IS NULL
               JOIN productos p ON p.id = r.producto_id
              WHERE t.centro_id = :c AND t.unidad_id = :u AND t.archivado_en IS NULL AND t.fecha BETWEEN :inicio AND :fin
                AND p.factor_leche > 0 AND t.litros_asignados > 0 AND r.cantidad > 0
              ORDER BY t.fecha, t.id",
            ['c' => $alcance->centroId, 'u' => $unidadId, 'inicio' => $f->inicio(), 'fin' => $f->fin()]
        );
        $lotes = [];
        $derivados = [];
        $litros = 0;
        $equivalentes = 0;
        foreach ($filas as $fila) {
            $l = Entrada::aUnidades((string) $fila['litros_asignados'], 3);
            $cantidad = Entrada::aUnidades((string) $fila['cantidad'], 3);
            $eq = (int) round($cantidad * (float) $fila['factor_leche']);
            $litros += $l;
            $equivalentes += $eq;
            $lotes[] = ['fecha' => (string) $fila['fecha'], 'lote' => (string) $fila['numero_interno'], 'producto' => (string) $fila['producto'],
                'litros' => (string) $fila['litros_asignados'], 'cantidad' => (string) $fila['cantidad'], 'medida' => (string) $fila['medida'],
                'rendimiento' => $l / $cantidad, 'aprovechamiento' => $eq / $l * 100];
            $id = (int) $fila['producto_id'];
            $derivados[$id] ??= ['id' => $id, 'producto' => (string) $fila['producto'], 'medida' => (string) $fila['medida'], 'litros' => 0, 'cantidad' => 0,
                'lotes' => 0, 'meta_min' => $fila['meta_rendimiento_min'] === null ? null : (float) $fila['meta_rendimiento_min'],
                'meta_max' => $fila['meta_rendimiento_max'] === null ? null : (float) $fila['meta_rendimiento_max']];
            $derivados[$id]['litros'] += $l;
            $derivados[$id]['cantidad'] += $cantidad;
            $derivados[$id]['lotes']++;
        }
        foreach ($derivados as &$d) {
            $d['rendimiento'] = $d['litros'] / $d['cantidad'];
            $d['en_meta'] = $d['meta_max'] === null ? null : $d['rendimiento'] <= $d['meta_max'] + 1e-9;
            $d['litros'] = Entrada::desdeUnidades($d['litros'], 3);
            $d['cantidad'] = Entrada::desdeUnidades($d['cantidad'], 3);
        }
        unset($d);
        $global = $litros > 0 ? $equivalentes / $litros * 100 : null;
        return ['lotes' => $lotes, 'derivados' => array_values($derivados), 'global' => $global === null ? null : round($global, 4), 'meta' => $meta,
            'litros' => Entrada::desdeUnidades($litros, 3), 'equivalentes' => Entrada::desdeUnidades($equivalentes, 3)];
    }

    /**
     * IND_12 (RF-076): rotación de los lotes recibidos en el periodo. El cierre se busca con todas las salidas del lote
     * y producto hasta la fecha de corte (la menor entre «hasta» y hoy), también las anteriores al filtro. Los lotes
     * abiertos muestran su antigüedad y no entran en el promedio.
     *
     * @return array<string,mixed>
     */
    public function rotacion(AlcanceDatos $alcance, int $unidadId, FiltrosTablero $f, ?string $hoy = null): array
    {
        $hoy ??= (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m-d');
        $corte = min($f->fin(), $hoy);
        $frescos = (int) ($this->parametro($alcance->centroId, 'rotacion_frescos', $corte) ?? 15);
        $animales = (int) ($this->parametro($alcance->centroId, 'rotacion_animales', $corte) ?? 30);
        if (!$alcance->permiteUnidad($unidadId)) {
            return ['lotes' => [], 'promedio' => null, 'cerrados' => 0, 'abiertos' => 0, 'corte' => $corte, 'meta_frescos' => $frescos, 'meta_animales' => $animales];
        }
        $p = ['c' => $alcance->centroId, 'u' => $unidadId, 'inicio' => $f->inicio(), 'fin' => $f->fin()];
        $producto = '';
        if ($f->productoId !== null) {
            $producto = 'AND r.producto_id = :producto';
            $p['producto'] = $f->productoId;
        }
        $recepciones = $this->bd->consultar(
            "SELECT r.id, r.numero_interno, r.fecha_recepcion, r.producto_id, r.cantidad, r.medida, p.nombre AS producto, p.categoria_rotacion
               FROM recepciones_lote r JOIN productos p ON p.id = r.producto_id
              WHERE r.centro_id = :c AND r.unidad_id = :u AND r.archivado_en IS NULL AND r.fecha_recepcion BETWEEN :inicio AND :fin {$producto}
              ORDER BY r.fecha_recepcion, r.id
              LIMIT 1000",
            $p
        );
        $lotes = [];
        $sumaDias = 0;
        $cerrados = 0;
        foreach ($recepciones as $r) {
            $salidas = $this->bd->consultar(
                'SELECT fecha, cantidad FROM salidas
                  WHERE recepcion_id = :r AND producto_id = :p AND medida = :m AND archivado_en IS NULL AND fecha <= :corte
                  ORDER BY fecha, id',
                ['r' => (int) $r['id'], 'p' => (int) $r['producto_id'], 'm' => (string) $r['medida'], 'corte' => $corte]
            );
            $total = Entrada::aUnidades((string) $r['cantidad'], 3);
            $vendido = 0;
            $cierre = null;
            foreach ($salidas as $s) {
                $vendido += Entrada::aUnidades((string) $s['cantidad'], 3);
                if ($cierre === null && $vendido >= $total) {
                    $cierre = (string) $s['fecha'];
                }
            }
            $dias = self::dias((string) $r['fecha_recepcion'], $cierre ?? $corte);
            $meta = $r['categoria_rotacion'] === 'animal' ? $animales : $frescos;
            if ($cierre !== null && $dias !== null) {
                $sumaDias += $dias;
                $cerrados++;
            }
            $lotes[] = ['lote' => (string) $r['numero_interno'], 'producto' => (string) $r['producto'], 'fecha' => (string) $r['fecha_recepcion'],
                'cantidad' => (string) $r['cantidad'], 'medida' => (string) $r['medida'], 'vendido' => Entrada::desdeUnidades($vendido, 3),
                'saldo' => Entrada::desdeUnidades(max(0, $total - $vendido), 3), 'cierre' => $cierre, 'dias' => $dias, 'meta' => $meta,
                'en_meta' => $dias === null ? null : $dias <= $meta];
        }
        return ['lotes' => $lotes, 'promedio' => $cerrados > 0 ? round($sumaDias / $cerrados, 1) : null, 'cerrados' => $cerrados,
            'abiertos' => count($lotes) - $cerrados, 'corte' => $corte, 'meta_frescos' => $frescos, 'meta_animales' => $animales];
    }

    /**
     * IND_17 (RF-077): costo operativo por litro = (gasto ejecutado de las categorías de IND_17 − componente
     * académico) / litros de leche recibidos por la unidad en el periodo. Los cupos de autoconsumo no intervienen.
     *
     * @return array<string,mixed>
     */
    public function costoLitro(AlcanceDatos $alcance, int $unidadId, FiltrosTablero $f): array
    {
        $precio = $this->parametro($alcance->centroId, 'precio_referencia_leche', $f->fin());
        $maximo = $this->parametro($alcance->centroId, 'costo_maximo_precio', $f->fin());
        $meta = $precio === null || $maximo === null ? null : round($precio * $maximo / 100, 2);
        $base = ['litros' => '0.000', 'bruto' => '0.00', 'academico' => '0.00', 'operativo' => '0.00', 'gastos' => 0, 'costo' => null,
            'precio' => $precio, 'meta' => $meta, 'margen' => null, 'presupuestado' => null, 'en_meta' => null];
        if (!$alcance->permiteUnidad($unidadId)) {
            return $base;
        }
        $gasto = $this->bd->fila(
            'SELECT COUNT(*) AS n, COALESCE(SUM(g.valor), 0) AS bruto, COALESCE(SUM(g.valor_componente_academico), 0) AS academico
               FROM gastos_ejecutados g JOIN categorias_gasto c ON c.id = g.categoria_id AND c.incluye_ind17 = 1
              WHERE g.centro_id = :c AND g.unidad_id = :u AND g.archivado_en IS NULL AND g.fecha BETWEEN :inicio AND :fin',
            ['c' => $alcance->centroId, 'u' => $unidadId, 'inicio' => $f->inicio(), 'fin' => $f->fin()]
        ) ?? ['n' => 0, 'bruto' => '0.00', 'academico' => '0.00'];
        $litros = (string) ($this->bd->valor(
            "SELECT COALESCE(SUM(r.cantidad), 0) FROM recepciones_lote r JOIN productos p ON p.id = r.producto_id AND p.codigo_sgac = :leche
              WHERE r.centro_id = :c AND r.unidad_id = :u AND r.archivado_en IS NULL AND r.medida = 'L' AND r.fecha_recepcion BETWEEN :inicio AND :fin",
            ['leche' => self::LECHE, 'c' => $alcance->centroId, 'u' => $unidadId, 'inicio' => $f->inicio(), 'fin' => $f->fin()]
        ) ?? '0');
        $litrosU = Entrada::aUnidades($litros, 3);
        $operativo = self::restar((string) $gasto['bruto'], (string) $gasto['academico']);
        $costo = (int) $gasto['n'] > 0 && $litrosU > 0 ? round(Entrada::aUnidades($operativo, 2) / 100 / ($litrosU / 1000), 4) : null;
        $presupuestado = null;
        if ($f->vigenciaCompleta() && $litrosU > 0) {
            $asignado = $this->bd->valor(
                'SELECT SUM(mantenimiento + alimentacion) FROM asignaciones_presupuestales WHERE centro_id = :c AND unidad_id = :u AND vigencia = :v AND archivado_en IS NULL',
                ['c' => $alcance->centroId, 'u' => $unidadId, 'v' => $f->vigencia]
            );
            $presupuestado = $asignado === null ? null : round(Entrada::aUnidades((string) $asignado, 2) / 100 / ($litrosU / 1000), 4);
        }
        return ['litros' => Entrada::desdeUnidades($litrosU, 3), 'bruto' => (string) $gasto['bruto'], 'academico' => (string) $gasto['academico'],
            'operativo' => $operativo, 'gastos' => (int) $gasto['n'], 'costo' => $costo, 'precio' => $precio, 'meta' => $meta,
            'margen' => $costo === null || $precio === null ? null : round($precio - $costo, 4), 'presupuestado' => $presupuestado,
            'en_meta' => $costo === null || $meta === null ? null : $costo <= $meta + 1e-9] + $base;
    }

    // ---------------------------------------------------------------- Ayudas

    /**
     * Condición de alcance y, si se pide, de unidad. La unidad pedida ya se validó contra el alcance en los filtros;
     * igual se combina con la condición de alcance, que es la que manda.
     *
     * @return array{0:string,1:array<string,int|string>}
     */
    private function alcance(AlcanceDatos $alcance, string $alias, ?int $unidadId): array
    {
        [$condicion, $p] = $alcance->condicionSql($alias);
        if ($unidadId !== null) {
            $condicion .= " AND {$alias}.unidad_id = :filtro_unidad";
            $p['filtro_unidad'] = $unidadId;
        }
        return [$condicion, $p];
    }

    /** @return array{0:string,1:array<string,int|string>} */
    private function filtroProducto(string $alias, string $campoTexto, FiltrosTablero $f): array
    {
        if ($f->productoId !== null) {
            return ["AND {$alias}.producto_id = :filtro_producto", ['filtro_producto' => $f->productoId]];
        }
        if ($f->concepto !== null) {
            return ["AND {$alias}.producto_id IS NULL AND {$alias}.{$campoTexto} = :filtro_concepto", ['filtro_concepto' => $f->concepto]];
        }
        return ['', []];
    }

    private function parametro(int $centroId, string $clave, ?string $fecha): ?float
    {
        $valor = $this->parametros->vigentes($centroId, $fecha)[$clave]['valor'] ?? null;
        return $valor === null ? null : (float) $valor;
    }

    /** Porcentaje a/b × 100 sobre decimales exactos; null si b es 0 (2.10: nada de infinitos). */
    public static function porcentaje(string $a, string $b, int $decimales = 2): ?float
    {
        $divisor = Entrada::aUnidades($b, $decimales);
        return $divisor === 0 ? null : round(Entrada::aUnidades($a, $decimales) / $divisor * 100, 4);
    }

    public static function cociente(int $a, int $b): ?float
    {
        return $b === 0 ? null : round($a / $b * 100, 4);
    }

    public static function restar(mixed $a, string $b, int $decimales = 2): string
    {
        return Entrada::desdeUnidades(Entrada::aUnidades((string) $a, $decimales) - Entrada::aUnidades($b, $decimales), $decimales);
    }

    /** @return array{tono:string,texto:string} */
    public static function semaforoMeta(?float $valor, ?float $meta): array
    {
        return match (true) {
            $valor === null || $meta === null => ['tono' => 'neutra', 'texto' => 'Sin dato'],
            $valor + 1e-9 >= $meta => ['tono' => 'exito', 'texto' => 'Cumple la meta'],
            default => ['tono' => 'advertencia', 'texto' => 'Bajo la meta'],
        };
    }

    private static function esPrenada(string $estado): bool
    {
        $texto = mb_strtoupper(trim($estado));
        return in_array($texto, ['PREÑADA', 'PRENADA'], true);
    }

    private static function distintoDeCero(string $valor): bool
    {
        return preg_match('/^-?0*(\.0*)?$/', $valor) !== 1;
    }

    private static function dias(string $desde, string $hasta): ?int
    {
        $dias = (int) (new \DateTimeImmutable($desde))->diff(new \DateTimeImmutable($hasta))->format('%r%a');
        return $dias >= 0 ? $dias : null;
    }
}
