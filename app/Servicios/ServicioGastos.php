<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;
use App\Politicas\RecursoUnidad;
use App\Repositorios\RepositorioGastos;

/**
 * Gastos ejecutados (RF-025 a RF-028, Obs. 1 y 5):
 *   - El componente académico (vacas de práctica) no supera el valor.
 *   - Editan el líder de unidad, solo en sus unidades (otra unidad → 403), y la Coordinación administrativa.
 *   - Un mes cerrado no admite crear, editar ni archivar gastos con fecha en él (409 «Periodo cerrado»). Al editar
 *     se verifican la fecha anterior y la nueva.
 *   - El soporte (PDF, PNG o JPG ≤ 5 MB) se guarda en almacenamiento privado.
 */
final class ServicioGastos
{
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly RepositorioGastos $repositorio,
        private readonly AutorizacionRecurso $autorizacion,
        private readonly ServicioPeriodos $periodos,
        private readonly ServicioAdjuntos $adjuntos,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioVersionDatos $versiones,
    ) {
    }

    /**
     * @param array{unidad:?int,vigencia:int,mes:?int,categoria:?int} $filtros
     * @return array{filas:list<array<string,mixed>>,hayMas:bool}
     */
    public function listar(Solicitud $solicitud, array $filtros, int $pagina): array
    {
        [$desde, $hasta] = self::rango($filtros['vigencia'], $filtros['mes']);
        $sql = 'g.fecha >= :desde AND g.fecha < :hasta';
        $parametros = ['desde' => $desde, 'hasta' => $hasta];
        if ($filtros['unidad'] !== null) {
            $sql .= ' AND g.unidad_id = :unidad';
            $parametros['unidad'] = $filtros['unidad'];
        }
        if ($filtros['categoria'] !== null) {
            $sql .= ' AND g.categoria_id = :categoria';
            $parametros['categoria'] = $filtros['categoria'];
        }
        return $this->repositorio->listar(AutorizacionRecurso::alcance($solicitud), $sql, $parametros, '-fecha', $pagina);
    }

    /** @return array<string,mixed> */
    public function obtener(Solicitud $solicitud, int $id): array
    {
        $fila = $this->repositorio->obtener(AutorizacionRecurso::alcance($solicitud), $id);
        if ($fila['archivado_en'] !== null) {
            throw new ErrorHttp(404);
        }
        return $fila;
    }

    /**
     * @param array<string,mixed> $datos
     * @param array{nombre:string,ruta:string,tamano:int,error:int}|null $soporte
     */
    public function guardar(Solicitud $solicitud, ?int $id, array $datos, ?array $soporte = null): int
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $actual = $id === null ? null : $this->obtener($solicitud, $id);
        if ($actual !== null) {
            $this->autorizacion->exigir($solicitud, 'gastos.gestionar', new RecursoUnidad((int) $actual['centro_id'], (int) $actual['unidad_id']));
        }
        $errores = [];
        $unidad = Entrada::entero($datos['unidad_id'] ?? '', 1);
        if ($unidad === null) {
            $errores['unidad_id'] = 'Elige la unidad.';
        } else {
            $this->autorizacion->exigir($solicitud, 'gastos.gestionar', new RecursoUnidad($alcance->centroId, $unidad));
            if ((int) $this->bd->valor("SELECT COUNT(*) FROM unidades_productivas WHERE id = :u AND centro_id = :c AND activa = 1 AND codigo <> 'SIN'", ['u' => $unidad, 'c' => $alcance->centroId]) === 0) {
                $errores['unidad_id'] = 'Elige una unidad activa.';
            }
        }
        $fecha = Entrada::fecha($datos['fecha'] ?? '');
        $hoy = (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m-d');
        if ($fecha === null) {
            $errores['fecha'] = 'Indica la fecha del gasto.';
        } elseif ($fecha > $hoy) {
            $errores['fecha'] = 'La fecha del gasto no puede ser futura.';
        }
        $categoria = Entrada::entero($datos['categoria_id'] ?? '', 1);
        if ($categoria === null || (int) $this->bd->valor('SELECT COUNT(*) FROM categorias_gasto WHERE id = :id AND centro_id = :c AND activa = 1', ['id' => $categoria, 'c' => $alcance->centroId]) === 0) {
            $errores['categoria_id'] = 'Elige una categoría activa.';
        }
        $valor = Entrada::decimal($datos['valor'] ?? '');
        if ($valor === null || Entrada::comparar($valor, '0.00') <= 0) {
            $errores['valor'] = 'Escribe el valor del gasto en pesos, mayor que 0.';
        }
        $componente = trim((string) ($datos['valor_componente_academico'] ?? '')) === '' ? '0.00' : Entrada::decimal($datos['valor_componente_academico']);
        if ($componente === null) {
            $errores['valor_componente_academico'] = 'Escribe el componente académico en pesos, sin signo.';
        } elseif ($valor !== null && Entrada::comparar($componente, $valor) > 0) {
            $errores['valor_componente_academico'] = 'El componente académico no puede superar el valor del gasto.';
        }
        $descripcion = Entrada::texto($datos['descripcion'] ?? '', 2000);
        if ($descripcion === null) {
            $errores['descripcion'] = 'La descripción admite hasta 2.000 caracteres.';
        }
        $referencia = Entrada::texto($datos['referencia_soporte'] ?? '', 120);
        if ($referencia === null) {
            $errores['referencia_soporte'] = 'La referencia admite hasta 120 caracteres.';
        }
        if ($soporte !== null) {
            try {
                $this->adjuntos->validar($soporte);
            } catch (ErrorValidacion $error) {
                $errores += $error->errores();
            }
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        $this->periodos->exigirAbierto($alcance->centroId, (string) $fecha, (string) ($actual['fecha'] ?? ''));
        $valores = [
            'unidad_id' => $unidad,
            'fecha' => $fecha,
            'categoria_id' => $categoria,
            'valor' => $valor,
            'valor_componente_academico' => $componente,
            'descripcion' => $descripcion === '' ? null : $descripcion,
            'referencia_soporte' => $referencia === '' ? null : $referencia,
        ];
        $usuario = AutorizacionRecurso::usuario($solicitud);
        return $this->bd->transaccion(function () use ($solicitud, $alcance, $id, $actual, $valores, $datos, $usuario, $soporte): int {
            if ($actual === null) {
                $id = $this->repositorio->insertar($valores + ['centro_id' => $alcance->centroId, 'origen_tipo' => 'manual'], $usuario->id);
                $antes = null;
            } else {
                $this->repositorio->actualizar($alcance, $id, Entrada::entero($datos['version'] ?? '', 1) ?? 0, $valores, $usuario->id);
                $antes = ServicioCatalogos::extraer($actual, array_keys($valores));
            }
            $metadatos = [];
            if ($soporte !== null) {
                $metadatos['adjunto_id'] = $this->adjuntos->guardar($soporte, $alcance->centroId, (int) $valores['unidad_id'], 'gastos_ejecutados', $id, $usuario->id);
            }
            $this->auditoria->registrar($solicitud, $antes === null ? 'gastos.crear' : 'gastos.actualizar', 'exito', new RecursoAuditado('gastos_ejecutados', $id, (int) $valores['unidad_id']), $metadatos, $antes, $valores);
            $this->versiones->incrementar($alcance->centroId);
            return $id;
        });
    }

    public function archivar(Solicitud $solicitud, int $id, int $version, string $motivo): void
    {
        $actual = $this->obtener($solicitud, $id);
        $this->autorizacion->exigir($solicitud, 'gastos.gestionar', new RecursoUnidad((int) $actual['centro_id'], (int) $actual['unidad_id']));
        $motivo = ServicioAsignaciones::motivo($motivo);
        $this->periodos->exigirAbierto((int) $actual['centro_id'], (string) $actual['fecha']);
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $this->bd->transaccion(function () use ($solicitud, $id, $version, $motivo, $actual, $usuario): void {
            $this->repositorio->archivar(AutorizacionRecurso::alcance($solicitud), $id, $version, $usuario->id, $motivo);
            $this->auditoria->registrar($solicitud, 'gastos.archivar', 'exito', new RecursoAuditado('gastos_ejecutados', $id, (int) $actual['unidad_id']), [], ['valor' => $actual['valor'], 'fecha' => $actual['fecha']], null, $motivo);
            $this->versiones->incrementar((int) $actual['centro_id']);
        });
    }

    /**
     * Soporte descargable: solo si el gasto dueño está en el alcance del usuario; si no, 404 (RF-027).
     *
     * @return array{ruta:string,nombre:string,tipo:string}
     */
    public function soporte(Solicitud $solicitud, int $gastoId, int $adjuntoId): array
    {
        $this->repositorio->obtener(AutorizacionRecurso::alcance($solicitud), $gastoId);
        $adjunto = $this->adjuntos->obtener($adjuntoId);
        if ($adjunto === null || $adjunto['entidad'] !== 'gastos_ejecutados' || (int) $adjunto['entidad_id'] !== $gastoId) {
            throw new ErrorHttp(404);
        }
        $ruta = $this->adjuntos->rutaAbsoluta((string) $adjunto['ruta_privada']);
        if ($ruta === null) {
            throw new ErrorHttp(404);
        }
        $this->auditoria->registrar($solicitud, 'gastos.descargar_soporte', 'exito', new RecursoAuditado('archivos_adjuntos', $adjuntoId, $adjunto['unidad_id'] === null ? null : (int) $adjunto['unidad_id']));
        return ['ruta' => $ruta, 'nombre' => (string) $adjunto['nombre_original'], 'tipo' => (string) $adjunto['tipo_mime']];
    }

    /**
     * Ejecución por línea presupuestal (RF-026): asignación frente a lo ejecutado en el año, por unidad del alcance.
     * No corresponde a ejecución CDP/RP (esa la lleva el sistema financiero del SENA).
     *
     * @return list<array{unidad_id:int,unidad:string,asignacion:?array<string,mixed>,lineas:array<string,array{asignado:string,ejecutado:string}>,otras:string}>
     */
    public function ejecucion(Solicitud $solicitud, int $vigencia, ?int $unidad): array
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        [$condicionG, $parametrosG] = $alcance->condicionSql('g');
        [$condicionA, $parametrosA] = $alcance->condicionSql('a');
        [$desde, $hasta] = self::rango($vigencia, null);
        $filtroUnidad = $unidad === null ? '' : ' AND g.unidad_id = :unidad';
        $gastos = $this->bd->consultar(
            "SELECT g.unidad_id, c.linea_presupuestal, SUM(g.valor) AS ejecutado FROM gastos_ejecutados g
               JOIN categorias_gasto c ON c.id = g.categoria_id
              WHERE {$condicionG} AND g.archivado_en IS NULL AND g.fecha >= :desde AND g.fecha < :hasta{$filtroUnidad}
              GROUP BY g.unidad_id, c.linea_presupuestal",
            $parametrosG + ['desde' => $desde, 'hasta' => $hasta] + ($unidad === null ? [] : ['unidad' => $unidad])
        );
        $asignaciones = $this->bd->consultar(
            "SELECT a.* FROM asignaciones_presupuestales a WHERE {$condicionA} AND a.archivado_en IS NULL AND a.vigencia = :vigencia"
            . ($unidad === null ? '' : ' AND a.unidad_id = :unidad'),
            $parametrosA + ['vigencia' => $vigencia] + ($unidad === null ? [] : ['unidad' => $unidad])
        );
        $nombres = [];
        foreach ($this->bd->consultar('SELECT id, nombre FROM unidades_productivas WHERE centro_id = :c', ['c' => $alcance->centroId]) as $u) {
            $nombres[(int) $u['id']] = (string) $u['nombre'];
        }
        $resultado = [];
        $base = static fn (int $id) => ['unidad_id' => $id, 'unidad' => $nombres[$id] ?? (string) $id, 'asignacion' => null, 'lineas' => array_fill_keys(array_keys(ServicioAsignaciones::LINEAS), ['asignado' => '0', 'ejecutado' => '0']), 'otras' => '0'];
        foreach ($asignaciones as $a) {
            $id = (int) $a['unidad_id'];
            $resultado[$id] ??= $base($id);
            $resultado[$id]['asignacion'] = $a;
            foreach (array_keys(ServicioAsignaciones::LINEAS) as $linea) {
                $resultado[$id]['lineas'][$linea]['asignado'] = (string) $a[$linea];
            }
        }
        foreach ($gastos as $g) {
            $id = (int) $g['unidad_id'];
            $resultado[$id] ??= $base($id);
            $linea = (string) $g['linea_presupuestal'];
            if (isset($resultado[$id]['lineas'][$linea])) {
                $resultado[$id]['lineas'][$linea]['ejecutado'] = (string) $g['ejecutado'];
            } else {
                $resultado[$id]['otras'] = (string) $g['ejecutado'];
            }
        }
        uasort($resultado, static fn (array $x, array $y): int => strcmp($x['unidad'], $y['unidad']));
        return array_values($resultado);
    }

    /** @return array{0:string,1:string} [desde, hasta) del año o del mes */
    public static function rango(int $vigencia, ?int $mes): array
    {
        if ($mes === null) {
            return [sprintf('%04d-01-01', $vigencia), sprintf('%04d-01-01', $vigencia + 1)];
        }
        $siguiente = $mes === 12 ? [$vigencia + 1, 1] : [$vigencia, $mes + 1];
        return [sprintf('%04d-%02d-01', $vigencia, $mes), sprintf('%04d-%02d-01', $siguiente[0], $siguiente[1])];
    }
}
