<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Registro técnico estructurado en JSON, una línea por evento, con el X-Request-ID de la solicitud (RAU-008).
 * No es la auditoría funcional (eventos_auditoria, I2). Nunca registra secretos, contraseñas ni cuerpos completos.
 */
final class Registro
{
    private const NIVELES = ['depuracion', 'info', 'aviso', 'error', 'critico'];

    private static string $idSolicitud = '';

    public function __construct(private readonly string $directorio)
    {
    }

    public static function fijarIdSolicitud(string $id): void
    {
        self::$idSolicitud = $id;
    }

    /** @param array<string,mixed> $contexto */
    public function registrar(string $nivel, string $evento, array $contexto = []): void
    {
        if (!in_array($nivel, self::NIVELES, true)) {
            $nivel = 'info';
        }
        $linea = json_encode([
            'fecha' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'nivel' => $nivel,
            'evento' => $evento,
            'request_id' => self::$idSolicitud,
        ] + $contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_dir($this->directorio)) {
            @mkdir($this->directorio, 0750, true);
        }
        $archivo = $this->directorio . '/app-' . gmdate('Y-m-d') . '.log';
        if (@file_put_contents($archivo, $linea . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log((string) $linea);
        }
    }

    /** @param array<string,mixed> $contexto */
    public function info(string $evento, array $contexto = []): void
    {
        $this->registrar('info', $evento, $contexto);
    }

    /** @param array<string,mixed> $contexto */
    public function aviso(string $evento, array $contexto = []): void
    {
        $this->registrar('aviso', $evento, $contexto);
    }

    /** @param array<string,mixed> $contexto */
    public function error(string $evento, array $contexto = []): void
    {
        $this->registrar('error', $evento, $contexto);
    }
}
