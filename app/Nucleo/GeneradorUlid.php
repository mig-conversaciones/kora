<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * ULID (26 caracteres en base32 de Crockford): 48 bits de milisegundos y 80 bits aleatorios. Es el identificador
 * público de los recursos en las URL (RBD-004) y el identificador de los eventos de auditoría.
 */
final class GeneradorUlid
{
    private const ALFABETO = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generar(?int $milisegundos = null): string
    {
        $tiempo = $milisegundos ?? (int) floor(microtime(true) * 1000);
        $parteTiempo = '';
        for ($i = 0; $i < 10; $i++) {
            $parteTiempo = self::ALFABETO[$tiempo % 32] . $parteTiempo;
            $tiempo = intdiv($tiempo, 32);
        }
        $bytes = random_bytes(10);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $parteAleatoria = '';
        foreach (str_split($bits, 5) as $grupo) {
            $parteAleatoria .= self::ALFABETO[(int) bindec($grupo)];
        }
        return $parteTiempo . $parteAleatoria;
    }

    public static function esValido(string $ulid): bool
    {
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $ulid) === 1;
    }
}
