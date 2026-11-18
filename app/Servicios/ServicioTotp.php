<?php

declare(strict_types=1);

namespace App\Servicios;

use InvalidArgumentException;

/**
 * TOTP según RFC 6238 (SHA-1, 6 dígitos, pasos de 30 s, ventana ±1) implementado sin dependencias (D-37).
 * Vector de prueba (RFC 6238, anexo B): clave ASCII "12345678901234567890", T = 59 s → 287082 (6 dígitos).
 * La anti-reutilización se apoya en el último paso aceptado guardado por usuario (RAC-012).
 */
final class ServicioTotp
{
    private const PASO_SEGUNDOS = 30;
    private const DIGITOS = 6;
    private const VENTANA = 1;
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Semilla de 160 bits en Base32 (RFC 4648). Se cifra antes de guardarla (RS-013). */
    public function generarSemilla(): string
    {
        return self::aBase32(random_bytes(20));
    }

    public function uriAprovisionamiento(string $semilla, string $cuenta, string $emisor = 'KORA'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($emisor),
            rawurlencode($cuenta),
            $semilla,
            rawurlencode($emisor),
            self::DIGITOS,
            self::PASO_SEGUNDOS
        );
    }

    /** Devuelve el paso aceptado o null. Rechaza pasos iguales o anteriores al último usado. */
    public function verificar(string $semilla, string $codigo, ?int $ultimoPaso, ?int $ahora = null): ?int
    {
        if (preg_match('/^\d{6}$/', $codigo) !== 1) {
            return null;
        }
        $clave = self::desdeBase32($semilla);
        $pasoActual = intdiv($ahora ?? time(), self::PASO_SEGUNDOS);
        for ($desfase = -self::VENTANA; $desfase <= self::VENTANA; $desfase++) {
            $paso = $pasoActual + $desfase;
            if ($ultimoPaso !== null && $paso <= $ultimoPaso) {
                continue;
            }
            if (hash_equals(self::codigoParaPaso($clave, $paso), $codigo)) {
                return $paso;
            }
        }
        return null;
    }

    public static function codigoParaPaso(string $clave, int $paso): string
    {
        $hmac = hash_hmac('sha1', pack('J', $paso), $clave, true);
        $desplazamiento = ord($hmac[19]) & 0x0f;
        $binario = ((ord($hmac[$desplazamiento]) & 0x7f) << 24)
            | ((ord($hmac[$desplazamiento + 1]) & 0xff) << 16)
            | ((ord($hmac[$desplazamiento + 2]) & 0xff) << 8)
            | (ord($hmac[$desplazamiento + 3]) & 0xff);
        return str_pad((string) ($binario % (10 ** self::DIGITOS)), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    private static function aBase32(string $binario): string
    {
        $bits = '';
        foreach (str_split($binario) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $salida = '';
        foreach (str_split($bits, 5) as $grupo) {
            $salida .= self::ALFABETO[(int) bindec(str_pad($grupo, 5, '0'))];
        }
        return $salida;
    }

    private static function desdeBase32(string $texto): string
    {
        $limpio = strtoupper((string) preg_replace('/[\s=]/', '', $texto));
        $bits = '';
        foreach (str_split($limpio) as $caracter) {
            $valor = strpos(self::ALFABETO, $caracter);
            if ($valor === false) {
                throw new InvalidArgumentException('La semilla TOTP no es Base32 válida.');
            }
            $bits .= str_pad(decbin($valor), 5, '0', STR_PAD_LEFT);
        }
        $binario = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binario .= chr((int) bindec($byte));
            }
        }
        return $binario;
    }
}
