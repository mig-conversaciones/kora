<?php

declare(strict_types=1);

namespace App\Integracion;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Firma de las solicitudes del software de ventas (anexo E; RF-095, RF-098):
 *
 *   X-Firma = base64(HMAC-SHA256(secreto_cliente, METODO + "\n" + ruta + "\n" + X-Fecha + "\n" + hex(SHA-256(cuerpo))))
 *
 * X-Fecha va en ISO 8601 UTC (2026-11-19T15:04:05Z) y se rechaza si difiere más de 5 min del reloj del servidor, para
 * impedir la repetición. X-Clave-Id identifica el secreto y permite rotarlo sin cortar el servicio.
 */
final class FirmaIntegracion
{
    public const TOLERANCIA_SEGUNDOS = 300;

    /** @param array<string,string> $secretos id de clave => secreto compartido */
    public function __construct(private readonly array $secretos)
    {
    }

    /** Formato del entorno: «cliente-2026a:SECRETO,cliente-2025b:ANTERIOR». */
    public static function desdeTexto(string $valor): self
    {
        $secretos = [];
        foreach (array_filter(array_map('trim', explode(',', $valor))) as $par) {
            [$id, $secreto] = array_pad(explode(':', $par, 2), 2, '');
            if ($id !== '' && strlen($secreto) >= 32) {
                $secretos[$id] = $secreto;
            }
        }
        return new self($secretos);
    }

    public static function firmar(string $secreto, string $metodo, string $ruta, string $fecha, string $cuerpo): string
    {
        $canonico = strtoupper($metodo) . "\n" . $ruta . "\n" . $fecha . "\n" . hash('sha256', $cuerpo);
        return base64_encode(hash_hmac('sha256', $canonico, $secreto, true));
    }

    /** @return string|null motivo del rechazo (sin detalles que ayuden a falsificar), o null si la firma es válida */
    public function verificar(string $metodo, string $ruta, ?string $claveId, ?string $fecha, ?string $firma, string $cuerpo, ?DateTimeImmutable $ahora = null): ?string
    {
        if ($claveId === null || $fecha === null || $firma === null || $claveId === '' || $fecha === '' || $firma === '') {
            return 'Faltan las cabeceras de autenticación (X-Clave-Id, X-Fecha y X-Firma).';
        }
        $secreto = $this->secretos[$claveId] ?? null;
        $momento = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $fecha, new DateTimeZone('UTC'));
        $ahora ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($momento === false || abs($ahora->getTimestamp() - $momento->getTimestamp()) > self::TOLERANCIA_SEGUNDOS) {
            return 'X-Fecha falta, no es ISO 8601 UTC o difiere más de 5 minutos del reloj del servidor.';
        }
        // Se compara siempre, también con una clave desconocida, para no revelar qué identificadores existen.
        $esperada = self::firmar($secreto ?? str_repeat("\0", 32), $metodo, $ruta, $fecha, $cuerpo);
        return $secreto !== null && hash_equals($esperada, $firma) ? null : 'La firma no es válida.';
    }
}
