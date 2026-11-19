<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;
use App\Politicas\AlcanceDatos;
use App\Politicas\RecursoUnidad;
use App\Repositorios\RepositorioRecepciones;
use PDOException;

/**
 * Recepciones de lotes (RF-039 a RF-041):
 *   - Número interno {PREFIJO}-REC-{DDMMAA}-{NNN}. El sistema sugiere el siguiente consecutivo y la restricción
 *     única (centro, número, producto) impide el duplicado aunque dos personas registren a la vez.
 *   - La medida es la del catálogo. Con salidas, el producto, la unidad, la medida y el número ya no cambian.
 *   - Existencias: saldo = recibido − salidas activas. El lote se cierra con la salida que lo agota.
 */
final class ServicioRecepciones
{
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly RepositorioRecepciones $repositorio,
        private readonly AutorizacionRecurso $autorizacion,
        private readonly ServicioPeriodos $periodos,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioVersionDatos $versiones,
    ) {
    }

    /**
     * Listado con existencias. Filtrar por una unidad fuera del alcance responde 403 auditado (RF-051).
     *
     * @param array{unidad:?int,desde:?string,hasta:?string,producto:?int,estado:?string} $filtros
     * @return array{filas:list<array<string,mixed>>,hayMas:bool}
     */
    public function listar(Solicitud $solicitud, array $filtros, int $pagina): array
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $condiciones = [];
        $parametros = [];
        if ($filtros['unidad'] !== null) {
            $this->autorizacion->exigir($solicitud, 'recepciones.consultar', new RecursoUnidad($alcance->centroId, $filtros['unidad']));
            $condiciones[] = 'r.unidad_id = :f_unidad';
            $parametros['f_unidad'] = $filtros['unidad'];
        }
        if ($filtros['desde'] !== null) {
            $condiciones[] = 'r.fecha_recepcion >= :f_desde';
            $parametros['f_desde'] = $filtros['desde'];
        }
        if ($filtros['hasta'] !== null) {
            $condiciones[] = 'r.fecha_recepcion <= :f_hasta';
            $parametros['f_hasta'] = $filtros['hasta'];
        }
        if ($filtros['producto'] !== null) {
            $condiciones[] = 'r.producto_id = :f_producto';
            $parametros['f_producto'] = $filtros['producto'];
        }
        $saldo = 'r.cantidad - (SELECT COALESCE(SUM(s.cantidad), 0) FROM salidas s WHERE s.recepcion_id = r.id AND s.archivado_en IS NULL)';
        if ($filtros['estado'] === 'abierto') {
            $condiciones[] = "({$saldo}) > 0";
        } elseif ($filtros['estado'] === 'cerrado') {
            $condiciones[] = "({$saldo}) <= 0";
        }
        $resultado = $this->repositorio->listar($alcance, implode(' AND ', $condiciones), $parametros, '-fecha', $pagina);
        $resultado['filas'] = $this->conExistencias($resultado['filas']);
        return $resultado;
    }

    /**
     * Detalle del lote con sus existencias (RF-041) y sus salidas.
     *
     * @return array<string,mixed>
     */
    public function obtener(Solicitud $solicitud, int $id): array
    {
        $fila = $this->repositorio->obtener(AutorizacionRecurso::alcance($solicitud), $id);
        if ($fila['archivado_en'] !== null) {
            throw new ErrorHttp(404);
        }
        return $this->conExistencias([$fila])[0];
    }

    /** @return list<array<string,mixed>> salidas activas del lote, en orden de fecha */
    public function salidasDelLote(int $recepcionId): array
    {
        return $this->bd->consultar(
            'SELECT id, fecha, cantidad, medida, precio_unitario, valor, canal, proceso_id, destino, factura FROM salidas
              WHERE recepcion_id = :r AND archivado_en IS NULL ORDER BY fecha, id',
            ['r' => $recepcionId]
        );
    }

    /** @return array<string,string> id => nombre de las cuentas activas del centro (responsable que recibe, RF-014) */
    public function responsables(int $centroId): array
    {
        $opciones = [];
        foreach ($this->bd->consultar("SELECT id, nombre FROM usuarios WHERE centro_id = :c AND estado = 'activo' ORDER BY nombre", ['c' => $centroId]) as $u) {
            $opciones[(string) $u['id']] = (string) $u['nombre'];
        }
        return $opciones;
    }

    /** Siguiente número interno libre para la unidad y la fecha (RF-039). */
    public function siguienteNumero(int $centroId, int $unidadId, string $fecha): string
    {
        $prefijo = (string) ($this->bd->valor('SELECT prefijo_lote FROM unidades_productivas WHERE id = :u AND centro_id = :c', ['u' => $unidadId, 'c' => $centroId]) ?? '');
        if ($prefijo === '') {
            return '';
        }
        $base = ReglasOperacion::numeroInterno($prefijo, $fecha, 0);
        $base = substr($base, 0, -3);
        $maximo = (int) $this->bd->valor(
            'SELECT MAX(CAST(SUBSTRING(numero_interno, :largo) AS UNSIGNED)) FROM recepciones_lote WHERE centro_id = :c AND numero_interno LIKE :patron',
            ['largo' => strlen($base) + 1, 'c' => $centroId, 'patron' => str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $base) . '%']
        );
        return ReglasOperacion::numeroInterno($prefijo, $fecha, $maximo + 1);
    }

    /**
     * Crea o edita una recepción. Devuelve el id.
     *
     * @param array<string,mixed> $datos
     */
    public function guardar(Solicitud $solicitud, ?int $id, array $datos): int
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $actual = $id === null ? null : $this->obtener($solicitud, $id);
        if ($actual !== null) {
            $this->autorizacion->exigir($solicitud, 'recepciones.gestionar', new RecursoUnidad((int) $actual['centro_id'], (int) $actual['unidad_id']));
        }
        $valores = $this->validar($solicitud, $alcance, $datos, $actual);
        $usuario = AutorizacionRecurso::usuario($solicitud);
        try {
            return $this->bd->transaccion(function () use ($solicitud, $alcance, $id, $actual, $valores, $datos, $usuario): int {
                $this->periodos->exigirAbierto($alcance->centroId, (string) $valores['fecha_recepcion'], (string) ($actual['fecha_recepcion'] ?? ''));
                if ($actual === null) {
                    $id = $this->repositorio->insertar($valores + ['centro_id' => $alcance->centroId, 'tipo_movimiento' => 'REC', 'origen_tipo' => 'manual'], $usuario->id);
                    $antes = null;
                } else {
                    $this->repositorio->actualizar($alcance, $id, Entrada::entero($datos['version'] ?? '', 1) ?? 0, $valores, $usuario->id);
                    $antes = ServicioCatalogos::extraer($actual, array_keys($valores));
                }
                $this->auditoria->registrar($solicitud, $antes === null ? 'recepciones.crear' : 'recepciones.actualizar', 'exito', new RecursoAuditado('recepciones_lote', $id, (int) $valores['unidad_id']), [], $antes, $valores);
                $this->versiones->incrementar($alcance->centroId);
                return $id;
            });
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new ErrorValidacion(['numero_interno' => 'Ese número interno ya tiene una recepción de este producto. Usa el siguiente consecutivo.']);
            }
            throw $error;
        }
    }

    /**
     * Inserta una recepción ya validada dentro de una transacción abierta por otro caso de uso (la recepción del
     * derivado en el flujo de transformación, RF-048). No abre transacción propia.
     *
     * @param array<string,mixed> $datos
     * @return array{id:int,valores:array<string,scalar|null>}
     */
    public function insertarEnTransaccion(Solicitud $solicitud, array $datos): array
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $valores = $this->validar($solicitud, $alcance, $datos, null, 'transformaciones.gestionar');
        $this->periodos->exigirAbierto($alcance->centroId, (string) $valores['fecha_recepcion']);
        $id = $this->repositorio->insertar($valores + ['centro_id' => $alcance->centroId, 'tipo_movimiento' => 'REC', 'origen_tipo' => 'manual'], AutorizacionRecurso::usuario($solicitud)->id);
        $this->auditoria->registrar($solicitud, 'recepciones.crear', 'exito', new RecursoAuditado('recepciones_lote', $id, (int) $valores['unidad_id']), ['flujo' => 'transformacion'], null, $valores);
        return ['id' => $id, 'valores' => $valores];
    }

    public function archivar(Solicitud $solicitud, int $id, int $version, string $motivo): void
    {
        $actual = $this->obtener($solicitud, $id);
        $this->autorizacion->exigir($solicitud, 'recepciones.gestionar', new RecursoUnidad((int) $actual['centro_id'], (int) $actual['unidad_id']));
        $motivo = ServicioAsignaciones::motivo($motivo);
        if ((int) $actual['salidas'] > 0 || $this->transformacionDe($id) !== null) {
            throw new ErrorHttp(409, 'El lote tiene salidas o transformaciones activas; no puede archivarse.');
        }
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $this->bd->transaccion(function () use ($solicitud, $id, $version, $motivo, $actual, $usuario): void {
            $this->periodos->exigirAbierto((int) $actual['centro_id'], (string) $actual['fecha_recepcion']);
            $this->repositorio->archivar(AutorizacionRecurso::alcance($solicitud), $id, $version, $usuario->id, $motivo);
            $this->auditoria->registrar($solicitud, 'recepciones.archivar', 'exito', new RecursoAuditado('recepciones_lote', $id, (int) $actual['unidad_id']), [], ['numero_interno' => $actual['numero_interno'], 'cantidad' => $actual['cantidad']], null, $motivo);
            $this->versiones->incrementar((int) $actual['centro_id']);
        });
    }

    /** Fecha de la transformación que produjo este lote (derivado), si existe. */
    public function transformacionDe(int $recepcionId): ?string
    {
        $fecha = $this->bd->valor('SELECT fecha FROM transformaciones WHERE recepcion_derivado_id = :r AND archivado_en IS NULL', ['r' => $recepcionId]);
        return $fecha === null ? null : (string) $fecha;
    }

    /**
     * Validación común de la recepción. $permiso es el del caso de uso que la crea: recepciones.gestionar en el
     * registro directo, transformaciones.gestionar cuando nace en el flujo de transformación (RF-048).
     *
     * @param array<string,mixed> $datos
     * @param array<string,mixed>|null $actual
     * @return array<string,scalar|null>
     */
    private function validar(Solicitud $solicitud, AlcanceDatos $alcance, array $datos, ?array $actual, string $permiso = 'recepciones.gestionar'): array
    {
        $errores = [];
        $unidad = Entrada::entero($datos['unidad_id'] ?? '', 1);
        if ($unidad === null) {
            $errores['unidad_id'] = 'Elige la unidad que recibe.';
        } else {
            if ($permiso === 'recepciones.gestionar') {
                $this->autorizacion->exigir($solicitud, $permiso, new RecursoUnidad($alcance->centroId, $unidad));
            }
            if ((int) $this->bd->valor("SELECT COUNT(*) FROM unidades_productivas WHERE id = :u AND centro_id = :c AND activa = 1 AND codigo <> 'SIN'", ['u' => $unidad, 'c' => $alcance->centroId]) === 0) {
                $errores['unidad_id'] = 'Elige una unidad activa.';
            }
        }
        $fecha = Entrada::fecha($datos['fecha_recepcion'] ?? '');
        if ($fecha === null) {
            $errores['fecha_recepcion'] = 'Escribe la fecha de recepción (AAAA-MM-DD).';
        } elseif ($fecha > (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m-d')) {
            $errores['fecha_recepcion'] = 'La fecha de recepción no puede ser futura.';
        }
        $numero = strtoupper(trim((string) ($datos['numero_interno'] ?? '')));
        if (preg_match('/^[A-Z0-9]{2,8}-(REC|DEV)-\d{6}-\d{3,4}$/', $numero) !== 1) {
            $errores['numero_interno'] = 'El número interno tiene el formato GAN-REC-210126-001.';
        }
        $producto = Entrada::entero($datos['producto_id'] ?? '', 1);
        $filaProducto = $producto === null ? null : $this->bd->fila('SELECT id, medida FROM productos WHERE id = :p AND centro_id = :c AND archivado_en IS NULL', ['p' => $producto, 'c' => $alcance->centroId]);
        if ($filaProducto === null) {
            $errores['producto_id'] = 'Elige un producto del catálogo.';
        }
        $medida = trim((string) ($datos['medida'] ?? '')) === '' ? (string) ($filaProducto['medida'] ?? '') : (string) $datos['medida'];
        if (!in_array($medida, ['L', 'kg', 'ud', 'lb', 'cubeta'], true)) {
            $errores['medida'] = 'Elige la medida.';
        }
        $cantidad = Entrada::decimal($datos['cantidad'] ?? '', 3);
        if ($cantidad === null || Entrada::comparar($cantidad, '0.000') <= 0) {
            $errores['cantidad'] = 'Escribe una cantidad mayor que 0 (hasta 3 decimales).';
        }
        $precio = trim((string) ($datos['precio_sugerido'] ?? '')) === '' ? null : Entrada::decimal($datos['precio_sugerido']);
        if (trim((string) ($datos['precio_sugerido'] ?? '')) !== '' && $precio === null) {
            $errores['precio_sugerido'] = 'Escribe el precio en pesos, sin signo.';
        }
        $responsable = Entrada::entero($datos['responsable_id'] ?? '', 1);
        if ($responsable === null || (int) $this->bd->valor("SELECT COUNT(*) FROM usuarios WHERE id = :u AND centro_id = :c AND estado = 'activo'", ['u' => $responsable, 'c' => $alcance->centroId]) === 0) {
            $errores['responsable_id'] = 'Elige quién recibe (una cuenta activa del centro).';
        }
        if ($errores === [] && $filaProducto !== null && $cantidad !== null && $fecha !== null) {
            $existencias = $actual === null ? ['salidas' => 0, 'entregado' => '0.000', 'primera_salida' => null] : $this->resumenSalidas((int) $actual['id']);
            $errores = ReglasOperacion::recepcion(
                ['unidad_id' => $unidad, 'producto_id' => $producto, 'medida' => $medida, 'numero_interno' => $numero, 'fecha_recepcion' => $fecha, 'cantidad' => $cantidad],
                [
                    'medida_producto' => (string) $filaProducto['medida'],
                    'duplicado' => (int) $this->bd->valor(
                        'SELECT COUNT(*) FROM recepciones_lote WHERE centro_id = :c AND numero_interno = :n AND producto_id = :p AND archivado_en IS NULL AND id <> :id',
                        ['c' => $alcance->centroId, 'n' => $numero, 'p' => $producto, 'id' => $actual['id'] ?? 0]
                    ) > 0,
                    'salidas' => $existencias['salidas'],
                    'entregado' => $existencias['entregado'],
                    'primera_salida' => $existencias['primera_salida'],
                    'fecha_transformacion' => $actual === null ? null : $this->transformacionDe((int) $actual['id']),
                ],
                $actual
            );
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        return [
            'unidad_id' => $unidad,
            'numero_interno' => $numero,
            'fecha_recepcion' => $fecha,
            'producto_id' => $producto,
            'cantidad' => $cantidad,
            'medida' => $medida,
            'precio_sugerido' => $precio,
            'responsable_id' => $responsable,
        ];
    }

    /** @return array{salidas:int,entregado:string,primera_salida:?string} */
    private function resumenSalidas(int $recepcionId): array
    {
        $fila = $this->bd->fila(
            'SELECT COUNT(*) AS salidas, COALESCE(SUM(cantidad), 0) AS entregado, MIN(fecha) AS primera FROM salidas WHERE recepcion_id = :r AND archivado_en IS NULL',
            ['r' => $recepcionId]
        ) ?? [];
        return ['salidas' => (int) ($fila['salidas'] ?? 0), 'entregado' => (string) ($fila['entregado'] ?? '0'), 'primera_salida' => isset($fila['primera']) ? (string) $fila['primera'] : null];
    }

    /**
     * Agrega a cada lote: entregado, saldo, número de salidas, estado y fecha de cierre (RF-041), además del nombre
     * del producto. Una sola consulta agregada para toda la página.
     *
     * @param list<array<string,mixed>> $filas
     * @return list<array<string,mixed>>
     */
    private function conExistencias(array $filas): array
    {
        if ($filas === []) {
            return [];
        }
        $ids = array_map(static fn (array $f): int => (int) $f['id'], $filas);
        $marcadores = [];
        $parametros = [];
        foreach ($ids as $i => $id) {
            $marcadores[] = ":id{$i}";
            $parametros["id{$i}"] = $id;
        }
        $lista = implode(', ', $marcadores);
        $resumen = [];
        foreach ($this->bd->consultar("SELECT recepcion_id, COUNT(*) AS salidas, SUM(cantidad) AS entregado, MAX(fecha) AS ultima FROM salidas WHERE recepcion_id IN ({$lista}) AND archivado_en IS NULL GROUP BY recepcion_id", $parametros) as $r) {
            $resumen[(int) $r['recepcion_id']] = $r;
        }
        $productos = [];
        foreach ($this->bd->consultar("SELECT r.id, p.nombre, p.codigo_sgac, p.es_derivado_lacteo FROM recepciones_lote r JOIN productos p ON p.id = r.producto_id WHERE r.id IN ({$lista})", $parametros) as $p) {
            $productos[(int) $p['id']] = $p;
        }
        foreach ($filas as &$fila) {
            $r = $resumen[(int) $fila['id']] ?? null;
            $entregado = (string) ($r['entregado'] ?? '0.000');
            $saldo = Entrada::desdeUnidades(Entrada::aUnidades((string) $fila['cantidad'], 3) - Entrada::aUnidades($entregado, 3), 3);
            $fila['salidas'] = (int) ($r['salidas'] ?? 0);
            $fila['entregado'] = Entrada::desdeUnidades(Entrada::aUnidades($entregado, 3), 3);
            $fila['saldo'] = $saldo;
            $fila['estado_lote'] = Entrada::comparar(ltrim($saldo, '-'), '0.000') > 0 && !str_starts_with($saldo, '-') ? 'abierto' : 'cerrado';
            $fila['fecha_cierre'] = $fila['estado_lote'] === 'cerrado' ? ($r['ultima'] ?? null) : null;
            $fila['producto_nombre'] = (string) ($productos[(int) $fila['id']]['nombre'] ?? '');
            $fila['producto_codigo'] = (string) ($productos[(int) $fila['id']]['codigo_sgac'] ?? '');
        }
        return $filas;
    }
}
