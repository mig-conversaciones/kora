<?php

declare(strict_types=1);

namespace App\Importacion\Fuentes;

use App\Importacion\ContextoImportacion;
use App\Importacion\FuenteImportacion;
use App\Importacion\Normalizador;
use App\Nucleo\Entrada;

/**
 * Fuente 8: gastos ejecutados (plantilla del sistema, G.4). La fuente no tiene identificador: la idempotencia la da el
 * hash del archivo (RF-089) y cada fila aplicada crea un gasto. La fecha debe estar en un periodo abierto (RF-028).
 */
final class FuenteGastos extends FuenteImportacion
{
    public function clave(): string
    {
        return 'gastos_ejecutados';
    }

    public function nombre(): string
    {
        return 'Gastos ejecutados (plantilla del sistema)';
    }

    public function permiso(): string
    {
        return 'gastos.importar';
    }

    public function patronHoja(): string
    {
        return '/^gastos/';
    }

    public function tabla(): string
    {
        return 'gastos_ejecutados';
    }

    public function campoSuma(): string
    {
        return 'valor';
    }

    public function columnas(): array
    {
        return [
            'unidad' => ['alias' => ['unidad codigo', 'unidad'], 'obligatoria' => true],
            'fecha' => ['alias' => ['fecha'], 'obligatoria' => true],
            'categoria' => ['alias' => ['categoria'], 'obligatoria' => true],
            'valor' => ['alias' => ['valor'], 'obligatoria' => true],
            'academico' => ['alias' => ['componente academico'], 'obligatoria' => false],
            'descripcion' => ['alias' => ['descripcion'], 'obligatoria' => false],
            'soporte' => ['alias' => ['referencia del soporte', 'referencia soporte'], 'obligatoria' => false],
        ];
    }

    public function camposComparables(): array
    {
        return [];
    }

    public function validar(array $valores, ContextoImportacion $contexto): ?array
    {
        if (Normalizador::texto($valores['unidad'] ?? null) === null && Normalizador::texto($valores['valor'] ?? null) === null) {
            return null;
        }
        $errores = [];
        $codigo = strtoupper((string) Normalizador::texto($valores['unidad'] ?? null));
        $unidad = $contexto->unidadId($codigo) ?? $contexto->unidadId(Normalizador::unidad($codigo));
        if ($unidad === null) {
            self::error($errores, 'unidad', 'La unidad no se reconoce (usa el código: GAN, AGR…).');
        }
        self::exigirUnidad($contexto, $unidad, 'unidad', $errores);
        $fecha = Normalizador::fecha($valores['fecha'] ?? null);
        if ($fecha === null) {
            self::error($errores, 'fecha', 'La fecha no es válida.');
        } elseif ((string) ($contexto->bd->valor(
            'SELECT estado FROM periodos_cierre WHERE centro_id = :c AND anio = :a AND mes = :m',
            ['c' => $contexto->centroId, 'a' => (int) substr($fecha, 0, 4), 'm' => (int) substr($fecha, 5, 2)]
        ) ?? '') === 'cerrado') {
            self::error($errores, 'fecha', 'La fecha cae en un periodo cerrado.');
        }
        $categoria = $contexto->bd->valor('SELECT id FROM categorias_gasto WHERE centro_id = :c AND codigo = :k AND activa = 1', ['c' => $contexto->centroId, 'k' => strtoupper((string) Normalizador::texto($valores['categoria'] ?? null))]);
        if ($categoria === null) {
            self::error($errores, 'categoria', 'La categoría debe ser MANTENIMIENTO, ALIMENTACION o MATERIALES_FORMACION.');
        }
        $valor = Normalizador::numero($valores['valor'] ?? null);
        if ($valor === null) {
            self::error($errores, 'valor', 'El valor no es un número mayor o igual que 0.');
        }
        $academico = Normalizador::numero($valores['academico'] ?? 0) ?? null;
        if ($academico === null) {
            self::error($errores, 'academico', 'El componente académico no es un número mayor o igual que 0.');
        } elseif ($valor !== null && Entrada::comparar($academico, $valor) > 0) {
            self::error($errores, 'academico', 'El costo académico no puede superar el gasto total.');
        }
        $descripcion = Normalizador::texto($valores['descripcion'] ?? null);
        $soporte = Normalizador::texto($valores['soporte'] ?? null);
        $datos = [
            'unidad_id' => $unidad, 'fecha' => $fecha, 'categoria_id' => $categoria === null ? null : (int) $categoria, 'valor' => $valor,
            'valor_componente_academico' => $academico ?? '0.00',
            'descripcion' => $descripcion === null ? null : mb_substr($descripcion, 0, 2000),
            'referencia_soporte' => $soporte === null ? null : mb_substr($soporte, 0, 120),
        ];
        return ['datos' => $datos, 'clave' => '', 'errores' => $errores, 'advertencias' => []];
    }

    public function actual(ContextoImportacion $contexto, array $datos): ?array
    {
        return null;
    }

    public function aplicar(ContextoImportacion $contexto, array $datos, ?array $actual, array $procedencia): int
    {
        return $this->insertarFila($contexto->bd, $datos + ['centro_id' => $contexto->centroId], $procedencia, $contexto->usuarioId);
    }
}
