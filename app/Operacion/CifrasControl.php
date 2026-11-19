<?php

declare(strict_types=1);

namespace App\Operacion;

use App\Nucleo\BaseDatos;
use App\Nucleo\Registro;
use App\Servicios\ServicioAuditoria;

/**
 * Cifras de control de una base (RDL-005, RNF-PERF-005, RF-094): conteos y sumas por centro de las tablas de negocio,
 * la última migración y la verificación de la cadena de auditoría. Se comparan antes y después de restaurar una copia
 * y antes y después de una prueba de carga. Solo agregados: nunca datos personales.
 */
final class CifrasControl
{
    /** Tablas de negocio con su columna de centro y, si aplica, la columna sumada. */
    private const TABLAS = [
        'unidades_productivas' => ['centro' => 'centro_id', 'suma' => null],
        'usuarios' => ['centro' => 'centro_id', 'suma' => null],
        'productos' => ['centro' => 'centro_id', 'suma' => null],
        'asignaciones_presupuestales' => ['centro' => 'centro_id', 'suma' => 'valor_total'],
        'gastos_ejecutados' => ['centro' => 'centro_id', 'suma' => 'valor'],
        'procesos_autoconsumo' => ['centro' => 'centro_id', 'suma' => 'cupo_valor'],
        'recaudos' => ['centro' => 'centro_id', 'suma' => 'valor'],
        'recepciones_lote' => ['centro' => 'centro_id', 'suma' => 'cantidad'],
        'salidas' => ['centro' => 'centro_id', 'suma' => 'valor'],
        'transformaciones' => ['centro' => 'centro_id', 'suma' => 'litros_asignados'],
        'animales' => ['centro' => 'centro_id', 'suma' => null],
        'lotes_importacion' => ['centro' => 'centro_id', 'suma' => null],
    ];

    public function __construct(private readonly BaseDatos $bd)
    {
    }

    /**
     * @return array{
     *     migracion:?string,
     *     tablas:array<string,array<string,array{filas:int,suma:?string}>>,
     *     auditoria:array{eventos:int,sellados:int,cadena_integra:bool,detalle:string},
     *     huella:string
     * }
     */
    public function calcular(bool $verificarCadena = true): array
    {
        $tablas = [];
        foreach (self::TABLAS as $tabla => $definicion) {
            $suma = $definicion['suma'] === null ? 'NULL' : "COALESCE(SUM({$definicion['suma']}), 0)";
            $filas = $this->bd->consultar("SELECT {$definicion['centro']} AS centro, COUNT(*) AS filas, {$suma} AS suma FROM {$tabla} GROUP BY {$definicion['centro']} ORDER BY {$definicion['centro']}");
            foreach ($filas as $fila) {
                $tablas[$tabla][(string) $fila['centro']] = [
                    'filas' => (int) $fila['filas'],
                    'suma' => $fila['suma'] === null ? null : (string) $fila['suma'],
                ];
            }
            $tablas[$tabla] ??= [];
        }
        $eventos = (int) $this->bd->valor('SELECT COUNT(*) FROM eventos_auditoria');
        $sellados = (int) $this->bd->valor('SELECT COUNT(*) FROM eventos_auditoria WHERE hash_evento IS NOT NULL');
        $cadena = ['integra' => true, 'detalle' => 'Sin verificar.'];
        if ($verificarCadena) {
            $cadena = (new ServicioAuditoria($this->bd, new Registro(sys_get_temp_dir())))->verificarCadena(5000);
        }
        $resultado = [
            'migracion' => ($m = $this->bd->valor('SELECT MAX(nombre) FROM migraciones_aplicadas')) === null ? null : (string) $m,
            'tablas' => $tablas,
            'auditoria' => ['eventos' => $eventos, 'sellados' => $sellados, 'cadena_integra' => (bool) $cadena['integra'], 'detalle' => (string) $cadena['detalle']],
        ];
        $resultado['huella'] = hash('sha256', json_encode([$resultado['migracion'], $tablas, $eventos, $sellados], JSON_THROW_ON_ERROR));
        return $resultado;
    }

    /**
     * Diferencias legibles entre dos juegos de cifras (vacío si coinciden).
     *
     * @param array<string,mixed> $antes
     * @param array<string,mixed> $despues
     * @return list<string>
     */
    public static function diferencias(array $antes, array $despues): array
    {
        $diferencias = [];
        if (($antes['migracion'] ?? null) !== ($despues['migracion'] ?? null)) {
            $diferencias[] = sprintf('Migración: %s frente a %s.', $antes['migracion'] ?? '—', $despues['migracion'] ?? '—');
        }
        $tablas = array_unique(array_merge(array_keys($antes['tablas'] ?? []), array_keys($despues['tablas'] ?? [])));
        foreach ($tablas as $tabla) {
            $a = $antes['tablas'][$tabla] ?? [];
            $d = $despues['tablas'][$tabla] ?? [];
            foreach (array_unique(array_merge(array_keys($a), array_keys($d))) as $centro) {
                if (($a[$centro] ?? null) != ($d[$centro] ?? null)) {
                    $diferencias[] = sprintf(
                        '%s (centro %s): %s frente a %s.',
                        $tabla,
                        $centro,
                        json_encode($a[$centro] ?? null, JSON_THROW_ON_ERROR),
                        json_encode($d[$centro] ?? null, JSON_THROW_ON_ERROR)
                    );
                }
            }
        }
        foreach (['eventos', 'sellados'] as $campo) {
            if (($antes['auditoria'][$campo] ?? null) !== ($despues['auditoria'][$campo] ?? null)) {
                $diferencias[] = "Auditoría, {$campo}: " . ($antes['auditoria'][$campo] ?? '—') . ' frente a ' . ($despues['auditoria'][$campo] ?? '—') . '.';
            }
        }
        if (($despues['auditoria']['cadena_integra'] ?? false) !== true) {
            $diferencias[] = 'La cadena de auditoría no está íntegra: ' . ($despues['auditoria']['detalle'] ?? '') . '.';
        }
        return $diferencias;
    }
}
