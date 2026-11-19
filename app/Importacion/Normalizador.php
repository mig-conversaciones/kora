<?php

declare(strict_types=1);

namespace App\Importacion;

use App\Nucleo\Entrada;
use App\Servicios\ServicioCatalogos;

/**
 * Normalización de los valores de las fuentes Excel (Anexo G, RF-086), con las reglas del extractor del prototipo:
 * unidades `UP_*`, medidas, fechas (seriales con época 1899-12-30 o texto), números y textos en NFC.
 */
final class Normalizador
{
    /** Nombre normalizado de la unidad (sin tildes, minúsculas, sin «up_») → código. */
    private const UNIDADES = [
        'ganaderia' => 'GAN', 'granja' => 'GRA', 'apicultura' => 'API', 'agricola' => 'AGR', 'hortalizas' => 'HOR',
        'invernadero' => 'INV', 'agroindustria' => 'IND', 'gourmet' => 'GOU', 'gourmet cafe' => 'GOU', 'cafe' => 'GOU',
        'cafeteria aprendices' => 'CAF', 'cafeteria' => 'CAF', 'lamfa' => 'LAM', 'comercio' => 'COM',
    ];

    private const MEDIDAS = [
        'l' => 'L', 'lt' => 'L', 'litro' => 'L', 'litros' => 'L', 'kg' => 'kg', 'kilo' => 'kg', 'kilos' => 'kg',
        'kilogramo' => 'kg', 'kilogramos' => 'kg', 'lb' => 'lb', 'libra' => 'lb', 'libras' => 'lb', 'ud' => 'ud',
        'unidad' => 'ud', 'unidades' => 'ud', 'und' => 'ud', 'cubeta' => 'cubeta', 'cubeta 30u' => 'cubeta', 'cubetas' => 'cubeta',
        // Presentaciones que se venden por pieza (Base Ventas): se registran como unidad, con advertencia.
        'manojo' => 'ud', 'manojo 250 gr' => 'ud', 'panal' => 'ud',
    ];

    /** Medidas que se registran como unidad aunque la fuente use otro nombre (se advierte al importar). */
    public const PRESENTACIONES = ['manojo', 'manojo 250 gr', 'panal'];

    /** Encabezado comparable: sin tildes, minúsculas, solo letras, cifras y espacios simples. */
    public static function encabezado(mixed $valor): string
    {
        $texto = ServicioCatalogos::normalizarConcepto((string) $valor);
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9#]+/', ' ', $texto)));
    }

    public static function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $texto = trim((string) preg_replace('/\s+/u', ' ', (string) $valor));
        $texto = \Normalizer::normalize($texto, \Normalizer::FORM_C) ?: $texto;
        return $texto === '' ? null : $texto;
    }

    /**
     * Código de unidad a partir de «UP_Ganaderia», «up_Agricola» o «Ganadería». Null si está vacía; «SIN» si no se
     * reconoce; «AMBIGUA» si nombra varias unidades («Ganadería y Granja»).
     */
    public static function unidad(mixed $valor): ?string
    {
        $texto = self::texto($valor);
        if ($texto === null) {
            return null;
        }
        $clave = ServicioCatalogos::normalizarConcepto((string) preg_replace('/^up_/i', '', $texto));
        $clave = trim((string) preg_replace('/[^a-z ]+/', ' ', $clave));
        $clave = (string) preg_replace('/\s+/', ' ', $clave);
        if (isset(self::UNIDADES[$clave])) {
            return self::UNIDADES[$clave];
        }
        if (preg_match('/^[a-z]{3}$/', $clave) === 1 && in_array(strtoupper($clave), self::UNIDADES, true)) {
            return strtoupper($clave);
        }
        $encontradas = [];
        foreach (self::UNIDADES as $nombre => $codigo) {
            if (preg_match('/\b' . preg_quote($nombre, '/') . '\b/', $clave) === 1) {
                $encontradas[$codigo] = true;
            }
        }
        return count($encontradas) > 1 ? 'AMBIGUA' : (count($encontradas) === 1 ? (string) array_key_first($encontradas) : 'SIN');
    }

    public static function medida(mixed $valor): ?string
    {
        $texto = self::texto($valor);
        if ($texto === null) {
            return null;
        }
        return self::MEDIDAS[ServicioCatalogos::normalizarConcepto($texto)] ?? null;
    }

    /** Fecha AAAA-MM-DD desde un objeto fecha, un serial de Excel (época 1899-12-30) o un texto D/M/AAAA o AAAA-MM-DD. */
    public static function fecha(mixed $valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }
        if (is_int($valor) || is_float($valor) || (is_string($valor) && preg_match('/^\d{4,5}(\.\d+)?$/', trim($valor)) === 1)) {
            $serial = (int) floor((float) $valor);
            if ($serial < 20000 || $serial > 80000) {
                return null;
            }
            return (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))->modify("+{$serial} days")->format('Y-m-d');
        }
        $texto = self::texto($valor);
        if ($texto === null) {
            return null;
        }
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $texto, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        return Entrada::fecha(substr($texto, 0, 10));
    }

    /** Número no negativo como decimal canónico; acepta números de Excel, «$ 1.234.500» y «3.045.000,50». */
    public static function numero(mixed $valor, int $decimales = 2): ?string
    {
        if (is_int($valor)) {
            return $valor < 0 ? null : Entrada::decimal((string) $valor, $decimales);
        }
        if (is_float($valor)) {
            if ($valor < 0 || !is_finite($valor)) {
                return null;
            }
            return Entrada::decimal(rtrim(rtrim(number_format($valor, $decimales, '.', ''), '0'), '.'), $decimales);
        }
        $texto = self::texto($valor);
        return $texto === null ? null : Entrada::decimal($texto, $decimales);
    }

    public static function entero(mixed $valor): ?int
    {
        $numero = self::numero($valor, 0);
        return $numero === null ? null : (int) $numero;
    }

    /** Los 4 últimos dígitos de una identificación, sin separadores. */
    public static function soloDigitos(mixed $valor): string
    {
        return (string) preg_replace('/\D/', '', (string) $valor);
    }
}
