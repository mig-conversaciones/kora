<?php

declare(strict_types=1);

namespace App\Tableros;

use App\Cola\DespachadorCola;
use App\Cola\ErrorDefinitivo;
use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\GeneradorUlid;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Politicas\AlcanceDatos;
use App\Politicas\IdentidadActor;
use App\Servicios\AutorizacionRecurso;
use App\Servicios\RecursoAuditado;
use App\Servicios\ServicioAuditoria;
use Dompdf\Dompdf;
use Dompdf\Options;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Exportaciones de tableros y listados (RF-080 a RF-082):
 *   - se piden con los filtros de la pantalla y se generan en la cola (exportacion.xlsx o exportacion.pdf), con la
 *     identidad y el alcance del autor releídos al ejecutar (RAC-024): un líder de Ganadería solo exporta GAN;
 *   - el archivo queda en almacenamiento privado con un nombre aleatorio y lo descarga solo su autor, con permiso
 *     vigente, durante 24 horas; después lo borra la limpieza periódica;
 *   - ninguna exportación lleva identificaciones de pagadores (RF-034): ni siquiera los 4 últimos dígitos.
 * Las cifras salen del mismo ServicioIndicadores y del mismo Formato que la pantalla (RF-081).
 */
final class ServicioExportacion
{
    /** tipo => [título, permiso de consulta, formatos admitidos] */
    public const TIPOS = [
        'tablero_central' => ['Tablero central', 'tablero_central.consultar', ['xlsx', 'csv', 'pdf']],
        'tablero_unidad' => ['Tablero técnico de unidad', 'tablero_unidad.consultar', ['xlsx', 'csv', 'pdf']],
        'recaudos' => ['Consulta de recaudos', 'recaudos.consultar', ['xlsx', 'csv']],
    ];

