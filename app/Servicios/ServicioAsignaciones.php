<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;
use App\Politicas\RecursoUnidad;
use App\Repositorios\RepositorioAsignaciones;
use PDOException;

/**
 * Asignaciones presupuestales anuales por unidad (RF-024, Obs. 5):
 *   - El desglose (mantenimiento + alimentación + materiales de formación) no supera el valor total.
 *   - Hay una sola asignación activa por unidad y vigencia: lo valida el caso de uso con un mensaje por campo, y la
 *     columna generada activa_unica lo garantiza en la base ante dos guardados simultáneos.
 *   - Edita la Coordinación administrativa; el líder de unidad solo consulta las de sus unidades.
 */
final class ServicioAsignaciones
{
    public const LINEAS = ['mantenimiento' => 'Mantenimiento de maquinaria y equipo', 'alimentacion' => 'Alimentación de semovientes', 'materiales_formacion' => 'Materiales de formación'];

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly RepositorioAsignaciones $repositorio,
        private readonly AutorizacionRecurso $autorizacion,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioVersionDatos $versiones,
    ) {
    }

    /** @return array{filas:list<array<string,mixed>>,hayMas:bool} */
    public function listar(Solicitud $solicitud, int $vigencia, int $pagina): array
    {
        return $this->repositorio->listar(AutorizacionRecurso::alcance($solicitud), 'a.vigencia = :vigencia', ['vigencia' => $vigencia], 'unidad', $pagina, 50);
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

    /** @param array<string,mixed> $datos */
    public function guardar(Solicitud $solicitud, ?int $id, array $datos): int
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $actual = $id === null ? null : $this->obtener($solicitud, $id);
        if ($actual !== null) {
            $this->autorizacion->exigir($solicitud, 'asignaciones.gestionar', new RecursoUnidad((int) $actual['centro_id'], (int) $actual['unidad_id']));
        }
        $errores = [];
        $unidad = Entrada::entero($datos['unidad_id'] ?? '', 1);
        if ($unidad === null) {
            $errores['unidad_id'] = 'Elige la unidad.';
        } else {
            $this->autorizacion->exigir($solicitud, 'asignaciones.gestionar', new RecursoUnidad($alcance->centroId, $unidad));
            if ((int) $this->bd->valor("SELECT COUNT(*) FROM unidades_productivas WHERE id = :u AND centro_id = :c AND activa = 1 AND codigo <> 'SIN'", ['u' => $unidad, 'c' => $alcance->centroId]) === 0) {
                $errores['unidad_id'] = 'Elige una unidad activa.';
            }
        }
        $vigencia = Entrada::entero($datos['vigencia'] ?? '', 2020, 2100);
        if ($vigencia === null) {
            $errores['vigencia'] = 'Escribe el año de la vigencia (por ejemplo 2026).';
        }
        $montos = [];
        foreach (['valor_total' => 'el valor total'] + array_map(static fn (string $t): string => 'el valor de ' . mb_strtolower($t), self::LINEAS) as $campo => $texto) {
            $vacio = trim((string) ($datos[$campo] ?? '')) === '';
            $montos[$campo] = $vacio && $campo !== 'valor_total' ? '0.00' : Entrada::decimal($datos[$campo] ?? '');
            if ($montos[$campo] === null) {
                $errores[$campo] = "Escribe {$texto} en pesos, sin signo (por ejemplo 12.500.000).";
            }
        }
        if (!isset($errores['valor_total']) && !array_intersect_key($errores, self::LINEAS)) {
            $desglose = Entrada::sumar((string) $montos['mantenimiento'], (string) $montos['alimentacion'], (string) $montos['materiales_formacion']);
            if (Entrada::comparar($desglose, (string) $montos['valor_total']) > 0) {
                $errores['valor_total'] = 'La suma del desglose ($ ' . number_format((float) $desglose, 0, ',', '.') . ') supera el valor total.';
            }
        }
        $semovientes = trim((string) ($datos['numero_semovientes'] ?? '')) === '' ? null : Entrada::entero($datos['numero_semovientes'], 0, 100000);
        if ($semovientes === null && trim((string) ($datos['numero_semovientes'] ?? '')) !== '') {
            $errores['numero_semovientes'] = 'Escribe un número entero de semovientes.';
        }
        if ($errores === [] && $this->existeOtraActiva($alcance->centroId, (int) $unidad, (int) $vigencia, $id)) {
            $errores['vigencia'] = "La unidad ya tiene una asignación activa para {$vigencia}. Edítala o archívala primero.";
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        $valores = ['unidad_id' => $unidad, 'vigencia' => $vigencia] + $montos + ['numero_semovientes' => $semovientes];
        $usuario = AutorizacionRecurso::usuario($solicitud);
        try {
            return $this->bd->transaccion(function () use ($solicitud, $alcance, $id, $actual, $valores, $datos, $usuario): int {
                if ($actual === null) {
                    $id = $this->repositorio->insertar($valores + ['centro_id' => $alcance->centroId, 'origen_tipo' => 'manual'], $usuario->id);
                    $antes = null;
                } else {
                    $version = Entrada::entero($datos['version'] ?? '', 1) ?? 0;
                    $this->repositorio->actualizar($alcance, $id, $version, $valores, $usuario->id);
                    $antes = ServicioCatalogos::extraer($actual, array_keys($valores));
                }
                $this->auditoria->registrar($solicitud, $antes === null ? 'asignaciones.crear' : 'asignaciones.actualizar', 'exito', new RecursoAuditado('asignaciones_presupuestales', $id, (int) $valores['unidad_id']), [], $antes, $valores);
                $this->versiones->incrementar($alcance->centroId);
                return $id;
            });
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new ErrorValidacion(['vigencia' => 'La unidad ya tiene una asignación activa para esa vigencia.']);
            }
            throw $error;
        }
    }

    public function archivar(Solicitud $solicitud, int $id, int $version, string $motivo): void
    {
        $actual = $this->obtener($solicitud, $id);
        $this->autorizacion->exigir($solicitud, 'asignaciones.gestionar', new RecursoUnidad((int) $actual['centro_id'], (int) $actual['unidad_id']));
        $motivo = self::motivo($motivo);
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $this->bd->transaccion(function () use ($solicitud, $id, $version, $motivo, $actual, $usuario): void {
            $this->repositorio->archivar(AutorizacionRecurso::alcance($solicitud), $id, $version, $usuario->id, $motivo);
            $this->auditoria->registrar($solicitud, 'asignaciones.archivar', 'exito', new RecursoAuditado('asignaciones_presupuestales', $id, (int) $actual['unidad_id']), [], ['valor_total' => $actual['valor_total'], 'vigencia' => $actual['vigencia']], null, $motivo);
            $this->versiones->incrementar((int) $actual['centro_id']);
        });
    }

    private function existeOtraActiva(int $centro, int $unidad, int $vigencia, ?int $id): bool
    {
        return (int) $this->bd->valor(
            'SELECT COUNT(*) FROM asignaciones_presupuestales WHERE centro_id = :c AND unidad_id = :u AND vigencia = :v AND archivado_en IS NULL AND id <> :id',
            ['c' => $centro, 'u' => $unidad, 'v' => $vigencia, 'id' => $id ?? 0]
        ) > 0;
    }

    public static function motivo(string $motivo): string
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 500) {
            throw new ErrorValidacion(['motivo' => 'Indica el motivo del archivo (entre 5 y 500 caracteres).']);
        }
        return $motivo;
    }
}
