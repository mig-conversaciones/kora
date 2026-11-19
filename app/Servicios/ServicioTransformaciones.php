<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;
use App\Politicas\RecursoUnidad;
use App\Repositorios\RepositorioTransformaciones;
use PDOException;

/**
 * Transformación láctea (RF-047 a RF-051, Obs. 6).
 *   - Entrada: una entrega de leche por autoconsumo, en litros, con litros sin asignar.
 *   - Salida: la recepción del producto derivado. Se vincula una existente sin transformación o se crea en el mismo
 *     flujo; la transformación y la recepción se guardan en una sola transacción, así que si falla una regla la
 *     recepción tampoco queda creada (RF-048).
 *   - El alcance va por la unidad de origen de la leche (columna unidad_id): el líder de Ganadería ve el resultado de
 *     sus transformaciones (derivado, cantidad, rendimiento) aunque la recepción del derivado sea de otra unidad,
 *     sin ver ningún otro dato de esa unidad (RF-051, RDI-007).
 */
final class ServicioTransformaciones
{
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly RepositorioTransformaciones $repositorio,
        private readonly ServicioRecepciones $recepciones,
        private readonly AutorizacionRecurso $autorizacion,
        private readonly ServicioPeriodos $periodos,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioVersionDatos $versiones,
    ) {
    }

    /**
     * Transformaciones del alcance con el resultado del derivado y su rendimiento (L por unidad).
     *
     * @return array{filas:list<array<string,mixed>>,hayMas:bool}
     */
    public function listar(Solicitud $solicitud, int $pagina): array
    {
        $resultado = $this->repositorio->listar(AutorizacionRecurso::alcance($solicitud), '', [], '-fecha', $pagina);
        if ($resultado['filas'] === []) {
            return $resultado;
        }
        $ids = implode(',', array_map(static fn (array $f): int => (int) $f['id'], $resultado['filas']));
        $detalle = [];
        // Solo los campos del resultado: producto, cantidad, medida y metas del derivado (RF-051).
        foreach ($this->bd->consultar(
            "SELECT t.id, r.numero_interno, r.cantidad AS cantidad_derivado, r.medida AS medida_derivado, r.unidad_id AS unidad_derivado,
                    p.nombre AS derivado, p.factor_leche, p.meta_rendimiento_min, p.meta_rendimiento_max, s.fecha AS fecha_entrega
               FROM transformaciones t
               JOIN recepciones_lote r ON r.id = t.recepcion_derivado_id
               JOIN productos p ON p.id = r.producto_id
               JOIN salidas s ON s.id = t.salida_leche_id
              WHERE t.id IN ({$ids})"
        ) as $d) {
            $detalle[(int) $d['id']] = $d;
        }
        foreach ($resultado['filas'] as &$fila) {
            $d = $detalle[(int) $fila['id']] ?? [];
            $fila += $d;
            $fila['rendimiento'] = self::rendimiento((string) $fila['litros_asignados'], (string) ($d['cantidad_derivado'] ?? '0'));
            $fila['en_meta'] = $fila['rendimiento'] === null || ($d['meta_rendimiento_max'] ?? null) === null ? null : (float) $fila['rendimiento'] <= (float) $d['meta_rendimiento_max'];
        }
        return $resultado;
    }

    /** Rendimiento por derivado = litros asignados / cantidad de derivado (RF-075), con 2 decimales. */
    public static function rendimiento(string $litros, string $cantidad): ?string
    {
        $divisor = Entrada::aUnidades($cantidad, 3);
        if ($divisor <= 0) {
            return null;
        }
        $centesimas = intdiv(Entrada::aUnidades($litros, 3) * 100 * 2 + $divisor, 2 * $divisor);
        return Entrada::desdeUnidades($centesimas, 2);
    }

    /**
     * Entregas de leche por autoconsumo con litros disponibles, dentro del alcance (RF-047).
     *
     * @return list<array<string,mixed>>
     */
    public function entregasDisponibles(Solicitud $solicitud): array
    {
        [$condicion, $parametros] = AutorizacionRecurso::alcance($solicitud)->condicionSql('s');
        return $this->bd->consultar(
            "SELECT s.id, s.fecha, s.cantidad, s.unidad_id, r.numero_interno,
                    s.cantidad - COALESCE((SELECT SUM(t.litros_asignados) FROM transformaciones t WHERE t.salida_leche_id = s.id AND t.archivado_en IS NULL), 0) AS disponible
               FROM salidas s
               JOIN productos p ON p.id = s.producto_id
               LEFT JOIN recepciones_lote r ON r.id = s.recepcion_id
              WHERE {$condicion} AND s.archivado_en IS NULL AND s.canal = 'AUTOCONSUMO' AND s.medida = 'L' AND p.codigo_sgac = :leche
             HAVING disponible > 0
              ORDER BY s.fecha DESC, s.id DESC LIMIT 200",
            $parametros + ['leche' => ReglasOperacion::CODIGO_LECHE]
        );
    }

    /**
     * Derivados lácteos configurados: marcados como derivados y con factor mayor que 0 (RF-047, RF-050).
     *
     * @return list<array<string,mixed>>
     */
    public function derivados(int $centroId): array
    {
        return $this->bd->consultar(
            'SELECT id, nombre, medida, factor_leche FROM productos WHERE centro_id = :c AND es_derivado_lacteo = 1 AND factor_leche > 0 AND archivado_en IS NULL ORDER BY nombre',
            ['c' => $centroId]
        );
    }

    /**
     * Recepciones de derivados sin transformación, para vincular una existente (RF-048). Solo los datos del lote.
     *
     * @return list<array<string,mixed>>
     */
    public function recepcionesLibres(int $centroId): array
    {
        return $this->bd->consultar(
            'SELECT r.id, r.numero_interno, r.fecha_recepcion, r.cantidad, r.medida, r.producto_id, p.nombre AS producto_nombre
               FROM recepciones_lote r JOIN productos p ON p.id = r.producto_id
              WHERE r.centro_id = :c AND r.archivado_en IS NULL AND p.es_derivado_lacteo = 1
                AND NOT EXISTS (SELECT 1 FROM transformaciones t WHERE t.recepcion_derivado_id = r.id)
              ORDER BY r.fecha_recepcion DESC LIMIT 200',
            ['c' => $centroId]
        );
    }

    /**
     * Registra la transformación y, si se pide, la recepción del derivado en la misma transacción.
     *
     * @param array<string,mixed> $datos
     */
    public function crear(Solicitud $solicitud, array $datos): int
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $errores = [];
        $salidaId = Entrada::entero($datos['salida_leche_id'] ?? '', 1);
        if ($salidaId === null) {
            $errores['salida_leche_id'] = 'Elige la entrega de leche de origen.';
        }
        $fecha = Entrada::fecha($datos['fecha'] ?? '');
        if ($fecha === null) {
            $errores['fecha'] = 'Escribe la fecha de la transformación (AAAA-MM-DD).';
        }
        $litros = Entrada::decimal($datos['litros_asignados'] ?? '', 3);
        if ($litros === null || Entrada::comparar($litros, '0.000') <= 0) {
            $errores['litros_asignados'] = 'Escribe los litros asignados, mayores que 0 (hasta 3 decimales).';
        }
        $modo = (string) ($datos['modo_recepcion'] ?? 'nueva');
        $derivadoId = Entrada::entero($datos['derivado_id'] ?? '', 1);
        $recepcionExistente = $modo === 'existente' ? Entrada::entero($datos['recepcion_derivado_id'] ?? '', 1) : null;
        if ($modo === 'existente' && $recepcionExistente === null) {
            $errores['recepcion_derivado_id'] = 'Elige la recepción del derivado.';
        }
        if ($modo === 'nueva' && $derivadoId === null) {
            $errores['derivado_id'] = 'Elige el producto derivado.';
        }
        if (!in_array($modo, ['nueva', 'existente'], true)) {
            $errores['modo_recepcion'] = 'Elige si la recepción del derivado es nueva o existente.';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        /** @var int $salidaId */
        /** @var string $fecha */
        /** @var string $litros */
        $usuario = AutorizacionRecurso::usuario($solicitud);
        try {
            return $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $alcance, $salidaId, $fecha, $litros, $modo, $derivadoId, $recepcionExistente, $datos, $usuario): int {
                [$condicion, $parametros] = $alcance->condicionSql('s');
                $leche = $bd->fila(
                    "SELECT s.id, s.centro_id, s.unidad_id, s.fecha, s.cantidad, s.canal, s.medida, p.codigo_sgac
                       FROM salidas s JOIN productos p ON p.id = s.producto_id
                      WHERE s.id = :id AND s.archivado_en IS NULL AND {$condicion} FOR UPDATE",
                    $parametros + ['id' => $salidaId]
                );
                if ($leche === null) {
                    throw new ErrorValidacion(['salida_leche_id' => 'Elige una entrega de leche activa de tus unidades.']);
                }
                $this->autorizacion->exigir($solicitud, 'transformaciones.gestionar', new RecursoUnidad((int) $leche['centro_id'], (int) $leche['unidad_id']));
                $this->periodos->exigirAbierto($alcance->centroId, $fecha);
                if ($modo === 'existente') {
                    $derivado = $bd->fila(
                        'SELECT r.id, r.fecha_recepcion, r.unidad_id FROM recepciones_lote r JOIN productos p ON p.id = r.producto_id
                          WHERE r.id = :id AND r.centro_id = :c AND r.archivado_en IS NULL AND p.es_derivado_lacteo = 1 FOR UPDATE',
                        ['id' => $recepcionExistente, 'c' => $alcance->centroId]
                    );
                    if ($derivado === null) {
                        throw new ErrorValidacion(['recepcion_derivado_id' => 'Elige una recepción activa de un derivado lácteo.']);
                    }
                    $recepcionId = (int) $derivado['id'];
                    $fechaDerivado = (string) $derivado['fecha_recepcion'];
                    $vinculada = (int) $bd->valor('SELECT COUNT(*) FROM transformaciones WHERE recepcion_derivado_id = :r', ['r' => $recepcionId]) > 0;
                } else {
                    if ((int) $bd->valor('SELECT COUNT(*) FROM productos WHERE id = :p AND centro_id = :c AND es_derivado_lacteo = 1 AND factor_leche > 0 AND archivado_en IS NULL', ['p' => $derivadoId, 'c' => $alcance->centroId]) === 0) {
                        throw new ErrorValidacion(['derivado_id' => 'Elige un derivado lácteo configurado (con factor).']);
                    }
                    $nueva = $this->recepcionDelFlujo($solicitud, [
                        'unidad_id' => $datos['unidad_receptora_id'] ?? '',
                        'numero_interno' => $datos['numero_interno'] ?? '',
                        'fecha_recepcion' => $fecha,
                        'producto_id' => (string) $derivadoId,
                        'cantidad' => $datos['cantidad_derivado'] ?? '',
                        'precio_sugerido' => $datos['precio_sugerido'] ?? '',
                        'responsable_id' => $datos['responsable_id'] ?? '',
                    ]);
                    $recepcionId = $nueva['id'];
                    $fechaDerivado = (string) $nueva['valores']['fecha_recepcion'];
                    $vinculada = false;
                }
                $asignado = (string) $bd->valor('SELECT COALESCE(SUM(litros_asignados), 0) FROM transformaciones WHERE salida_leche_id = :s AND archivado_en IS NULL', ['s' => $leche['id']]);
                $errores = ReglasOperacion::transformacion(
                    ['fecha' => $fecha, 'litros' => $litros, 'unidad_id' => (int) $leche['unidad_id']],
                    ['fecha' => (string) $leche['fecha'], 'cantidad' => (string) $leche['cantidad'], 'unidad_id' => (int) $leche['unidad_id'], 'canal' => (string) $leche['canal'], 'medida' => (string) $leche['medida'], 'producto_codigo' => (string) $leche['codigo_sgac']],
                    ['fecha_recepcion' => $fechaDerivado, 'vinculada' => $vinculada],
                    $asignado
                );
                if ($errores !== []) {
                    // La excepción revierte la transacción: la recepción creada en este flujo tampoco queda (RF-048).
                    throw new ErrorValidacion($errores);
                }
                $valores = [
                    'unidad_id' => (int) $leche['unidad_id'],
                    'fecha' => $fecha,
                    'salida_leche_id' => (int) $leche['id'],
                    'recepcion_derivado_id' => $recepcionId,
                    'litros_asignados' => $litros,
                ];
                $id = $this->repositorio->insertar($valores + ['centro_id' => $alcance->centroId, 'origen_tipo' => 'manual'], $usuario->id);
                $this->auditoria->registrar($solicitud, 'transformaciones.crear', 'exito', new RecursoAuditado('transformaciones', $id, (int) $leche['unidad_id']), ['recepcion' => $modo], null, $valores);
                $this->versiones->incrementar($alcance->centroId);
                return $id;
            });
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new ErrorValidacion(['recepcion_derivado_id' => 'El lote del derivado ya está vinculado a una transformación.']);
            }
            throw $error;
        }
    }

    /**
     * Recepción del derivado creada en el flujo. Sus errores se devuelven con los nombres de campo del formulario de
     * transformación.
     *
     * @param array<string,mixed> $datos
     * @return array{id:int,valores:array<string,scalar|null>}
     */
    private function recepcionDelFlujo(Solicitud $solicitud, array $datos): array
    {
        try {
            return $this->recepciones->insertarEnTransaccion($solicitud, $datos);
        } catch (ErrorValidacion $error) {
            $nombres = ['unidad_id' => 'unidad_receptora_id', 'cantidad' => 'cantidad_derivado', 'fecha_recepcion' => 'fecha', 'producto_id' => 'derivado_id', 'medida' => 'derivado_id'];
            $errores = [];
            foreach ($error->errores() as $campo => $mensaje) {
                $errores[$nombres[$campo] ?? $campo] = $mensaje;
            }
            throw new ErrorValidacion($errores);
        }
    }

    public function archivar(Solicitud $solicitud, int $id, int $version, string $motivo): void
    {
        $actual = $this->repositorio->obtener(AutorizacionRecurso::alcance($solicitud), $id);
        if ($actual['archivado_en'] !== null) {
            throw new ErrorHttp(404);
        }
        $this->autorizacion->exigir($solicitud, 'transformaciones.gestionar', new RecursoUnidad((int) $actual['centro_id'], (int) $actual['unidad_id']));
        $motivo = ServicioAsignaciones::motivo($motivo);
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $this->bd->transaccion(function () use ($solicitud, $id, $version, $motivo, $actual, $usuario): void {
            $this->periodos->exigirAbierto((int) $actual['centro_id'], (string) $actual['fecha']);
            $this->repositorio->archivar(AutorizacionRecurso::alcance($solicitud), $id, $version, $usuario->id, $motivo);
            $this->auditoria->registrar($solicitud, 'transformaciones.archivar', 'exito', new RecursoAuditado('transformaciones', $id, (int) $actual['unidad_id']), [], ['litros_asignados' => $actual['litros_asignados'], 'recepcion_derivado_id' => $actual['recepcion_derivado_id']], null, $motivo);
            $this->versiones->incrementar((int) $actual['centro_id']);
        });
    }
}