    private const VIGENCIA_HORAS = 24;
    private const MAX_FILAS_LISTADO = 20000;

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly ServicioIndicadores $indicadores,
        private readonly DespachadorCola $despachador,
        private readonly ServicioAuditoria $auditoria,
        private readonly Vista $vista,
        private readonly string $directorio,
    ) {
    }

    /**
     * Encola la exportación y devuelve la fila de exportaciones con el trabajo (202 con seguimiento).
     *
     * @param array<string,mixed> $filtros los de FiltrosTablero::aArreglo() más unidad_tecnica y practica
     * @return array<string,mixed>
     */
    public function solicitar(Solicitud $solicitud, string $tipo, string $formato, array $filtros): array
    {
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $definicion = self::TIPOS[$tipo] ?? null;
        if ($definicion === null) {
            throw new ErrorHttp(404);
        }
        if (!$usuario->tienePermiso('exportaciones.solicitar') || !$usuario->tienePermiso($definicion[1]) || $alcance->estaVacio()) {
            throw new ErrorHttp(403);
        }
        if (!in_array($formato, $definicion[2], true)) {
            throw new ErrorValidacion(['formato' => 'Ese formato no está disponible para esta exportación.']);
        }
        $ulid = GeneradorUlid::generar();
        return $this->bd->transaccion(function () use ($solicitud, $usuario, $alcance, $tipo, $formato, $filtros, $ulid): array {
            $this->bd->ejecutar(
                'INSERT INTO exportaciones (ulid, centro_id, usuario_id, tipo, formato, filtros) VALUES (:ulid, :c, :u, :t, :f, :filtros)',
                ['ulid' => $ulid, 'c' => $alcance->centroId, 'u' => $usuario->id, 't' => $tipo, 'f' => $formato,
                    'filtros' => json_encode($filtros, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]
            );
            $id = (int) $this->bd->valor('SELECT id FROM exportaciones WHERE ulid = :ulid', ['ulid' => $ulid]);
            $trabajo = $this->despachador->encolar($formato === 'pdf' ? 'exportacion.pdf' : 'exportacion.xlsx', ['exportacion_id' => $id], [
                'actor_id' => $usuario->id, 'centro_id' => $alcance->centroId, 'clave_idempotencia' => 'exportacion-' . $ulid,
            ]);
            $this->bd->ejecutar('UPDATE exportaciones SET trabajo_id = :t WHERE id = :id', ['t' => $trabajo['id'], 'id' => $id]);
            $this->auditoria->registrar($solicitud, 'exportaciones.solicitar', 'exito', new RecursoAuditado('exportaciones', $id), ['tipo' => $tipo, 'formato' => $formato, 'filtros' => $filtros]);
            return ['id' => $id, 'ulid' => $ulid, 'tipo' => $tipo, 'formato' => $formato, 'trabajo_ulid' => $trabajo['ulid']];
        });
    }

    /**
     * Genera el archivo (trabajos exportacion.xlsx y exportacion.pdf). Idempotente: si ya existe, no lo rehace.
     *
     * @return array<string,mixed>
     */
    public function generar(int $exportacionId): array
    {
        $exportacion = $this->bd->fila('SELECT * FROM exportaciones WHERE id = :id', ['id' => $exportacionId]);
        if ($exportacion === null) {
            throw new ErrorDefinitivo('La exportación no existe.');
        }
        if ($exportacion['ruta_privada'] !== null && is_file($this->directorio . '/' . $exportacion['ruta_privada'])) {
            return ['archivo' => $exportacion['ruta_privada'], 'reutilizado' => true];
        }
        $tipo = (string) $exportacion['tipo'];
        $definicion = self::TIPOS[$tipo] ?? throw new ErrorDefinitivo('Tipo de exportación desconocido.');
        $actor = IdentidadActor::cargar($this->bd, (int) $exportacion['usuario_id'], (int) $exportacion['centro_id']);
        if (!$actor->tienePermiso('exportaciones.solicitar') || !$actor->tienePermiso($definicion[1]) || $actor->alcance->estaVacio()) {
            throw new ErrorDefinitivo('El usuario ya no tiene permiso para esta exportación.');
        }
        $filtros = json_decode((string) $exportacion['filtros'], true, 8, JSON_THROW_ON_ERROR);
        $datos = $this->datos($tipo, is_array($filtros) ? $filtros : [], $actor);
        $formato = (string) $exportacion['formato'];
        $relativa = substr((string) $exportacion['ulid'], -10) . '-' . bin2hex(random_bytes(12)) . '.' . $formato;
        if (!is_dir($this->directorio)) {
            mkdir($this->directorio, 0750, true);
        }
        $ruta = $this->directorio . '/' . $relativa;
        $temporal = $ruta . '.tmp';
        match ($formato) {
            'xlsx' => $this->escribirXlsx($temporal, $datos['tablas']),
            'csv' => $this->escribirCsv($temporal, $datos['tablas']),
            'pdf' => file_put_contents($temporal, $this->pdf($this->htmlPdf($datos)), LOCK_EX),
            default => throw new ErrorDefinitivo('Formato no admitido.'),
        };
        rename($temporal, $ruta);
        @chmod($ruta, 0640);
        $this->bd->ejecutar(
            'UPDATE exportaciones SET ruta_privada = :r, expira_en = UTC_TIMESTAMP(3) + INTERVAL ' . self::VIGENCIA_HORAS . ' HOUR WHERE id = :id',
            ['r' => $relativa, 'id' => $exportacionId]
        );
        return ['archivo' => $relativa, 'filas' => array_sum(array_map(static fn (array $t): int => count($t['filas']), $datos['tablas']))];
    }

    /**
     * Archivo de una exportación propia, vigente y con permiso actual (RF-082). Cualquier otro caso es 404: el enlace
     * de otra persona no revela que la exportación existe.
     *
     * @return array{ruta:string,nombre:string,tipo:string}
     */
    public function descargar(Solicitud $solicitud, string $ulid): array
    {
        $usuario = AutorizacionRecurso::usuario($solicitud);
        if (!GeneradorUlid::esValido($ulid)) {
            throw new ErrorHttp(404);
        }
        $exportacion = $this->bd->fila(
            'SELECT * FROM exportaciones WHERE ulid = :ulid AND usuario_id = :u AND centro_id = :c AND expira_en > UTC_TIMESTAMP(3) AND ruta_privada IS NOT NULL',
            ['ulid' => $ulid, 'u' => $usuario->id, 'c' => (int) $usuario->centroId]
        );
        $definicion = $exportacion === null ? null : (self::TIPOS[(string) $exportacion['tipo']] ?? null);
        if ($exportacion === null || $definicion === null || !$usuario->tienePermiso('exportaciones.solicitar') || !$usuario->tienePermiso($definicion[1])) {
            throw new ErrorHttp(404);
        }
        $ruta = $this->directorio . '/' . basename((string) $exportacion['ruta_privada']);
        if (!is_file($ruta)) {
            throw new ErrorHttp(404);
        }
        $this->bd->ejecutar('UPDATE exportaciones SET descargado_en = UTC_TIMESTAMP(3) WHERE id = :id', ['id' => (int) $exportacion['id']]);
        $this->auditoria->registrar($solicitud, 'exportaciones.descargar', 'exito', new RecursoAuditado('exportaciones', (int) $exportacion['id']), ['tipo' => $exportacion['tipo'], 'formato' => $exportacion['formato']]);
        $formato = (string) $exportacion['formato'];
        $fecha = substr((string) $exportacion['creado_en'], 0, 10);
        return [
            'ruta' => $ruta,
            'nombre' => 'kora-' . str_replace('_', '-', (string) $exportacion['tipo']) . '-' . $fecha . '.' . $formato,
            'tipo' => ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv; charset=utf-8', 'pdf' => 'application/pdf'][$formato] ?? 'application/octet-stream',
        ];
    }

    /** @return array<string,mixed>|null exportación propia (para el seguimiento) */
    public function propia(Solicitud $solicitud, string $ulid): ?array
    {
        $usuario = AutorizacionRecurso::usuario($solicitud);
        if (!GeneradorUlid::esValido($ulid)) {
            return null;
        }
        return $this->bd->fila(
            'SELECT e.*, t.ulid AS trabajo_ulid, t.estado, t.progreso, t.mensaje_progreso, t.mensaje_error,
                    e.expira_en > UTC_TIMESTAMP(3) AS vigente
               FROM exportaciones e LEFT JOIN trabajos t ON t.id = e.trabajo_id
              WHERE e.ulid = :ulid AND e.usuario_id = :u AND e.centro_id = :c',
            ['ulid' => $ulid, 'u' => $usuario->id, 'c' => (int) $usuario->centroId]
        );
    }

    /** Limpieza: borra los archivos vencidos y olvida su ruta (RF-082). */
    public function limpiarVencidas(): int
    {
        $borradas = 0;
        foreach ($this->bd->consultar('SELECT id, ruta_privada FROM exportaciones WHERE ruta_privada IS NOT NULL AND expira_en < UTC_TIMESTAMP(3) LIMIT 1000') as $fila) {
            $ruta = $this->directorio . '/' . basename((string) $fila['ruta_privada']);
            if (is_file($ruta)) {
                @unlink($ruta);
            }
            $this->bd->ejecutar('UPDATE exportaciones SET ruta_privada = NULL WHERE id = :id', ['id' => (int) $fila['id']]);
            $borradas++;
        }
        return $borradas;
    }

    // ---------------------------------------------------------------- Contenido

    /**
     * Tablas y metadatos de la exportación, calculados con la identidad releída del autor.
     *
     * @param array<string,mixed> $filtros
     * @return array{titulo:string,usuario:string,generado:string,filtros:list<array{0:string,1:string}>,notas:list<string>,kpi:list<array{0:string,1:string}>,tablas:list<array{titulo:string,columnas:list<string>,filas:list<list<mixed>>}>}
     */
    public function datos(string $tipo, array $filtros, IdentidadActor $actor): array
    {
        $alcance = $actor->alcance;
        $unidades = array_map(static fn (array $u): int => (int) $u['id'], $this->indicadores->unidadesDelAlcance($alcance));
        $consulta = array_map(static fn (mixed $v): string => $v === null ? '' : (string) $v, $filtros);
        $f = FiltrosTablero::desdeConsulta($consulta, $unidades, (int) ($filtros['vigencia'] ?? date('Y')));
        $generado = (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('d/m/Y H:i');
        $base = ['titulo' => self::TIPOS[$tipo][0], 'usuario' => $actor->nombre, 'generado' => $generado, 'filtros' => $this->descripcionFiltros($f, $alcance), 'notas' => [], 'kpi' => [], 'tablas' => []];
        return match ($tipo) {
            'tablero_central' => $this->datosCentral($base, $alcance, $f),
            'tablero_unidad' => $this->datosUnidad($base, $alcance, $f, (int) ($filtros['unidad_tecnica'] ?? 0), !empty($filtros['practica'])),
            'recaudos' => $this->datosRecaudos($base, $alcance, $f),
            default => throw new ErrorDefinitivo('Tipo de exportación desconocido.'),
        };
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private function datosCentral(array $base, AlcanceDatos $alcance, FiltrosTablero $f): array
    {
        $c = $this->indicadores->central($alcance, $f);
        $k = $c['kpi'];
        $base['kpi'] = [
            ['Asignación presupuestal (ANUAL)', Formato::moneda($k['asignacion']) . ' · ' . $k['asignaciones'] . ' asignaciones'],
            ['Recaudo de venta directa', Formato::moneda($k['venta_directa']) . ' · ' . $k['recaudos'] . ' recaudos'],
            ['Ventas por autoconsumo', Formato::moneda($k['autoconsumo']) . ' · ' . $k['salidas'] . ' salidas'],
            ['Total de ventas realizadas', Formato::moneda($k['total'])],
            ['Cobertura sobre la asignación', Formato::porcentaje($k['cobertura'])],
            ['Saldo por cubrir', Formato::moneda($k['saldo'])],
        ];
        $base['notas'] = self::notasCentral($c);
        $base['tablas'][] = ['titulo' => 'IND_001 por unidad', 'columnas' => ['Unidad', 'Asignación', 'Mantenimiento', 'Alimentación', 'Materiales de formación', 'Venta directa', 'Autoconsumo', 'Total', 'Cobertura (%)'],
            'filas' => array_map(static fn (array $u): array => [$u['nombre'], self::num($u['asignacion']), self::num($u['mantenimiento']), self::num($u['alimentacion']),
                self::num($u['materiales_formacion']), self::num($u['venta_directa']), self::num($u['autoconsumo']), self::num($u['total']), self::pct($u['cobertura'])], $c['unidades'])];
        $base['tablas'][] = ['titulo' => 'IND_002 por proceso', 'columnas' => ['Proceso', 'Origen', 'Destino', 'Producto', 'Medida', 'Cupo', 'Entregado', 'Saldo', 'Avance físico (%)', 'Valor del cupo', 'Valor entregado', 'Avance monetario (%)', 'Lotes vinculados'],
            'filas' => array_map(static fn (array $p): array => [$p['codigo'], $p['unidad_nombre'], $p['destino'], $p['producto'], $p['medida'], self::num($p['cupo_cantidad']),
                self::num($p['entregado']), self::num($p['saldo_cantidad']), self::pct($p['avance_fisico']), self::num($p['cupo_valor']), self::num($p['valor']), self::pct($p['avance_monetario']), $p['lotes']], $c['procesos'])];
        return $base;
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private function datosUnidad(array $base, AlcanceDatos $alcance, FiltrosTablero $f, int $unidadId, bool $practica): array
    {
        if (!$alcance->permiteUnidad($unidadId) || $unidadId <= 0) {
            throw new ErrorDefinitivo('La unidad no está en el alcance del usuario.');
        }
        $nombre = (string) ($this->bd->valor('SELECT nombre FROM unidades_productivas WHERE id = :u AND centro_id = :c', ['u' => $unidadId, 'c' => $alcance->centroId]) ?? '');
        $h = $this->indicadores->hato($alcance, $unidadId, $practica, $f->fin());
        $t = $this->indicadores->transformacion($alcance, $unidadId, $f);
        $r = $this->indicadores->rotacion($alcance, $unidadId, $f);
        $co = $this->indicadores->costoLitro($alcance, $unidadId, $f);
        $base['titulo'] .= ' · ' . $nombre;
        $base['filtros'][] = ['Lote de práctica', $practica ? 'Incluido' : 'Excluido'];
        $base['filtros'][] = ['Corte del hato', Formato::fecha($h['corte'])];
        $base['kpi'] = [
            ['IND_003 · Tasa productiva', Formato::porcentaje($h['tasa']) . ' (' . $h['produccion'] . ' / ' . $h['poblacion'] . ', meta ' . Formato::porcentaje($h['meta'], 0) . ')'],
            ['Tasa de preñez', Formato::porcentaje($h['prenez'])],
            ['Disponibilidad didáctica', $h['practica'] . ' animales · ' . Formato::porcentaje($h['disponibilidad'])],
            ['IND_08 · Aprovechamiento global', Formato::porcentaje($t['global']) . ' (meta ' . Formato::porcentaje($t['meta'], 0) . ')'],
            ['IND_12 · Rotación promedio', $r['promedio'] === null ? Formato::SIN_DATO : Formato::decimal($r['promedio'], 1) . ' días (' . $r['cerrados'] . ' lotes cerrados)'],
            ['IND_17 · Costo operativo por litro', $co['costo'] === null ? 'Pendiente' : Formato::moneda($co['costo']) . ' /L (meta ' . Formato::moneda($co['meta']) . ' /L)'],
        ];
        $base['notas'] = [
            'El hato es un corte fechado: el filtro de fechas no reconstruye su historia.',
            'IND_08 solo usa transformaciones con vínculos verificables y factor mayor que 0.',
            'IND_12: los lotes abiertos muestran su antigüedad y no entran en el promedio.',
            'IND_17 excluye Materiales de formación y el componente académico; los cupos de autoconsumo no se suman a la producción.',
        ];
        $base['tablas'][] = ['titulo' => 'Salud reproductiva', 'columnas' => ['Animal', 'Lote', 'Estado reproductivo', 'Último parto', 'IEP (días)', 'Semáforo IEP', 'Días abiertos', 'Semáforo días abiertos'],
            'filas' => array_map(static fn (array $a): array => [$a['identificador'], $a['lote_hato'], (string) $a['estado_reproductivo'], Formato::fecha($a['ultimo_parto']),
                $a['iep_dias'], $a['semaforo_iep']['texto'], $a['dias_abiertos'], $a['semaforo_abiertos']['texto']], $h['animales'])];
        $base['tablas'][] = ['titulo' => 'IND_08 por lote', 'columnas' => ['Fecha', 'Lote', 'Derivado', 'Litros asignados', 'Cantidad', 'Medida', 'Rendimiento (L por unidad)', 'Aprovechamiento (%)'],
            'filas' => array_map(static fn (array $l): array => [Formato::fecha($l['fecha']), $l['lote'], $l['producto'], self::num($l['litros']), self::num($l['cantidad']), $l['medida'],
                round((float) $l['rendimiento'], 4), round((float) $l['aprovechamiento'], 2)], $t['lotes'])];
        $base['tablas'][] = ['titulo' => 'IND_12 rotación de lotes', 'columnas' => ['Lote', 'Producto', 'Recepción', 'Cantidad', 'Medida', 'Salidas', 'Saldo', 'Cierre', 'Días', 'Referencia (días)'],
            'filas' => array_map(static fn (array $l): array => [$l['lote'], $l['producto'], Formato::fecha($l['fecha']), self::num($l['cantidad']), $l['medida'], self::num($l['vendido']),
                self::num($l['saldo']), $l['cierre'] === null ? 'Abierto' : Formato::fecha($l['cierre']), $l['dias'], $l['meta']], $r['lotes'])];
        $base['tablas'][] = ['titulo' => 'IND_17 costo por litro', 'columnas' => ['Concepto', 'Valor'], 'filas' => [
            ['Gasto ejecutado (Mantenimiento y Alimentación)', self::num($co['bruto'])],
            ['Componente académico', self::num($co['academico'])],
            ['Gasto operativo', self::num($co['operativo'])],
            ['Litros de leche recibidos', self::num($co['litros'])],
            ['Costo operativo por litro', $co['costo']],
            ['Precio de referencia', $co['precio']],
            ['Meta de costo', $co['meta']],
        ]];
        return $base;
    }

    /**
     * Consulta de recaudos sin datos personales (RF-034, RF-080).
     *
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private function datosRecaudos(array $base, AlcanceDatos $alcance, FiltrosTablero $f): array
    {
        [$condicion, $p] = $alcance->condicionSql('r');
        $unidad = '';
        if ($f->unidadId !== null) {
            $unidad = 'AND r.unidad_id = :unidad';
            $p['unidad'] = $f->unidadId;
        }
        $filas = $this->bd->consultar(
            "SELECT r.fecha_pago, u.nombre AS unidad, COALESCE(pr.nombre, r.concepto_fuente) AS producto, r.valor, r.tipo_ingreso, r.pasarela,
                    r.prefactura, r.factura_electronica, r.estado_clasificacion, r.estado_conciliacion
               FROM recaudos r LEFT JOIN unidades_productivas u ON u.id = r.unidad_id LEFT JOIN productos pr ON pr.id = r.producto_id
              WHERE {$condicion} AND r.archivado_en IS NULL AND r.fecha_pago BETWEEN :inicio AND :fin {$unidad}
              ORDER BY r.fecha_pago, r.id LIMIT " . self::MAX_FILAS_LISTADO,
            $p + ['inicio' => $f->inicio(), 'fin' => $f->fin()]
        );
        $base['notas'] = ['No incluye nombres ni identificaciones de los pagadores (RF-034).'];
        $base['tablas'][] = ['titulo' => 'Recaudos', 'columnas' => ['Fecha de pago', 'Unidad', 'Producto o concepto', 'Valor', 'Tipo de ingreso', 'Pasarela', 'Prefactura', 'Factura electrónica', 'Clasificación', 'Conciliación'],
            'filas' => array_map(static fn (array $r): array => [Formato::fecha((string) $r['fecha_pago']), (string) ($r['unidad'] ?? 'Sin unidad'), (string) $r['producto'], self::num($r['valor']),
                (string) $r['tipo_ingreso'], (string) $r['pasarela'], (string) $r['prefactura'], (string) $r['factura_electronica'], (string) $r['estado_clasificacion'], (string) $r['estado_conciliacion']], $filas)];
        return $base;
    }

    /**
     * @param array<string,mixed> $central
     * @return list<string>
     */
    public static function notasCentral(array $central): array
    {
        $notas = [
            'La asignación es anual y no se prorratea al filtrar por fechas.',
            'Venta directa = recaudos de tipo «Producción» del periodo; autoconsumo = salidas por autoconsumo del periodo.',
            'Las ventas por transferencia se muestran solo para conciliar y no se suman. La cobertura comercial no es ejecución CDP/RP ni saldo de caja.',
        ];
        if ((int) $central['sin_unidad']['recaudos'] > 0) {
            $notas[] = sprintf('%d recaudos (%s) no tienen una unidad inequívoca: cuentan en el centro, no se reparten entre unidades.', $central['sin_unidad']['recaudos'], Formato::moneda($central['sin_unidad']['valor']));
        }
        if ((int) $central['sin_proceso']['salidas'] > 0) {
            $notas[] = sprintf('%d entregas por autoconsumo sin proceso cuentan en IND_001 pero no en las metas de IND_002.', $central['sin_proceso']['salidas']);
        }
        return $notas;
    }

    /** @return list<array{0:string,1:string}> */
    private function descripcionFiltros(FiltrosTablero $f, AlcanceDatos $alcance): array
    {
        $unidad = 'Todas las del alcance';
        if ($f->unidadId !== null) {
            $unidad = (string) ($this->bd->valor('SELECT nombre FROM unidades_productivas WHERE id = :u AND centro_id = :c', ['u' => $f->unidadId, 'c' => $alcance->centroId]) ?? $unidad);
        }
        $producto = 'Todos';
        if ($f->productoId !== null) {
            $producto = (string) ($this->bd->valor('SELECT nombre FROM productos WHERE id = :p AND centro_id = :c', ['p' => $f->productoId, 'c' => $alcance->centroId]) ?? $producto);
        } elseif ($f->concepto !== null) {
            $producto = $f->concepto . ' (concepto fuente)';
        }
        return [
            ['Vigencia', (string) $f->vigencia],
            ['Periodo', Formato::fecha($f->inicio()) . ' a ' . Formato::fecha($f->fin())],
            ['Unidad', $unidad],
            ['Producto', $producto],
            ['Canal', FiltrosTablero::CANALES[$f->canal]],
        ];
    }

    // ---------------------------------------------------------------- Formatos

    /** @param list<array{titulo:string,columnas:list<string>,filas:list<list<mixed>>}> $tablas */
    private function escribirXlsx(string $ruta, array $tablas): void
    {
        $escritor = new Writer();
        $escritor->openToFile($ruta);
        $primera = true;
        foreach ($tablas as $tabla) {
            $hoja = $primera ? $escritor->getCurrentSheet() : $escritor->addNewSheetAndMakeItCurrent();
            $primera = false;
            $hoja->setName(mb_substr((string) preg_replace('#[\\\\/?*\[\]:]#', ' ', $tabla['titulo']), 0, 31));
            $escritor->addRow(Row::fromValues($tabla['columnas']));
            foreach ($tabla['filas'] as $fila) {
                $escritor->addRow(Row::fromValues(array_map(static fn (mixed $v): mixed => $v ?? '', $fila)));
            }
        }
        $escritor->close();
    }

    /**
     * CSV en UTF-8 con BOM para que Excel lo abra bien; los números van sin comillas, como números (RF-080). Con varias
     * tablas, cada una lleva su título y una línea en blanco de separación.
     *
     * @param list<array{titulo:string,columnas:list<string>,filas:list<list<mixed>>}> $tablas
     */
    private function escribirCsv(string $ruta, array $tablas): void
    {
        $salida = fopen($ruta, 'wb');
        if ($salida === false) {
            throw new \RuntimeException('No se pudo escribir la exportación.');
        }
        fwrite($salida, "\xEF\xBB\xBF");
        foreach ($tablas as $i => $tabla) {
            if (count($tablas) > 1) {
                fwrite($salida, ($i > 0 ? "\r\n" : '') . self::celdaCsv($tabla['titulo']) . "\r\n");
            }
            fwrite($salida, implode(',', array_map([self::class, 'celdaCsv'], $tabla['columnas'])) . "\r\n");
            foreach ($tabla['filas'] as $fila) {
                fwrite($salida, implode(',', array_map([self::class, 'celdaCsv'], $fila)) . "\r\n");
            }
        }
        fclose($salida);
    }

    public static function celdaCsv(mixed $valor): string
    {
        if (is_int($valor) || is_float($valor)) {
            return is_float($valor) ? rtrim(rtrim(number_format($valor, 4, '.', ''), '0'), '.') : (string) $valor;
        }
        $texto = (string) $valor;
        // Neutraliza fórmulas al abrir en Excel (inyección CSV) y escapa comillas.
        if ($texto !== '' && in_array($texto[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $texto = "'" . $texto;
        }
        return '"' . str_replace('"', '""', $texto) . '"';
    }

    /** @param array<string,mixed> $datos */
    public function htmlPdf(array $datos): string
    {
        return $this->vista->fragmento('exportaciones/pdf', $datos + ['nombreApp' => $this->vista->nombreApp()]);
    }

    private function pdf(string $html): string
    {
        $opciones = new Options();
        // Sin recursos remotos ni PHP embebido: el HTML lo arma la aplicación, pero se cierra la puerta igual.
        $opciones->setIsRemoteEnabled(false);
        $opciones->setIsPhpEnabled(false);
        $opciones->setChroot([RAIZ . '/app/Vistas']);
        $opciones->setDefaultFont('DejaVu Sans');
        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'landscape');
        $dompdf->render();
        return (string) $dompdf->output();
    }

    /** Decimal como número (int o float) para que XLSX y CSV lo traten como número, no como texto. */
    private static function num(mixed $valor): int|float
    {
        $texto = (string) $valor;
        return preg_match('/^-?\d+$/', $texto) === 1 ? (int) $texto : (float) $texto;
    }

    private static function pct(?float $valor): ?float
    {
        return $valor === null ? null : round($valor, 2);
    }
}
