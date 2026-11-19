<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;

/**
 * Consulta de la auditoría (RAU-001): filtros por fecha, acción, resultado, actor y recurso, con paginación por
 * cursor sobre (ocurrido_en, id) para no degradarse con el volumen. La consulta es de solo lectura y no muestra datos
 * personales en claro: la IP llega tratada (/24 o /48) y los correos como HMAC.
 */
final class ServicioConsultaAuditoria
{
    public const RESULTADOS = ['exito' => 'Éxito', 'denegado' => 'Denegado', 'error' => 'Error', 'duplicado' => 'Duplicado', 'encolado' => 'Encolado'];

    public function __construct(private readonly BaseDatos $bd)
    {
    }

    /**
     * @param array<string,mixed> $consulta parámetros de la URL
     * @return array{filtros:array<string,string>,filas:list<array<string,mixed>>,siguiente:?string}
     */
    public function buscar(array $consulta, int $porPagina = 50): array
    {
        $hoy = gmdate('Y-m-d');
        $filtros = [
            'desde' => Entrada::fecha($consulta['desde'] ?? '') ?? gmdate('Y-m-d', time() - 7 * 86400),
            'hasta' => Entrada::fecha($consulta['hasta'] ?? '') ?? $hoy,
            'accion' => preg_replace('/[^a-z0-9_.]/', '', strtolower((string) ($consulta['accion'] ?? ''))) ?? '',
            'resultado' => isset(self::RESULTADOS[(string) ($consulta['resultado'] ?? '')]) ? (string) $consulta['resultado'] : '',
            'actor' => (string) (Entrada::entero($consulta['actor'] ?? '', 1) ?? ''),
            'recurso' => preg_replace('/[^a-z_]/', '', strtolower((string) ($consulta['recurso'] ?? ''))) ?? '',
        ];
        $condiciones = ['e.ocurrido_en >= :desde', 'e.ocurrido_en < :hasta + INTERVAL 1 DAY'];
        $parametros = ['desde' => $filtros['desde'], 'hasta' => $filtros['hasta']];
        if ($filtros['accion'] !== '') {
            $condiciones[] = 'e.accion LIKE :accion';
            $parametros['accion'] = addcslashes($filtros['accion'], '%_\\') . '%';
        }
        if ($filtros['resultado'] !== '') {
            $condiciones[] = 'e.resultado = :resultado';
            $parametros['resultado'] = $filtros['resultado'];
        }
        if ($filtros['actor'] !== '') {
            $condiciones[] = 'e.actor_id = :actor';
            $parametros['actor'] = (int) $filtros['actor'];
        }
        if ($filtros['recurso'] !== '') {
            $condiciones[] = 'e.recurso = :recurso';
            $parametros['recurso'] = $filtros['recurso'];
        }
        // Cursor «antes de»: ocurrido_en|id del último elemento de la página anterior.
        $cursor = (string) ($consulta['antes'] ?? '');
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3})\|(\d+)$/', $cursor, $m) === 1) {
            $condiciones[] = '(e.ocurrido_en < :cursor_fecha OR (e.ocurrido_en = :cursor_fecha2 AND e.id < :cursor_id))';
            $parametros += ['cursor_fecha' => $m[1], 'cursor_fecha2' => $m[1], 'cursor_id' => (int) $m[2]];
        }
        $filas = $this->bd->consultar(
            'SELECT e.id, e.evento_id, e.ocurrido_en, e.actor_id, e.actor_rol, e.accion, e.recurso, e.recurso_id, e.unidad_id, e.resultado, e.origen,
                    e.ip_tratada, e.motivo, e.valores_antes, e.valores_despues, e.metadatos, e.secuencia_sello, u.nombre AS actor_nombre
               FROM eventos_auditoria e LEFT JOIN usuarios u ON u.id = e.actor_id
              WHERE ' . implode(' AND ', $condiciones) . '
              ORDER BY e.ocurrido_en DESC, e.id DESC LIMIT ' . ($porPagina + 1),
            $parametros
        );
        $siguiente = null;
        if (count($filas) > $porPagina) {
            $filas = array_slice($filas, 0, $porPagina);
            $ultima = end($filas);
            $siguiente = $ultima['ocurrido_en'] . '|' . $ultima['id'];
        }
        return ['filtros' => $filtros, 'filas' => $filas, 'siguiente' => $siguiente];
    }

    /** @return array{pendientes:int,ultima_secuencia:int} estado del sellado (D-39) */
    public function estadoSellado(): array
    {
        return [
            'pendientes' => (int) $this->bd->valor('SELECT COUNT(*) FROM eventos_auditoria WHERE hash_evento IS NULL'),
            'ultima_secuencia' => (int) $this->bd->valor('SELECT ultima_secuencia FROM cadena_auditoria WHERE id = 1'),
        ];
    }
}
