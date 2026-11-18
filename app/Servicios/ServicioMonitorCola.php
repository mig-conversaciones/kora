<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;

/**
 * Estado observable de la cola y de los procesos de cron (RF-Q-014, RF-Q-016, ROB-010, ROB-011). Lo usan
 * /health/ready, el programador (alertas) y cli/cola.php. Solo consultas agregadas sobre índices existentes.
 */
final class ServicioMonitorCola
{
    public const LATIDO_TRABAJADOR = 'trabajador';
    public const LATIDO_PROGRAMADOR = 'programador';

    public function __construct(private readonly BaseDatos $bd, private readonly string $directorioLatidos)
    {
    }

    /**
     * @return array{por_estado:array<string,int>,pendiente_mas_antiguo_segundos:int,fallidos_ultima_hora:int,duracion_media_ms:int|null,latido_trabajador_segundos:int,latido_programador_segundos:int}
     */
    public function estadisticas(): array
    {
        $porEstado = ['pendiente' => 0, 'reservado' => 0, 'completado' => 0, 'fallido' => 0, 'cancelado' => 0];
        foreach ($this->bd->consultar('SELECT estado, COUNT(*) AS total FROM trabajos GROUP BY estado') as $fila) {
            $porEstado[(string) $fila['estado']] = (int) $fila['total'];
        }
        $duracion = $this->bd->valor(
            "SELECT AVG(TIMESTAMPDIFF(MICROSECOND, iniciado_en, finalizado_en)) / 1000 FROM trabajos
              WHERE estado = 'completado' AND finalizado_en >= UTC_TIMESTAMP(3) - INTERVAL 1 DAY AND iniciado_en IS NOT NULL"
        );
        return [
            'por_estado' => $porEstado,
            'pendiente_mas_antiguo_segundos' => $this->pendienteMasAntiguo(),
            'fallidos_ultima_hora' => (int) $this->bd->valor('SELECT COUNT(*) FROM trabajos_fallidos WHERE fallido_en >= UTC_TIMESTAMP(3) - INTERVAL 1 HOUR'),
            'duracion_media_ms' => $duracion === null ? null : (int) round((float) $duracion),
            'latido_trabajador_segundos' => $this->edadLatido(self::LATIDO_TRABAJADOR),
            'latido_programador_segundos' => $this->edadLatido(self::LATIDO_PROGRAMADOR),
        ];
    }

    /** Antigüedad (s) del pendiente disponible más viejo; 0 si no hay. */
    public function pendienteMasAntiguo(): int
    {
        return (int) $this->bd->valor(
            "SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(disponible_en), UTC_TIMESTAMP(3)), 0)
               FROM trabajos WHERE estado = 'pendiente' AND disponible_en <= UTC_TIMESTAMP(3)"
        );
    }

    public function registrarLatido(string $proceso): void
    {
        if (!is_dir($this->directorioLatidos)) {
            mkdir($this->directorioLatidos, 0750, true);
        }
        file_put_contents($this->rutaLatido($proceso), gmdate('c'), LOCK_EX);
    }

    /** Segundos desde el último latido; PHP_INT_MAX si nunca hubo. */
    public function edadLatido(string $proceso): int
    {
        return ServicioMantenimiento::edadLatido($this->rutaLatido($proceso));
    }

    private function rutaLatido(string $proceso): string
    {
        return $this->directorioLatidos . '/' . preg_replace('/[^a-z_]/', '', $proceso) . '.txt';
    }
}
