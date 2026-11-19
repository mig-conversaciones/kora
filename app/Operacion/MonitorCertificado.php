<?php

declare(strict_types=1);

namespace App\Operacion;

/**
 * Vencimiento del certificado TLS del dominio (ROB-014): el programador lo consulta una vez al día y avisa con 15 días
 * de anticipación (SSL_AVISO_DIAS). Complementa, no reemplaza, el monitoreo externo (ROB-012), que también lo vigila.
 */
final class MonitorCertificado
{
    /** Días completos de vigencia que le quedan a un certificado PEM (negativo si ya venció); null si no se lee. */
    public static function diasRestantesDePem(string $pem, ?int $ahora = null): ?int
    {
        $datos = @openssl_x509_parse($pem);
        if (!is_array($datos) || !isset($datos['validTo_time_t'])) {
            return null;
        }
        return (int) floor(((int) $datos['validTo_time_t'] - ($ahora ?? time())) / 86400);
    }

    /** Consulta el certificado que presenta el servidor. Null si no hay conexión TLS (eso lo alerta el monitoreo externo). */
    public function diasRestantes(string $host, int $puerto = 443, float $espera = 10.0): ?int
    {
        $contexto = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $conexion = @stream_socket_client("ssl://{$host}:{$puerto}", $codigo, $mensaje, $espera, STREAM_CLIENT_CONNECT, $contexto);
        if ($conexion === false) {
            return null;
        }
        $parametros = stream_context_get_params($conexion);
        fclose($conexion);
        $certificado = $parametros['options']['ssl']['peer_certificate'] ?? null;
        if ($certificado === null || !openssl_x509_export($certificado, $pem)) {
            return null;
        }
        return self::diasRestantesDePem($pem);
    }

    /** ¿Hay que avisar? Sí con la vigencia en el umbral o por debajo (14 días con un umbral de 15, ROB-014). */
    public static function requiereAviso(?int $dias, int $umbral): bool
    {
        return $dias !== null && $dias <= $umbral;
    }
}
