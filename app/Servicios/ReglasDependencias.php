<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;

/**
 * Cuenta los registros activos que dependen de un maestro, para las reglas de bloqueo (regla `archiveBlocker` del
 * prototipo):
 *   - Una unidad no se inactiva con registros activos (RF-016).
 *   - La medida de un producto no cambia si tiene recepciones (RF-017).
 *   - Un proceso con entregas no cambia unidad, medida, producto ni vigencia (RF-036).
 *
 * Algunas tablas dependientes (recepciones_lote, salidas, transformaciones) llegan con la migración 010 en la I4.
 * Solo se consultan si existen, así que las reglas se activan solas al aplicar esa migración, sin cambiar este código.
 */
final class ReglasDependencias
{
    /** Registros activos por unidad: tabla => etiqueta en plural para el mensaje. */
    public const DEPENDIENTES_UNIDAD = [
        'asignaciones_presupuestales' => 'asignaciones',
        'gastos_ejecutados' => 'gastos',
        'procesos_autoconsumo' => 'procesos de autoconsumo',
        'recaudos' => 'recaudos',
        'recepciones_lote' => 'lotes recibidos',
        'salidas' => 'salidas',
        'transformaciones' => 'transformaciones',
    ];

    /** @var array<string,bool> */
    private array $existentes = [];

    public function __construct(private readonly BaseDatos $bd)
    {
    }

    public function existeTabla(string $tabla): bool
    {
        return $this->existentes[$tabla] ??= (int) $this->bd->valor(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla',
            ['tabla' => $tabla]
        ) > 0;
    }

    /**
     * Registros activos de la unidad, por tipo (solo los tipos con al menos uno).
     *
     * @return array<string,int> etiqueta => cantidad
     */
    public function activosDeUnidad(int $centroId, int $unidadId): array
    {
        $resultado = [];
        foreach (self::DEPENDIENTES_UNIDAD as $tabla => $etiqueta) {
            $cantidad = $this->contar($tabla, 'centro_id = :centro AND unidad_id = :unidad AND archivado_en IS NULL', ['centro' => $centroId, 'unidad' => $unidadId]);
            if ($cantidad > 0) {
                $resultado[$etiqueta] = $cantidad;
            }
        }
        return $resultado;
    }

    public function recepcionesDeProducto(int $productoId): int
    {
        return $this->contar('recepciones_lote', 'producto_id = :producto AND archivado_en IS NULL', ['producto' => $productoId]);
    }

    public function entregasDeProceso(int $procesoId): int
    {
        return $this->contar('salidas', 'proceso_id = :proceso AND archivado_en IS NULL', ['proceso' => $procesoId]);
    }

    /**
     * Lo entregado de un proceso (RF-037): solo las salidas de autoconsumo vinculadas al proceso, de su misma unidad
     * de origen y con su misma medida. Una entrega con medida incompatible no suma.
     *
     * @return array{cantidad:string,valor:string,entregas:int}
     */
    public function entregadoProceso(int $procesoId, int $unidadId, string $medida): array
    {
        if (!$this->existeTabla('salidas')) {
            return ['cantidad' => '0', 'valor' => '0', 'entregas' => 0];
        }
        $fila = $this->bd->fila(
            "SELECT COALESCE(SUM(cantidad), 0) AS cantidad, COALESCE(SUM(valor), 0) AS valor, COUNT(*) AS entregas FROM salidas
              WHERE proceso_id = :proceso AND unidad_id = :unidad AND medida = :medida AND canal = 'AUTOCONSUMO' AND archivado_en IS NULL",
            ['proceso' => $procesoId, 'unidad' => $unidadId, 'medida' => $medida]
        );
        return ['cantidad' => (string) ($fila['cantidad'] ?? '0'), 'valor' => (string) ($fila['valor'] ?? '0'), 'entregas' => (int) ($fila['entregas'] ?? 0)];
    }

    /**
     * La tabla y la condición son constantes del código, nunca entrada del usuario.
     *
     * @param array<string,int|string> $parametros
     */
    private function contar(string $tabla, string $condicion, array $parametros): int
    {
        if (!$this->existeTabla($tabla)) {
            return 0;
        }
        return (int) $this->bd->valor("SELECT COUNT(*) FROM {$tabla} WHERE {$condicion}", $parametros);
    }

    /**
     * «3 gastos y 1 proceso de autoconsumo» → mensaje de bloqueo.
     *
     * @param array<string,int> $activos
     */
    public static function describir(array $activos): string
    {
        $partes = [];
        foreach ($activos as $etiqueta => $cantidad) {
            $partes[] = "{$cantidad} {$etiqueta}";
        }
        $ultima = array_pop($partes);
        return $partes === [] ? (string) $ultima : implode(', ', $partes) . ' y ' . $ultima;
    }
}
