<?php

declare(strict_types=1);

namespace App\Trabajos;

use App\Cola\ContextoTrabajo;
use App\Cola\ErrorDefinitivo;
use App\Cola\ManejadorTrabajo;
use App\Nucleo\BaseDatos;
use App\Nucleo\Cifrador;
use DateTimeImmutable;

/**
 * Exportación diaria del lote de auditoría (tipo auditoria.exportar_lote; RAU-005). Escribe los eventos sellados
 * del día (UTC) en JSON Lines, cifrados con el llavero del servidor (RS-013), junto con el último hash del lote:
 * si alguien manipula la cadena en la base y la recalcula, el lote guardado fuera lo delata. La copia al
 * almacenamiento externo la hace scripts/backup.sh (I7). Idempotente: si el lote ya existe, no lo reescribe.
 */
final class ExportarLoteAuditoria implements ManejadorTrabajo
{
    public function __construct(private readonly BaseDatos $bd, private readonly Cifrador $cifrador, private readonly string $directorio)
    {
    }

    public function ejecutar(array $trabajo, ContextoTrabajo $contexto): array
    {
        $fecha = (string) ($trabajo['carga']['fecha'] ?? '');
        if (DateTimeImmutable::createFromFormat('!Y-m-d', $fecha) === false) {
            throw new ErrorDefinitivo('La fecha del lote no es válida.');
        }
        if (!is_dir($this->directorio)) {
            mkdir($this->directorio, 0750, true);
        }
        $archivo = "{$this->directorio}/auditoria-{$fecha}.jsonl.cifrado";
        if (is_file($archivo)) {
            return ['archivo' => basename($archivo), 'reutilizado' => true];
        }
        $eventos = $this->bd->consultar(
            'SELECT * FROM eventos_auditoria
              WHERE ocurrido_en >= :desde AND ocurrido_en < :desde_mas_un_dia AND hash_evento IS NOT NULL
              ORDER BY secuencia_sello',
            ['desde' => "{$fecha} 00:00:00", 'desde_mas_un_dia' => (new DateTimeImmutable($fecha))->modify('+1 day')->format('Y-m-d 00:00:00')]
        );
        $lineas = array_map(static fn (array $e): string => json_encode($e, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $eventos);
        $ultimo = $eventos === [] ? null : (string) end($eventos)['hash_evento'];
        $contenido = json_encode(['fecha' => $fecha, 'eventos' => count($eventos), 'ultimo_hash' => $ultimo], JSON_THROW_ON_ERROR) . "\n" . implode("\n", $lineas);
        $temporal = $archivo . '.tmp';
        file_put_contents($temporal, $this->cifrador->cifrar($contenido, 'auditoria.lote:' . $fecha), LOCK_EX);
        rename($temporal, $archivo);
        @chmod($archivo, 0640);
        $contexto->latido(100, 'Lote de auditoría exportado.');
        return ['archivo' => basename($archivo), 'eventos' => count($eventos), 'ultimo_hash' => $ultimo, 'sha256_archivo' => hash_file('sha256', $archivo)];
    }
}
