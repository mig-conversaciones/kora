<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;
use App\Politicas\RecursoUnidad;
use App\Repositorios\RepositorioSalidas;

/**
 * Salidas de un lote: venta directa y autoconsumo (RF-043, RF-044, RF-046).
 *   - Hereda la unidad, el producto y la medida del lote. El valor es cantidad × precio, redondeado a 2 decimales.
 *   - El lote se bloquea (FOR UPDATE) durante la validación del saldo: dos salidas simultáneas no pueden sobrepasar
 *     lo recibido.
 *   - La venta directa no crea recaudos (RF-044): se cruzan después por conciliación (RF-032).
 */
final class ServicioSalidas
{
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly RepositorioSalidas $repositorio,
        private readonly AutorizacionRecurso $autorizacion,
        private readonly ServicioPeriodos $periodos,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioVersionDatos $versiones,
    ) {
    }

    /**
     * @param array{unidad:?int,canal:?string,desde:?string,hasta:?string,sin_recepcion:bool} $filtros
     * @return array{filas:list<array<string,mixed>>,hayMas:bool}
     */
    public function listar(Solicitud $solicitud, array $filtros, int $pagina): array
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $condiciones = [];
        $parametros = [];
        if ($filtros['unidad'] !== null) {
            $this->autorizacion->exigir($solicitud, 'salidas.consultar', new RecursoUnidad($alcance->centroId, $filtros['unidad']));
            $condiciones[] = 's.unidad_id = :f_unidad';
            $parametros['f_unidad'] = $filtros['unidad'];
        }
        if ($filtros['canal'] !== null) {
            $condiciones[] = 's.canal = :f_canal';
            $parametros['f_canal'] = $filtros['canal'];
        }
        if ($filtros['desde'] !== null) {
            $condiciones[] = 's.fecha >= :f_desde';
            $parametros['f_desde'] = $filtros['desde'];
        }
        if ($filtros['hasta'] !== null) {
            $condiciones[] = 's.fecha <= :f_hasta';
            $parametros['f_hasta'] = $filtros['hasta'];
        }
        if ($filtros['sin_recepcion']) {
            $condiciones[] = 's.recepcion_id IS NULL';
        }
        $resultado = $this->repositorio->listar($alcance, implode(' AND ', $condiciones), $parametros, '-fecha', $pagina);
        $resultado['filas'] = $this->conNombres($resultado['filas']);
        return $resultado;
    }

    /** @return array<string,mixed> */
    public function obtener(Solicitud $solicitud, int $id): array
    {
        $fila = $this->repositorio->obtener(AutorizacionRecurso::alcance($solicitud), $id);
        if ($fila['archivado_en'] !== null) {
            throw new ErrorHttp(404);
        }
        $fila['transformaciones'] = $this->transformacionesDe($id);
        return $this->conNombres([$fila])[0];
    }

    /**
     * Lotes abiertos (con saldo) del alcance, para el formulario. Incluye el lote de la salida que se edita.
     *
     * @return list<array<string,mixed>>
     */
    public function lotesDisponibles(Solicitud $solicitud, ?int $incluir = null): array
    {
        [$condicion, $parametros] = AutorizacionRecurso::alcance($solicitud)->condicionSql('r');
        return $this->bd->consultar(
            "SELECT r.id, r.numero_interno, r.fecha_recepcion, r.cantidad, r.medida, r.unidad_id, r.precio_sugerido, p.nombre AS producto_nombre,
                    r.cantidad - COALESCE((SELECT SUM(s.cantidad) FROM salidas s WHERE s.recepcion_id = r.id AND s.archivado_en IS NULL), 0) AS saldo
               FROM recepciones_lote r JOIN productos p ON p.id = r.producto_id
              WHERE {$condicion} AND r.archivado_en IS NULL
             HAVING saldo > 0 OR r.id = :incluir
              ORDER BY r.fecha_recepcion DESC, r.id DESC LIMIT 200",
            $parametros + ['incluir' => $incluir ?? 0]
        );
    }

    /**
     * Procesos de autoconsumo del alcance (vigencias recientes) para el formulario.
     *
     * @return list<array<string,mixed>>
     */
    public function procesosDisponibles(Solicitud $solicitud): array
    {
        [$condicion, $parametros] = AutorizacionRecurso::alcance($solicitud)->condicionSql('p');
        return $this->bd->consultar(
            "SELECT p.id, p.codigo, p.vigencia, p.unidad_id, p.producto_texto, p.medida FROM procesos_autoconsumo p
              WHERE {$condicion} AND p.archivado_en IS NULL AND p.vigencia >= :desde ORDER BY p.vigencia DESC, p.codigo",
            $parametros + ['desde' => (int) date('Y') - 1]
        );
    }

    /** @param array<string,mixed> $datos */
    public function guardar(Solicitud $solicitud, ?int $id, array $datos): int
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $actual = $id === null ? null : $this->obtener($solicitud, $id);
        if ($actual !== null) {
            $this->autorizacion->exigir($solicitud, 'salidas.gestionar', new RecursoUnidad((int) $actual['centro_id'], (int) $actual['unidad_id']));
            if ($actual['recepcion_id'] === null) {
                throw new ErrorHttp(409, 'La salida heredada no tiene recepción: vincúlala primero desde la bandeja de calidad.');
            }
        }
        $errores = [];
        $recepcion = Entrada::entero($datos['recepcion_id'] ?? '', 1);
        $fecha = Entrada::fecha($datos['fecha'] ?? '');
        if ($fecha === null) {
            $errores['fecha'] = 'Escribe la fecha de la salida (AAAA-MM-DD).';
        } elseif ($fecha > (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m-d')) {
            $errores['fecha'] = 'La fecha de la salida no puede ser futura.';
        }
        $cantidad = Entrada::decimal($datos['cantidad'] ?? '', 3);
        if ($cantidad === null || Entrada::comparar($cantidad, '0.000') <= 0) {
            $errores['cantidad'] = 'Escribe una cantidad mayor que 0 (hasta 3 decimales).';
        }
        $precio = Entrada::decimal($datos['precio_unitario'] ?? '');
        if ($precio === null) {
            $errores['precio_unitario'] = 'Escribe el precio unitario en pesos, sin signo.';
        }
        $canal = (string) ($datos['canal'] ?? '');
        if (!in_array($canal, ['VENTA_DIRECTA', 'AUTOCONSUMO'], true)) {
            $errores['canal'] = 'Elige el canal: venta directa o autoconsumo.';
        }
        $procesoId = $canal === 'AUTOCONSUMO' ? Entrada::entero($datos['proceso_id'] ?? '', 1) : null;
        $factura = Entrada::texto($datos['factura'] ?? '', 40);
        $referencia = Entrada::texto($datos['referencia_transaccion'] ?? '', 80);
        $destino = Entrada::texto($datos['destino'] ?? '', 150);
        if ($factura === null) {
            $errores['factura'] = 'Máximo 40 caracteres.';
        }
        if ($referencia === null) {
            $errores['referencia_transaccion'] = 'Máximo 80 caracteres.';
        }
        if ($destino === null) {
            $errores['destino'] = 'Máximo 150 caracteres.';
        }
        if ($recepcion === null) {
            $errores['recepcion_id'] = 'Elige el lote del que sale el producto.';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        /** @var int $recepcion */
        /** @var string $fecha */
        /** @var string $cantidad */
        /** @var string $precio */
        $usuario = AutorizacionRecurso::usuario($solicitud);
        return $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $alcance, $id, $actual, $recepcion, $fecha, $cantidad, $precio, $canal, $procesoId, $factura, $referencia, $destino, $datos, $usuario): int {
            [$condicion, $parametros] = $alcance->condicionSql('r');
            $lote = $bd->fila(
                "SELECT r.id, r.centro_id, r.unidad_id, r.producto_id, r.medida, r.cantidad, r.fecha_recepcion, p.codigo_sgac, p.nombre
                   FROM recepciones_lote r JOIN productos p ON p.id = r.producto_id
                  WHERE r.id = :id AND r.archivado_en IS NULL AND {$condicion} FOR UPDATE",
                $parametros + ['id' => $recepcion]
            );
            if ($lote === null) {
                throw new ErrorValidacion(['recepcion_id' => 'Elige un lote activo de tus unidades.']);
            }
            if ($actual !== null && (int) $actual['recepcion_id'] !== (int) $lote['id']) {
                throw new ErrorValidacion(['recepcion_id' => 'Una salida registrada no cambia de lote; archívala y registra una nueva.']);
            }
            $this->autorizacion->exigir($solicitud, 'salidas.gestionar', new RecursoUnidad((int) $lote['centro_id'], (int) $lote['unidad_id']));
            $this->periodos->exigirAbierto($alcance->centroId, $fecha, (string) ($actual['fecha'] ?? ''));
            $proceso = $procesoId === null ? null : $bd->fila(
                'SELECT id, unidad_id, medida, vigencia, producto_id, producto_texto, destino_id, destino_texto FROM procesos_autoconsumo WHERE id = :p AND centro_id = :c AND archivado_en IS NULL',
                ['p' => $procesoId, 'c' => $alcance->centroId]
            );
            $otras = (string) $bd->valor(
                'SELECT COALESCE(SUM(cantidad), 0) FROM salidas WHERE recepcion_id = :r AND archivado_en IS NULL AND id <> :id',
                ['r' => $lote['id'], 'id' => $id ?? 0]
            );
            $transformaciones = $id === null ? ['litros' => '0', 'primera_fecha' => null, 'cantidad' => 0] : $this->transformacionesDe($id);
            $errores = ReglasOperacion::salida(
                ['fecha' => $fecha, 'cantidad' => $cantidad, 'canal' => $canal],
                [
                    'fecha_recepcion' => (string) $lote['fecha_recepcion'],
                    'cantidad' => (string) $lote['cantidad'],
                    'unidad_id' => (int) $lote['unidad_id'],
                    'medida' => (string) $lote['medida'],
                    'producto_id' => (int) $lote['producto_id'],
                    'producto_codigo' => (string) $lote['codigo_sgac'],
                    'producto_nombre' => (string) $lote['nombre'],
                ],
                $otras,
                $proceso === null ? null : [
                    'unidad_id' => (int) $proceso['unidad_id'],
                    'medida' => (string) $proceso['medida'],
                    'vigencia' => (int) $proceso['vigencia'],
                    'producto_id' => $proceso['producto_id'] === null ? null : (int) $proceso['producto_id'],
                    'producto_texto' => (string) $proceso['producto_texto'],
                ],
                $transformaciones
            );
            $valor = Entrada::valorSalida($cantidad, $precio);
            if ($valor === null) {
                $errores['precio_unitario'] = 'El valor de la salida supera el máximo admitido.';
            }
            if ($errores !== []) {
                throw new ErrorValidacion($errores);
            }
            if ($proceso !== null) {
                $destino = $proceso['destino_texto'] !== null ? (string) $proceso['destino_texto']
                    : (string) ($bd->valor('SELECT nombre FROM destinos_internos WHERE id = :d', ['d' => $proceso['destino_id']]) ?? $destino);
            }
            $valores = [
                'unidad_id' => (int) $lote['unidad_id'],
                'recepcion_id' => (int) $lote['id'],
                'fecha' => $fecha,
                'producto_id' => (int) $lote['producto_id'],
                'cantidad' => $cantidad,
                'medida' => (string) $lote['medida'],
                'precio_unitario' => $precio,
                'valor' => $valor,
                'canal' => $canal,
                'proceso_id' => $proceso === null ? null : (int) $proceso['id'],
                'destino' => $destino === '' ? null : $destino,
                'factura' => $factura === '' ? null : $factura,
                'referencia_transaccion' => $referencia === '' ? null : $referencia,
            ];
            if ($actual === null) {
                $id = $this->repositorio->insertar($valores + [
                    'centro_id' => $alcance->centroId,
                    'clave_natural' => hash('sha256', 'manual|' . bin2hex(random_bytes(16))),
                    'origen_tipo' => 'manual',
                ], $usuario->id);
                $antes = null;
            } else {
                $this->repositorio->actualizar($alcance, $id, Entrada::entero($datos['version'] ?? '', 1) ?? 0, $valores, $usuario->id);
                $antes = ServicioCatalogos::extraer($actual, array_keys($valores));
            }
            $this->auditoria->registrar($solicitud, $antes === null ? 'salidas.crear' : 'salidas.actualizar', 'exito', new RecursoAuditado('salidas', $id, (int) $lote['unidad_id']), [], $antes, $valores);
            $this->versiones->incrementar($alcance->centroId);
            return $id;
        });
    }

    public function archivar(Solicitud $solicitud, int $id, int $version, string $motivo): void
    {
        $actual = $this->obtener($solicitud, $id);
        $this->autorizacion->exigir($solicitud, 'salidas.gestionar', new RecursoUnidad((int) $actual['centro_id'], (int) $actual['unidad_id']));
        $motivo = ServicioAsignaciones::motivo($motivo);
        if ($actual['transformaciones']['cantidad'] > 0) {
            throw new ErrorHttp(409, 'La entrega tiene transformaciones activas asociadas; no puede archivarse.');
        }
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $this->bd->transaccion(function () use ($solicitud, $id, $version, $motivo, $actual, $usuario): void {
            $this->periodos->exigirAbierto((int) $actual['centro_id'], (string) $actual['fecha']);
            $this->repositorio->archivar(AutorizacionRecurso::alcance($solicitud), $id, $version, $usuario->id, $motivo);
            $this->auditoria->registrar($solicitud, 'salidas.archivar', 'exito', new RecursoAuditado('salidas', $id, (int) $actual['unidad_id']), [], ['cantidad' => $actual['cantidad'], 'valor' => $actual['valor'], 'fecha' => $actual['fecha']], null, $motivo);
            $this->versiones->incrementar((int) $actual['centro_id']);
        });
    }

    /** @return array{litros:string,primera_fecha:?string,cantidad:int} */
    public function transformacionesDe(int $salidaId): array
    {
        $fila = $this->bd->fila(
            'SELECT COUNT(*) AS cantidad, COALESCE(SUM(litros_asignados), 0) AS litros, MIN(fecha) AS primera FROM transformaciones WHERE salida_leche_id = :s AND archivado_en IS NULL',
            ['s' => $salidaId]
        ) ?? [];
        return ['litros' => (string) ($fila['litros'] ?? '0'), 'primera_fecha' => isset($fila['primera']) ? (string) $fila['primera'] : null, 'cantidad' => (int) ($fila['cantidad'] ?? 0)];
    }

    /**
     * @param list<array<string,mixed>> $filas
     * @return list<array<string,mixed>>
     */
    private function conNombres(array $filas): array
    {
        if ($filas === []) {
            return [];
        }
        $productos = [];
        foreach ($this->bd->consultar('SELECT id, nombre FROM productos WHERE centro_id = :c', ['c' => (int) $filas[0]['centro_id']]) as $p) {
            $productos[(int) $p['id']] = (string) $p['nombre'];
        }
        $lotes = [];
        $procesos = [];
        $idsLotes = array_values(array_unique(array_filter(array_map(static fn (array $f): int => (int) ($f['recepcion_id'] ?? 0), $filas))));
        $idsProcesos = array_values(array_unique(array_filter(array_map(static fn (array $f): int => (int) ($f['proceso_id'] ?? 0), $filas))));
        if ($idsLotes !== []) {
            foreach ($this->bd->consultar('SELECT id, numero_interno FROM recepciones_lote WHERE id IN (' . implode(',', $idsLotes) . ')') as $l) {
                $lotes[(int) $l['id']] = (string) $l['numero_interno'];
            }
        }
        if ($idsProcesos !== []) {
            foreach ($this->bd->consultar('SELECT id, codigo FROM procesos_autoconsumo WHERE id IN (' . implode(',', $idsProcesos) . ')') as $p) {
                $procesos[(int) $p['id']] = (string) $p['codigo'];
            }
        }
        foreach ($filas as &$fila) {
            $fila['producto_nombre'] = $fila['producto_id'] === null ? (string) ($fila['nombre_producto_fuente'] ?? '') : ($productos[(int) $fila['producto_id']] ?? '');
            $fila['numero_lote'] = $fila['recepcion_id'] === null ? (string) ($fila['numero_interno_texto'] ?? '') : ($lotes[(int) $fila['recepcion_id']] ?? '');
            $fila['proceso_codigo'] = $fila['proceso_id'] === null ? null : ($procesos[(int) $fila['proceso_id']] ?? null);
        }
        return $filas;
    }
}
