<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;

/**
 * Códigos de respaldo de la 2FA (RAC-011): 10 códigos de un solo uso de 10 caracteres en base32, guardados como
 * HMAC-SHA256 con una clave del servidor (CLAVE_HMAC_CODIGOS). Se muestran una sola vez; regenerarlos invalida los
 * anteriores.
 */
final class ServicioCodigosRespaldo
{
    private const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly BaseDatos $bd, private readonly string $claveHmac, private readonly int $cantidad = 10)
    {
    }

    /** @return list<string> códigos en claro, con formato XXXXX-XXXXX, para mostrarse una sola vez */
    public function generar(int $usuarioId): array
    {
        $codigos = [];
        while (count($codigos) < $this->cantidad) {
            $codigo = '';
            foreach (str_split(random_bytes(10)) as $byte) {
                $codigo .= self::ALFABETO[ord($byte) % 32];
            }
            $codigos[$codigo] = true;
        }
        $codigos = array_keys($codigos);
        $this->bd->transaccion(function (BaseDatos $bd) use ($usuarioId, $codigos): void {
            $bd->ejecutar('DELETE FROM codigos_respaldo_2fa WHERE usuario_id = :usuario', ['usuario' => $usuarioId]);
            foreach ($codigos as $codigo) {
                $bd->ejecutar(
                    'INSERT INTO codigos_respaldo_2fa (usuario_id, hmac_codigo) VALUES (:usuario, :hmac)',
                    ['usuario' => $usuarioId, 'hmac' => $this->hmac($codigo)]
                );
            }
        });
        return array_map(static fn (string $c): string => substr($c, 0, 5) . '-' . substr($c, 5), $codigos);
    }

    /** Consume un código; true si era válido y no se había usado. */
    public function usar(int $usuarioId, string $codigo): bool
    {
        $limpio = strtoupper((string) preg_replace('/[\s-]/', '', $codigo));
        if (preg_match('/^[A-Z2-9]{10}$/', $limpio) !== 1) {
            return false;
        }
        return $this->bd->ejecutar(
            'UPDATE codigos_respaldo_2fa SET usado_en = UTC_TIMESTAMP(3)
              WHERE usuario_id = :usuario AND hmac_codigo = :hmac AND usado_en IS NULL',
            ['usuario' => $usuarioId, 'hmac' => $this->hmac($limpio)]
        ) === 1;
    }

    public function restantes(int $usuarioId): int
    {
        return (int) $this->bd->valor('SELECT COUNT(*) FROM codigos_respaldo_2fa WHERE usuario_id = :usuario AND usado_en IS NULL', ['usuario' => $usuarioId]);
    }

    public function eliminar(int $usuarioId): void
    {
        $this->bd->ejecutar('DELETE FROM codigos_respaldo_2fa WHERE usuario_id = :usuario', ['usuario' => $usuarioId]);
    }

    public static function pareceCodigo(string $texto): bool
    {
        return preg_match('/^[A-Za-z2-9]{5}-?[A-Za-z2-9]{5}$/', trim($texto)) === 1;
    }

    private function hmac(string $codigo): string
    {
        return hash_hmac('sha256', $codigo, $this->claveHmac);
    }
}
