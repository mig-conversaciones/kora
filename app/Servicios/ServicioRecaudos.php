<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;
use App\Politicas\RecursoUnidad;
use App\Repositorios\RepositorioRecaudos;
use PDOException;

/**
 * Recaudos de la Dirección General (RF-029, RF-034, RF-035).
 *   - Consulta filtrada en el servidor por fecha, unidad, producto, pasarela y estados, con paginación y orden por
 *     columnas permitidas. Un filtro de unidad fuera del alcance devuelve un resultado vacío, nunca datos ajenos.
 *   - Registro manual solo para el Grupo de Recaudo (PoliticaRecaudos). La identificación del pagador nunca se guarda
 *     en claro: HMAC-SHA256 y los últimos 4 dígitos (RF-034). La importación del archivo DG llega en la I5.
 */
final class ServicioRecaudos
{
    public const PASARELAS = ['Wompi', 'Ecollect', 'QR', 'Consignación', 'Otra'];
    public const TIPOS_INGRESO = ['Producción', 'Servicios', 'Otros ingresos'];
    public const ORDENES = ['-fecha' => 'Fecha (recientes primero)', 'fecha' => 'Fecha (antiguos primero)', '-valor' => 'Valor (mayor primero)', 'valor' => 'Valor (menor primero)'];

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly RepositorioRecaudos $repositorio,
        private readonly AutorizacionRecurso $autorizacion,
        private readonly ServicioPeriodos $periodos,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioVersionDatos $versiones,
    ) {
    }

    /**
     * @param array{desde:?string,hasta:?string,unidad:?int,producto:?int,pasarela:?string,clasificacion:?string,conciliacion:?string,orden:string} $filtros
     * @return array{filas:list<array<string,mixed>>,hayMas:bool}
     */
    public function listar(Solicitud $solicitud, array $filtros, int $pagina): array
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        if ($filtros['unidad'] !== null && !$alcance->permiteUnidad($filtros['unidad'])) {
            return ['filas' => [], 'hayMas' => false];
        }
        $condiciones = [];
        $parametros = [];
        foreach ([
            'desde' => 'rc.fecha_pago >= :f_desde',
            'hasta' => 'rc.fecha_pago <= :f_hasta',
            'unidad' => 'rc.unidad_id = :f_unidad',
            'producto' => 'rc.producto_id = :f_producto',
            'pasarela' => 'rc.pasarela = :f_pasarela',
            'clasificacion' => 'rc.estado_clasificacion = :f_clasificacion',
            'conciliacion' => 'rc.estado_conciliacion = :f_conciliacion',
        ] as $clave => $sql) {
            if ($filtros[$clave] !== null) {
                $condiciones[] = $sql;
                $parametros['f_' . $clave] = $filtros[$clave];
            }
        }
        $orden = isset(self::ORDENES[$filtros['orden']]) ? $filtros['orden'] : '-fecha';
        $resultado = $this->repositorio->listar($alcance, implode(' AND ', $condiciones), $parametros, $orden, $pagina);
        $productos = [];
        foreach ($this->bd->consultar('SELECT id, nombre FROM productos WHERE centro_id = :c', ['c' => $alcance->centroId]) as $p) {
            $productos[(int) $p['id']] = (string) $p['nombre'];
        }
        foreach ($resultado['filas'] as &$fila) {
            $fila['producto_nombre'] = $fila['producto_id'] === null ? null : ($productos[(int) $fila['producto_id']] ?? null);
            unset($fila['identificacion_hmac']);
        }
        return $resultado;
    }

    /** @param array<string,mixed> $datos */
    public function crear(Solicitud $solicitud, array $datos): int
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $errores = [];
        $fecha = Entrada::fecha($datos['fecha_pago'] ?? '');
        if ($fecha === null) {
            $errores['fecha_pago'] = 'Escribe la fecha de pago (AAAA-MM-DD).';
        }
        $valor = Entrada::decimal($datos['valor'] ?? '');
        if ($valor === null || Entrada::comparar($valor, '0.00') <= 0) {
            $errores['valor'] = 'Escribe el valor recaudado en pesos, mayor que 0.';
        }
        $unidad = trim((string) ($datos['unidad_id'] ?? '')) === '' ? null : Entrada::entero($datos['unidad_id'], 1);
        if ($unidad !== null && (int) $this->bd->valor('SELECT COUNT(*) FROM unidades_productivas WHERE id = :u AND centro_id = :c AND activa = 1', ['u' => $unidad, 'c' => $alcance->centroId]) === 0) {
            $errores['unidad_id'] = 'Elige una unidad activa o deja el recaudo sin clasificar.';
        }
        $producto = trim((string) ($datos['producto_id'] ?? '')) === '' ? null : Entrada::entero($datos['producto_id'], 1);
        if ($producto !== null && (int) $this->bd->valor('SELECT COUNT(*) FROM productos WHERE id = :p AND centro_id = :c AND archivado_en IS NULL', ['p' => $producto, 'c' => $alcance->centroId]) === 0) {
            $errores['producto_id'] = 'Elige un producto del catálogo.';
        }
        $pasarela = (string) ($datos['pasarela'] ?? '');
        if (!in_array($pasarela, self::PASARELAS, true)) {
            $errores['pasarela'] = 'Elige la pasarela o medio de pago.';
        }
        $tipo = (string) ($datos['tipo_ingreso'] ?? '');
        if (!in_array($tipo, self::TIPOS_INGRESO, true)) {
            $errores['tipo_ingreso'] = 'Elige el tipo de ingreso.';
        }
        $textos = [];
        foreach (['cupon_pago' => 60, 'prefactura' => 40, 'factura_electronica' => 40, 'concepto_fuente' => 200] as $campo => $maximo) {
            $texto = Entrada::texto($datos[$campo] ?? '', $maximo);
            if ($texto === null) {
                $errores[$campo] = "Máximo {$maximo} caracteres.";
            }
            $textos[$campo] = $texto === '' ? null : $texto;
        }
        $identificacion = preg_replace('/\D/', '', (string) ($datos['identificacion'] ?? '')) ?? '';
        if ($identificacion !== '' && (strlen($identificacion) < 5 || strlen($identificacion) > 15)) {
            $errores['identificacion'] = 'La identificación tiene entre 5 y 15 dígitos.';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        /** @var string $fecha */
        /** @var string $valor */
        $this->autorizacion->exigir($solicitud, 'recaudos.gestionar', $unidad === null ? null : new RecursoUnidad($alcance->centroId, $unidad));
        $valores = [
            'unidad_id' => $unidad,
            'producto_id' => $producto,
            'fecha_pago' => $fecha,
            'valor' => $valor,
            'pasarela' => $pasarela,
            'tipo_ingreso' => $tipo,
            'estado_clasificacion' => $unidad !== null && ($producto !== null || $textos['concepto_fuente'] !== null) ? 'clasificado' : 'pendiente',
        ] + $textos;
        $privados = [
            'identificacion_hmac' => $identificacion === '' ? null : $this->auditoria->hmacPii($identificacion),
            'identificacion_ultimos4' => $identificacion === '' ? null : substr($identificacion, -4),
            'clave_natural' => hash('sha256', implode('|', [$textos['cupon_pago'] ?? '', $textos['prefactura'] ?? '', $textos['factura_electronica'] ?? '', $valor, $fecha])),
        ];
        $usuario = AutorizacionRecurso::usuario($solicitud);
        try {
            return $this->bd->transaccion(function () use ($solicitud, $alcance, $valores, $privados, $fecha, $usuario): int {
                $this->periodos->exigirAbierto($alcance->centroId, $fecha);
                $id = $this->repositorio->insertar($valores + $privados + ['centro_id' => $alcance->centroId, 'origen_tipo' => 'manual'], $usuario->id);
                $this->auditoria->registrar($solicitud, 'recaudos.crear', 'exito', new RecursoAuditado('recaudos', $id, $valores['unidad_id']), [], null, $valores + ['identificacion_ultimos4' => $privados['identificacion_ultimos4']]);
                $this->versiones->incrementar($alcance->centroId);
                return $id;
            });
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new ErrorValidacion(['cupon_pago' => 'Ya existe un recaudo con el mismo cupón, prefactura, factura, valor y fecha.']);
            }
            throw $error;
        }
    }
}
