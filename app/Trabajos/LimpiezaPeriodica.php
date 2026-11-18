<?php

declare(strict_types=1);

namespace App\Trabajos;

use App\Cola\ContextoTrabajo;
use App\Cola\ManejadorTrabajo;
use App\Nucleo\BaseDatos;
use App\Nucleo\Cache;

/**
 * Limpieza periódica (tipo mantenimiento.limpieza; RF-Q-015, RDL-010, RNF-PERF-012). La encola el programador:
 *   - «horaria»: claves de idempotencia, límites de frecuencia y sesiones vencidas.
 *   - «diaria»: además, trabajos completados (14 días) y fallidos (90 días), caché vencida o excedente y correos
 *     de desarrollo guardados en archivo.
 * Borra en lotes de 500 filas para no bloquear las tablas más de 1 s por lote. Es idempotente: repetirla no
 * tiene efectos adicionales.
 */
final class LimpiezaPeriodica implements ManejadorTrabajo
{
    private const LOTE = 500;

    /** @param array<string,mixed> $config config/cola.php */
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly Cache $cache,
        private readonly array $config,
        private readonly int $cacheMaximoBytes,
        private readonly string $directorioCorreos,
    ) {
    }

    public function ejecutar(array $trabajo, ContextoTrabajo $contexto): array
    {
        $alcance = (string) ($trabajo['carga']['alcance'] ?? 'horaria');
        $resultado = [
            'idempotencia' => $this->borrarPorLotes('DELETE FROM claves_idempotencia WHERE expira_en < UTC_TIMESTAMP(3)'),
            'limites_tasa' => $this->borrarPorLotes(
                'DELETE FROM limites_tasa WHERE actualizado_en < UTC_TIMESTAMP(3) - INTERVAL 1 DAY
                    AND (bloqueado_hasta IS NULL OR bloqueado_hasta < UTC_TIMESTAMP(3))'
            ),
            'sesiones' => $this->borrarPorLotes('DELETE FROM sesiones WHERE expira_absoluta_en < UTC_TIMESTAMP(3) - INTERVAL 1 DAY'),
        ];
        $contexto->latido(30, 'Registros de seguridad vencidos depurados.');
        if ($alcance === 'diaria') {
            $retencion = $this->config['retencion'];
            $resultado['trabajos_completados'] = $this->borrarPorLotes(
                "DELETE FROM trabajos WHERE estado IN ('completado', 'cancelado')
                    AND finalizado_en < UTC_TIMESTAMP(3) - INTERVAL {$this->entero($retencion['completados_dias'])} DAY"
            );
            $contexto->latido(55, 'Trabajos completados depurados.');
            // trabajos_fallidos referencia a trabajos: primero la copia, después el original.
            $resultado['trabajos_fallidos'] = $this->borrarPorLotes(
                "DELETE FROM trabajos_fallidos WHERE fallido_en < UTC_TIMESTAMP(3) - INTERVAL {$this->entero($retencion['fallidos_dias'])} DAY"
            );
            $this->borrarPorLotes(
                "DELETE t FROM trabajos t LEFT JOIN trabajos_fallidos f ON f.trabajo_id = t.id
                  WHERE t.estado = 'fallido' AND f.id IS NULL
                    AND t.finalizado_en < UTC_TIMESTAMP(3) - INTERVAL {$this->entero($retencion['fallidos_dias'])} DAY",
                false
            );
            $contexto->latido(75, 'Trabajos fallidos depurados.');
            $resultado['cache'] = $this->cache->limpiar($this->cacheMaximoBytes);
            $resultado['correos_archivo'] = $this->borrarArchivosAntiguos($this->directorioCorreos, '*.eml', $this->entero($retencion['correos_archivo_dias']));
        }
        return ['alcance' => $alcance] + $resultado;
    }

    /** Borra en lotes con LIMIT (MySQL no admite LIMIT en DELETE con JOIN; esos van sin lote). */
    private function borrarPorLotes(string $sql, bool $conLimite = true): int
    {
        if (!$conLimite) {
            return $this->bd->ejecutar($sql);
        }
        $total = 0;
        do {
            $filas = $this->bd->ejecutar($sql . ' LIMIT ' . self::LOTE);
            $total += $filas;
        } while ($filas === self::LOTE);
        return $total;
    }

    private function borrarArchivosAntiguos(string $directorio, string $patron, int $dias): int
    {
        $limite = time() - $dias * 86400;
        $borrados = 0;
        foreach (glob($directorio . '/' . $patron) ?: [] as $archivo) {
            if (filemtime($archivo) < $limite && @unlink($archivo)) {
                $borrados++;
            }
        }
        return $borrados;
    }

    private function entero(mixed $valor): int
    {
        return max(1, (int) $valor);
    }
}
