<?php

declare(strict_types=1);

namespace App\Tableros;

/**
 * Formatos de cifras de los tableros, compartidos por las vistas, los gráficos SVG y las exportaciones, para que
 * pantalla, XLSX y PDF muestren exactamente lo mismo (RF-081). Un valor nulo es «—» (2.10: sin infinitos).
 */
final class Formato
{
    public const SIN_DATO = '—';

    public static function moneda(mixed $valor): string
    {
        if ($valor === null) {
            return self::SIN_DATO;
        }
        $numero = (float) $valor;
        return ($numero < 0 ? '−$ ' : '$ ') . number_format(abs($numero), 0, ',', '.');
    }

    /** Pesos abreviados para etiquetas de gráfico y tarjetas: 185 M, 3,27 M, 176 mil (oráculo de RF-063). */
    public static function monedaCorta(mixed $valor): string
    {
        if ($valor === null) {
            return self::SIN_DATO;
        }
        $numero = (float) $valor;
        $absoluto = abs($numero);
        $signo = $numero < 0 ? '−' : '';
        return match (true) {
            $absoluto >= 1_000_000_000 => $signo . self::compacto($absoluto / 1_000_000_000) . ' mil M',
            $absoluto >= 1_000_000 => $signo . self::compacto($absoluto / 1_000_000) . ' M',
            $absoluto >= 1_000 => $signo . self::compacto($absoluto / 1_000) . ' mil',
            default => $signo . number_format($absoluto, 0, ',', '.'),
        };
    }

    public static function porcentaje(?float $valor, int $decimales = 1): string
    {
        return $valor === null ? self::SIN_DATO : number_format($valor, $decimales, ',', '.') . ' %';
    }

    public static function decimal(?float $valor, int $decimales = 2): string
    {
        return $valor === null ? self::SIN_DATO : number_format($valor, $decimales, ',', '.');
    }

    /** Cantidad física sin ceros de relleno: «1234.500» → «1.234,5». */
    public static function cantidad(mixed $valor): string
    {
        if ($valor === null) {
            return self::SIN_DATO;
        }
        $texto = (string) $valor;
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $texto, $m) !== 1) {
            return $texto;
        }
        $fraccion = rtrim($m[3] ?? '', '0');
        return $m[1] . number_format((float) $m[2], 0, ',', '.') . ($fraccion === '' ? '' : ',' . $fraccion);
    }

    public static function fecha(?string $valor): string
    {
        return $valor !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $valor, $m) === 1 ? "{$m[3]}/{$m[2]}/{$m[1]}" : self::SIN_DATO;
    }

    /** Hasta 3 cifras significativas, sin ceros de relleno: 185 / 3,27 / 1,02. */
    private static function compacto(float $valor): string
    {
        $decimales = $valor >= 100 ? 0 : ($valor >= 10 ? 1 : 2);
        $texto = number_format($valor, $decimales, ',', '.');
        return str_contains($texto, ',') ? rtrim(rtrim($texto, '0'), ',') : $texto;
    }
}
