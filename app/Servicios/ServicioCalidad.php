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

/**
 * Bandeja de calidad de datos (RF-058, RF-059, RF-061).
 *   - recalcular() detecta las incidencias con consultas de conjunto, las inserta sin duplicar (clave única por
 *     entidad y tipo) y marca «resuelta» la incidencia abierta cuya causa ya no existe. Es idempotente: el trabajo
 *     calidad.recalcular puede repetirse sin efectos dobles (RF-Q-010).
 *   - Las correcciones (vincular una salida a un lote, asignar un proceso) validan las mismas reglas del registro,
 *     quedan auditadas con el antes y el después e incrementan la versión de datos del centro (RF-061).
 */
final class ServicioCalidad
{
    public const TIPOS = [
        'salida_sin_recepcion' => 'Salida sin recepción vinculada',
        'autoconsumo_sin_proceso' => 'Autoconsumo sin proceso',
        'recaudo_sin_clasificar' => 'Recaudo sin unidad o sin producto',
        'concepto_sin_homologar' => 'Concepto sin homologar',
        'fecha_inconsistente' => 'Fecha inconsistente',
        'iep_diferente' => 'IEP o días abiertos importados distintos del cálculo',
        'derivado_sin_factor' => 'Derivado sin factor',
    ];

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly AutorizacionRecurso $autorizacion,
        private readonly ServicioPeriodos $periodos,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioVersionDatos $versiones,
    ) {
    }

    /**
     * Recalcula la bandeja del centro. Devuelve cuántas incidencias quedaron abiertas por tipo.
     *
     * @return array<string,int>
     */
    public function recalcular(int $centroId): array
    {
        $detecciones = [
            'salida_sin_recepcion' => [
                "SELECT s.id, s.unidad_id, CONCAT('Salida del ', DATE_FORMAT(s.fecha, '%d/%m/%Y'), ' (', COALESCE(s.numero_interno_texto, 'sin número'), ') sin recepción vinculada') AS descripcion
                   FROM salidas s WHERE s.centro_id = :c AND s.archivado_en IS NULL AND s.recepcion_id IS NULL",
                'salidas',
            ],
            'autoconsumo_sin_proceso' => [
                "SELECT s.id, s.unidad_id, CONCAT('Entrega de autoconsumo del ', DATE_FORMAT(s.fecha, '%d/%m/%Y'), ' por ', FORMAT(s.valor, 0, 'es_ES'), ' COP pendiente de vinculación a un proceso') AS descripcion
                   FROM salidas s WHERE s.centro_id = :c AND s.archivado_en IS NULL AND s.canal = 'AUTOCONSUMO' AND s.proceso_id IS NULL",
                'salidas',
            ],
            'recaudo_sin_clasificar' => [
                "SELECT rc.id, rc.unidad_id, CONCAT('Recaudo del ', DATE_FORMAT(rc.fecha_pago, '%d/%m/%Y'), ' por ', FORMAT(rc.valor, 0, 'es_ES'), ' COP ', IF(rc.unidad_id IS NULL, 'sin unidad', 'sin producto')) AS descripcion
                   FROM recaudos rc WHERE rc.centro_id = :c AND rc.archivado_en IS NULL AND (rc.unidad_id IS NULL OR (rc.producto_id IS NULL AND rc.concepto_fuente IS NULL))",
                'recaudos',
            ],
            'concepto_sin_homologar' => [
                "SELECT h.id, NULL AS unidad_id, CONCAT('El concepto «', h.texto_normalizado, '» no tiene producto del catálogo') AS descripcion
                   FROM homologaciones_producto h WHERE h.centro_id = :c AND h.producto_id IS NULL",
                'homologaciones_producto',
            ],
            'fecha_inconsistente' => [
                "SELECT s.id, s.unidad_id, CONCAT('La salida del ', DATE_FORMAT(s.fecha, '%d/%m/%Y'), ' es anterior a la recepción del lote ', r.numero_interno) AS descripcion
                   FROM salidas s JOIN recepciones_lote r ON r.id = s.recepcion_id
                  WHERE s.centro_id = :c AND s.archivado_en IS NULL AND s.fecha < r.fecha_recepcion",
                'salidas',
            ],
            'iep_diferente' => [
                "SELECT rr.id, rr.unidad_id, CONCAT('Animal ', a.identificador, ': el valor importado difiere del calculado en más de 1 día') AS descripcion
                   FROM registros_reproductivos rr
                   JOIN cortes_hato ch ON ch.id = rr.corte_id AND ch.vigente = 1
                   JOIN animales a ON a.id = rr.animal_id
                  WHERE rr.centro_id = :c
                    AND ((rr.iep_importado IS NOT NULL AND rr.iep_dias IS NOT NULL AND ABS(rr.iep_importado - rr.iep_dias) > " . ReglasHato::TOLERANCIA_DIAS . ')
                      OR (rr.dias_abiertos_importado IS NOT NULL AND rr.dias_abiertos IS NOT NULL AND ABS(rr.dias_abiertos_importado - rr.dias_abiertos) > ' . ReglasHato::TOLERANCIA_DIAS . '))',
                'registros_reproductivos',
            ],
            'derivado_sin_factor' => [
                "SELECT p.id, p.unidad_sugerida_id AS unidad_id, CONCAT('El derivado «', p.nombre, '» no tiene factor de leche') AS descripcion
                   FROM productos p WHERE p.centro_id = :c AND p.archivado_en IS NULL AND p.es_derivado_lacteo = 1 AND (p.factor_leche IS NULL OR p.factor_leche <= 0)",
                'productos',
            ],
        ];
        return $this->bd->transaccion(function (BaseDatos $bd) use ($centroId, $detecciones): array {
            $abiertas = [];
            foreach ($detecciones as $tipo => [$sql, $entidad]) {
                $vigentes = [];
                foreach ($bd->consultar($sql, ['c' => $centroId]) as $fila) {
                    $vigentes[] = (int) $fila['id'];
                    // Reabre la que se resolvió por corrección si la causa volvió; respeta la «aceptada sin corrección».
                    $bd->ejecutar(
                        "INSERT INTO incidencias_calidad (centro_id, unidad_id, tipo, entidad, entidad_id, descripcion)
                         VALUES (:c, :u, :t, :e, :id, :d)
                         ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion), unidad_id = VALUES(unidad_id),
                                estado = IF(estado = 'resuelta', 'abierta', estado), resolucion = IF(estado = 'abierta', NULL, resolucion)",
                        ['c' => $centroId, 'u' => $fila['unidad_id'], 't' => $tipo, 'e' => $entidad, 'id' => $fila['id'], 'd' => mb_substr((string) $fila['descripcion'], 0, 500)]
                    );
                }
                $parametros = ['c' => $centroId, 't' => $tipo];
                $excluir = '';
                if ($vigentes !== []) {
                    $excluir = ' AND entidad_id NOT IN (' . implode(',', $vigentes) . ')';
                }
                $bd->ejecutar(
                    "UPDATE incidencias_calidad SET estado = 'resuelta', resolucion = 'La causa ya no existe (recálculo).', resuelto_en = UTC_TIMESTAMP(3)
                      WHERE centro_id = :c AND tipo = :t AND estado = 'abierta'{$excluir}",
                    $parametros
                );
                $abiertas[$tipo] = (int) $bd->valor("SELECT COUNT(*) FROM incidencias_calidad WHERE centro_id = :c AND tipo = :t AND estado = 'abierta'", $parametros);
            }
            return $abiertas;
        });
    }

    /**
     * Bandeja filtrada dentro del alcance. Las incidencias sin unidad solo las ve quien tiene alcance de centro.
     *
     * @param array{tipo:?string,unidad:?int,estado:string} $filtros
     * @return array{filas:list<array<string,mixed>>,hayMas:bool,conteos:array<string,int>}
     */
    public function bandeja(Solicitud $solicitud, array $filtros, int $pagina): array
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        [$condicion, $parametros] = self::condicion($alcance);
        $condiciones = [$condicion, 'i.estado = :estado'];
        $parametros['estado'] = $filtros['estado'];
        if ($filtros['tipo'] !== null) {
            $condiciones[] = 'i.tipo = :tipo';
            $parametros['tipo'] = $filtros['tipo'];
        }
        if ($filtros['unidad'] !== null) {
            $condiciones[] = 'i.unidad_id = :unidad';
            $parametros['unidad'] = $filtros['unidad'];
        }
        $porPagina = 25;
        $filas = $this->bd->consultar(
            'SELECT i.* FROM incidencias_calidad i WHERE ' . implode(' AND ', $condiciones) . ' ORDER BY i.tipo, i.detectada_en DESC, i.id DESC LIMIT ' . ($porPagina + 1) . ' OFFSET ' . ((max(1, $pagina) - 1) * $porPagina),
            $parametros
        );
        $conteos = array_fill_keys(array_keys(self::TIPOS), 0);
        [$condicionConteo, $parametrosConteo] = self::condicion($alcance);
        foreach ($this->bd->consultar("SELECT i.tipo, COUNT(*) AS n FROM incidencias_calidad i WHERE {$condicionConteo} AND i.estado = 'abierta' GROUP BY i.tipo", $parametrosConteo) as $c) {
            $conteos[(string) $c['tipo']] = (int) $c['n'];
        }
        return ['filas' => array_slice($filas, 0, $porPagina), 'hayMas' => count($filas) > $porPagina, 'conteos' => $conteos];
    }

    /** @return array<string,mixed> incidencia dentro del alcance; 404 si no existe o es ajena */
    public function incidencia(Solicitud $solicitud, int $id): array
    {
        [$condicion, $parametros] = self::condicion(AutorizacionRecurso::alcance($solicitud));
        $fila = $this->bd->fila("SELECT i.* FROM incidencias_calidad i WHERE i.id = :id AND {$condicion}", $parametros + ['id' => $id]);
        if ($fila === null) {
            throw new ErrorHttp(404);
        }
        return $fila;
    }

    /**
     * Vincula una salida heredada sin recepción a un lote (RF-059): mismo centro y unidad, misma medida, fecha igual o
     * posterior a la recepción y saldo suficiente.
     */
    public function vincularRecepcion(Solicitud $solicitud, int $incidenciaId, int $recepcionId): void
    {
        $incidencia = $this->abiertaDeSalida($solicitud, $incidenciaId, 'salida_sin_recepcion');
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $incidencia, $recepcionId, $usuario): void {
            $salida = $this->salidaBloqueada($bd, $solicitud, (int) $incidencia['entidad_id']);
            if ($salida['recepcion_id'] !== null) {
                throw new ErrorHttp(409, 'La salida ya tiene recepción vinculada.');
            }
            $lote = $bd->fila(
                'SELECT r.id, r.unidad_id, r.medida, r.cantidad, r.fecha_recepcion, r.producto_id, r.numero_interno FROM recepciones_lote r
                  WHERE r.id = :id AND r.centro_id = :c AND r.archivado_en IS NULL FOR UPDATE',
                ['id' => $recepcionId, 'c' => $salida['centro_id']]
            );
            $errores = [];
            if ($lote === null || (int) $lote['unidad_id'] !== (int) $salida['unidad_id']) {
                $errores['recepcion_id'] = 'Elige un lote activo de la misma unidad que la salida.';
            } else {
                if ((string) $lote['medida'] !== (string) $salida['medida']) {
                    $errores['recepcion_id'] = 'La medida del lote no coincide con la de la salida.';
                } elseif ($salida['producto_id'] !== null && (int) $salida['producto_id'] !== (int) $lote['producto_id']) {
                    $errores['recepcion_id'] = 'El producto del lote no coincide con el de la salida.';
                } elseif ((string) $salida['fecha'] < (string) $lote['fecha_recepcion']) {
                    $errores['recepcion_id'] = 'La salida no puede ser anterior a la recepción.';
                } else {
                    $otras = (string) $bd->valor('SELECT COALESCE(SUM(cantidad), 0) FROM salidas WHERE recepcion_id = :r AND archivado_en IS NULL', ['r' => $lote['id']]);
                    if (Entrada::comparar(Entrada::sumarCon(3, $otras, (string) $salida['cantidad']), (string) $lote['cantidad']) > 0) {
                        $saldo = Entrada::desdeUnidades(max(0, Entrada::aUnidades((string) $lote['cantidad'], 3) - Entrada::aUnidades($otras, 3)), 3);
                        $errores['recepcion_id'] = 'La entrega supera el saldo del lote (' . ReglasOperacion::cantidadLegible($saldo) . ' ' . $lote['medida'] . ').';
                    }
                }
            }
            if ($errores !== []) {
                throw new ErrorValidacion($errores);
            }
            /** @var array<string,mixed> $lote */
            $this->periodos->exigirAbierto((int) $salida['centro_id'], (string) $salida['fecha']);
            $bd->ejecutar(
                'UPDATE salidas SET recepcion_id = :r, producto_id = COALESCE(producto_id, :p), version = version + 1, actualizado_por = :u WHERE id = :id',
                ['r' => $lote['id'], 'p' => $lote['producto_id'], 'u' => $usuario->id, 'id' => $salida['id']]
            );
            $this->resolver($bd, (int) $incidencia['id'], 'resuelta', 'Vinculada al lote ' . $lote['numero_interno'] . '.', $usuario->id);
            $this->auditoria->registrar($solicitud, 'calidad.vincular_recepcion', 'exito', new RecursoAuditado('salidas', (int) $salida['id'], (int) $salida['unidad_id']), ['incidencia' => (int) $incidencia['id']], ['recepcion_id' => null], ['recepcion_id' => (int) $lote['id']]);
            $this->versiones->incrementar((int) $salida['centro_id']);
        });
    }

    /** Asigna un proceso a una entrega de autoconsumo sin proceso (RF-059), con las reglas de RF-043. */
    public function asignarProceso(Solicitud $solicitud, int $incidenciaId, int $procesoId): void
    {
        $incidencia = $this->abiertaDeSalida($solicitud, $incidenciaId, 'autoconsumo_sin_proceso');
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $incidencia, $procesoId, $usuario): void {
            $salida = $this->salidaBloqueada($bd, $solicitud, (int) $incidencia['entidad_id']);
            $proceso = $bd->fila(
                'SELECT id, codigo, unidad_id, medida, vigencia, producto_id, producto_texto FROM procesos_autoconsumo WHERE id = :p AND centro_id = :c AND archivado_en IS NULL',
                ['p' => $procesoId, 'c' => $salida['centro_id']]
            );
            $producto = $salida['producto_id'] === null ? null : $bd->fila('SELECT codigo_sgac, nombre FROM productos WHERE id = :p', ['p' => $salida['producto_id']]);
            // Las reglas del proceso de RF-043, con la salida como «lote» (una heredada puede no tener recepción).
            $errores = array_intersect_key(ReglasOperacion::salida(
                ['fecha' => (string) $salida['fecha'], 'cantidad' => '0', 'canal' => 'AUTOCONSUMO'],
                [
                    'fecha_recepcion' => (string) $salida['fecha'],
                    'cantidad' => (string) $salida['cantidad'],
                    'unidad_id' => (int) $salida['unidad_id'],
                    'medida' => (string) $salida['medida'],
                    'producto_id' => (int) ($salida['producto_id'] ?? 0),
                    'producto_codigo' => (string) ($producto['codigo_sgac'] ?? ''),
                    'producto_nombre' => (string) ($producto['nombre'] ?? $salida['nombre_producto_fuente'] ?? ''),
                ],
                '0',
                $proceso === null ? null : [
                    'unidad_id' => (int) $proceso['unidad_id'],
                    'medida' => (string) $proceso['medida'],
                    'vigencia' => (int) $proceso['vigencia'],
                    'producto_id' => $proceso['producto_id'] === null ? null : (int) $proceso['producto_id'],
                    'producto_texto' => (string) $proceso['producto_texto'],
                ],
                ['litros' => '0', 'primera_fecha' => null, 'cantidad' => 0]
            ), ['proceso_id' => true]);
            if ($errores !== []) {
                throw new ErrorValidacion($errores);
            }
            /** @var array<string,mixed> $proceso */
            $this->periodos->exigirAbierto((int) $salida['centro_id'], (string) $salida['fecha']);
            $bd->ejecutar('UPDATE salidas SET proceso_id = :p, version = version + 1, actualizado_por = :u WHERE id = :id', ['p' => $proceso['id'], 'u' => $usuario->id, 'id' => $salida['id']]);
            $this->resolver($bd, (int) $incidencia['id'], 'resuelta', 'Vinculada al proceso ' . $proceso['codigo'] . '.', $usuario->id);
            $this->auditoria->registrar($solicitud, 'calidad.asignar_proceso', 'exito', new RecursoAuditado('salidas', (int) $salida['id'], (int) $salida['unidad_id']), ['incidencia' => (int) $incidencia['id']], ['proceso_id' => null], ['proceso_id' => (int) $proceso['id']]);
            $this->versiones->incrementar((int) $salida['centro_id']);
        });
    }

    /** «Aceptada sin corrección», con motivo (RF-059). Sigue visible en la auditoría y en el filtro «Aceptadas». */
    public function aceptar(Solicitud $solicitud, int $incidenciaId, string $motivo): void
    {
        $incidencia = $this->incidencia($solicitud, $incidenciaId);
        $this->exigirSobre($solicitud, $incidencia);
        if ($incidencia['estado'] !== 'abierta') {
            throw new ErrorHttp(409, 'La incidencia ya no está abierta.');
        }
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 10 || mb_strlen($motivo) > 500) {
            throw new ErrorValidacion(['motivo' => 'Explica por qué se acepta sin corrección (entre 10 y 500 caracteres).']);
        }
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $incidencia, $motivo, $usuario): void {
            $this->resolver($bd, (int) $incidencia['id'], 'aceptada', $motivo, $usuario->id);
            $this->auditoria->registrar($solicitud, 'calidad.aceptar', 'exito', new RecursoAuditado('incidencias_calidad', (int) $incidencia['id'], $incidencia['unidad_id'] === null ? null : (int) $incidencia['unidad_id']), ['tipo' => $incidencia['tipo']], ['estado' => 'abierta'], ['estado' => 'aceptada'], $motivo);
        });
    }

    /** @return array{0:string,1:array<string,int>} */
    private static function condicion(AlcanceDatos $alcance): array
    {
        if ($alcance->estaVacio()) {
            return ['1 = 0', []];
        }
        if ($alcance->todoElCentro) {
            return ['i.centro_id = :alcance_centro', ['alcance_centro' => $alcance->centroId]];
        }
        return $alcance->condicionSql('i');
    }

    /** @return array<string,mixed> */
    private function abiertaDeSalida(Solicitud $solicitud, int $id, string $tipo): array
    {
        $incidencia = $this->incidencia($solicitud, $id);
        if ($incidencia['tipo'] !== $tipo || $incidencia['entidad'] !== 'salidas') {
            throw new ErrorHttp(409, 'Esta acción no corresponde al tipo de la incidencia.');
        }
        if ($incidencia['estado'] !== 'abierta') {
            throw new ErrorHttp(409, 'La incidencia ya no está abierta.');
        }
        $this->exigirSobre($solicitud, $incidencia);
        return $incidencia;
    }

    /** @param array<string,mixed> $incidencia */
    private function exigirSobre(Solicitud $solicitud, array $incidencia): void
    {
        $centro = (int) $incidencia['centro_id'];
        $this->autorizacion->exigir($solicitud, 'calidad.gestionar', $incidencia['unidad_id'] === null ? null : new RecursoUnidad($centro, (int) $incidencia['unidad_id']));
    }

    /** @return array<string,mixed> */
    private function salidaBloqueada(BaseDatos $bd, Solicitud $solicitud, int $id): array
    {
        [$condicion, $parametros] = AutorizacionRecurso::alcance($solicitud)->condicionSql('s');
        $salida = $bd->fila("SELECT s.* FROM salidas s WHERE s.id = :id AND s.archivado_en IS NULL AND {$condicion} FOR UPDATE", $parametros + ['id' => $id]);
        if ($salida === null) {
            throw new ErrorHttp(404);
        }
        return $salida;
    }

    private function resolver(BaseDatos $bd, int $id, string $estado, string $resolucion, int $usuario): void
    {
        $bd->ejecutar(
            'UPDATE incidencias_calidad SET estado = :e, resolucion = :r, resuelto_por = :u, resuelto_en = UTC_TIMESTAMP(3) WHERE id = :id',
            ['e' => $estado, 'r' => mb_substr($resolucion, 0, 500), 'u' => $usuario, 'id' => $id]
        );
    }
}
