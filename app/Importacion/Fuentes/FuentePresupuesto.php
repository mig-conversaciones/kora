<?php

declare(strict_types=1);

namespace App\Importacion\Fuentes;

use App\Importacion\ContextoImportacion;
use App\Importacion\FuenteImportacion;
use App\Importacion\Normalizador;
use App\Nucleo\Entrada;

/** Fuente 1: presupuesto («Ingresos - Gastos», G.1). Clave natural: unidad + vigencia. */
final class FuentePresupuesto extends FuenteImportacion
{
    public function clave(): string
    {
        return 'presupuesto';
    }

    public function nombre(): string
    {
        return 'Presupuesto (Ingresos - Gastos)';
    }

    public function permiso(): string
    {
        return 'asignaciones.importar';
    }

    public function patronHoja(): string
    {
        return '/^ingresos gastos/';
    }

    public function tabla(): string
    {
        return 'asignaciones_presupuestales';
    }

    /** @return list<string> */
    public function opcionesRequeridas(): array
    {
        return ['vigencia'];
    }

    public function campoSuma(): string
    {
        return 'valor_total';
    }

    public function columnas(): array
    {
        return [
            'id' => ['alias' => ['id'], 'obligatoria' => true],
            'dependencia' => ['alias' => ['codigo de dependencia'], 'obligatoria' => false],
            'valor_total' => ['alias' => ['valor total proyecto', 'valor total'], 'obligatoria' => true],
            'mantenimiento' => ['alias' => ['mantenimiento'], 'obligatoria' => true],
            'alimentacion' => ['alias' => ['alimentacion'], 'obligatoria' => true],
            'materiales_formacion' => ['alias' => ['materiales formacion', 'materiales de formacion'], 'obligatoria' => true],
            'numero_semovientes' => ['alias' => ['numero de semovientes', 'semovientes'], 'obligatoria' => false],
        ];
    }

    public function camposComparables(): array
    {
        return ['valor_total', 'mantenimiento', 'alimentacion', 'materiales_formacion', 'numero_semovientes'];
    }

    public function validar(array $valores, ContextoImportacion $contexto): ?array
    {
        $id = Normalizador::texto($valores['id'] ?? null);
        if ($id === null || stripos($id, 'up_') !== 0) {
            return null;
        }
        $errores = [];
        $codigo = Normalizador::unidad($id);
        $unidad = $contexto->unidadId($codigo);
        if ($unidad === null || $codigo === 'SIN') {
            self::error($errores, 'id', "La unidad «{$id}» no está en el catálogo de unidades.");
        }
        self::exigirUnidad($contexto, $unidad, 'id', $errores);
        $dependencia = Normalizador::texto($valores['dependencia'] ?? null);
        if ($dependencia !== null && Normalizador::soloDigitos($dependencia) !== '9124') {
            self::error($errores, 'dependencia', 'La dependencia no es el centro 9124.');
        }
        $datos = ['unidad_id' => $unidad, 'vigencia' => (int) $contexto->opcion('vigencia')];
        foreach (['valor_total', 'mantenimiento', 'alimentacion', 'materiales_formacion'] as $campo) {
            $numero = Normalizador::numero($valores[$campo] ?? 0) ?? null;
            if ($numero === null) {
                self::error($errores, $campo, 'Escribe un valor en pesos mayor o igual que 0.');
            }
            $datos[$campo] = $numero ?? '0.00';
        }
        $semovientes = trim((string) ($valores['numero_semovientes'] ?? '')) === '' ? null : Normalizador::entero($valores['numero_semovientes']);
        $datos['numero_semovientes'] = $semovientes;
        if (Entrada::comparar(Entrada::sumar($datos['mantenimiento'], $datos['alimentacion'], $datos['materiales_formacion']), $datos['valor_total']) > 0) {
            self::error($errores, 'valor_total', 'El desglose de gastos supera la asignación.');
        }
        return ['datos' => $datos, 'clave' => $codigo . '|' . $datos['vigencia'], 'errores' => $errores, 'advertencias' => []];
    }

    public function actual(ContextoImportacion $contexto, array $datos): ?array
    {
        return $contexto->bd->fila(
            'SELECT * FROM asignaciones_presupuestales WHERE centro_id = :c AND unidad_id = :u AND vigencia = :v AND archivado_en IS NULL',
            ['c' => $contexto->centroId, 'u' => $datos['unidad_id'], 'v' => $datos['vigencia']]
        );
    }

    public function aplicar(ContextoImportacion $contexto, array $datos, ?array $actual, array $procedencia): int
    {
        if ($actual === null) {
            return $this->insertarFila($contexto->bd, $datos + ['centro_id' => $contexto->centroId], $procedencia, $contexto->usuarioId);
        }
        $this->actualizarFila($contexto->bd, (int) $actual['id'], array_intersect_key($datos, array_flip($this->camposComparables())) + $procedencia, $contexto->usuarioId);
        return (int) $actual['id'];
    }
}
