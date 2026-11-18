<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;

/**
 * Tokens de un solo uso para la invitación (72 h) y la recuperación (30 min) de cuentas (RAC-006, RAC-007).
 * Token aleatorio de 256 bits en base64url; en la base solo se guarda su SHA-256. Emitir uno nuevo invalida los
 * anteriores del mismo tipo, y consumirlo es atómico (una sola actualización condicional).
 */
final class ServicioTokensCuenta
{
    /** @param array{recuperacion_minutos:int,invitacion_horas:int} $config */
    public function __construct(private readonly BaseDatos $bd, private readonly array $config)
    {
    }

    public function emitir(int $usuarioId, string $tipo): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $segundos = $tipo === 'invitacion' ? $this->config['invitacion_horas'] * 3600 : $this->config['recuperacion_minutos'] * 60;
        $this->bd->transaccion(function (BaseDatos $bd) use ($usuarioId, $tipo, $token, $segundos): void {
            $bd->ejecutar(
                'UPDATE tokens_cuenta SET usado_en = UTC_TIMESTAMP(3) WHERE usuario_id = :usuario AND tipo = :tipo AND usado_en IS NULL',
                ['usuario' => $usuarioId, 'tipo' => $tipo]
            );
            $bd->ejecutar(
                'INSERT INTO tokens_cuenta (usuario_id, tipo, hash_token, expira_en) VALUES (:usuario, :tipo, :hash, UTC_TIMESTAMP(3) + INTERVAL :segundos SECOND)',
                ['usuario' => $usuarioId, 'tipo' => $tipo, 'hash' => hash('sha256', $token), 'segundos' => $segundos]
            );
        });
        return $token;
    }

    /** Usuario dueño de un token vigente, sin consumirlo (para mostrar el formulario). */
    public function vigente(string $token, string $tipo): ?int
    {
        if (!self::formatoValido($token)) {
            return null;
        }
        $usuario = $this->bd->valor(
            'SELECT usuario_id FROM tokens_cuenta WHERE hash_token = :hash AND tipo = :tipo AND usado_en IS NULL AND expira_en > UTC_TIMESTAMP(3)',
            ['hash' => hash('sha256', $token), 'tipo' => $tipo]
        );
        return $usuario === null ? null : (int) $usuario;
    }

    /** Consume el token y devuelve su usuario; null si no existe, venció o ya se usó. */
    public function consumir(string $token, string $tipo): ?int
    {
        $usuario = $this->vigente($token, $tipo);
        if ($usuario === null) {
            return null;
        }
        $filas = $this->bd->ejecutar(
            'UPDATE tokens_cuenta SET usado_en = UTC_TIMESTAMP(3)
              WHERE hash_token = :hash AND tipo = :tipo AND usado_en IS NULL AND expira_en > UTC_TIMESTAMP(3)',
            ['hash' => hash('sha256', $token), 'tipo' => $tipo]
        );
        return $filas === 1 ? $usuario : null;
    }

    public static function formatoValido(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }
}
