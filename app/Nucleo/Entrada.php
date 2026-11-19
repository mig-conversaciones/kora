<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Lectura estricta de valores escritos en formularios (RS-003). Devuelve null cuando el texto no es válido, para que
 * el caso de uso arme el mensaje por campo. Acepta la coma decimal colombiana y los puntos de miles («3.045.000,50»)
 * además del formato de los campos numéricos del navegador («3045000.50»).
 */
final class Entrada
{
    /** Decimal no negativo como texto canónico («3045000.50»), apto para DECIMAL sin pérdida por float. */
    public static function decimal(mixed $valor, int $decimales = 2): ?string
    {
        $texto = str_replace([' ', "\u{00A0}", '$'], '', trim((string) $valor));
        if ($texto === '') {
            return null;
        }
        if (str_contains($texto, ',')) {
            // Formato local: los puntos son de miles y la coma es decimal.
            $texto = str_replace(',', '.', str_replace('.', '', $texto));
        } elseif ($decimales <= 2 && preg_match('/^\d{1,3}(\.\d{3})+$/', $texto) === 1) {
            // Montos «3.045.000» sin decimales: puntos de miles. No se aplica a factores ni cantidades (8.500 L/kg
            // sería ambiguo), que llegan del campo numérico del navegador con punto decimal.
            $texto = str_replace('.', '', $texto);
        }
        if (preg_match('/^\d{1,13}(\.\d+)?$/', $texto) !== 1) {
            return null;
        }
        [$entero, $fraccion] = array_pad(explode('.', $texto, 2), 2, '');
        if (strlen(rtrim($fraccion, '0')) > $decimales) {
            return null;
        }
        $entero = ltrim($entero, '0') === '' ? '0' : ltrim($entero, '0');
        return $decimales === 0 ? $entero : $entero . '.' . str_pad(substr($fraccion, 0, $decimales), $decimales, '0');
    }

    public static function entero(mixed $valor, int $minimo = 0, int $maximo = PHP_INT_MAX): ?int
    {
        $texto = trim((string) $valor);
        if (preg_match('/^-?\d{1,18}$/', $texto) !== 1) {
            return null;
        }
        $numero = (int) $texto;
        return $numero < $minimo || $numero > $maximo ? null : $numero;
    }

    /** Fecha AAAA-MM-DD real (rechaza 2026-02-30). */
    public static function fecha(mixed $valor): ?string
    {
        $texto = trim((string) $valor);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $texto;
    }

    /** Texto recortado en NFC; null si supera el máximo. */
    public static function texto(mixed $valor, int $maximo): ?string
    {
        $texto = trim((string) $valor);
        $texto = \Normalizer::normalize($texto, \Normalizer::FORM_C) ?: $texto;
        return mb_strlen($texto) > $maximo ? null : $texto;
    }

    /** Compara dos decimales canónicos sin pasar por float (bcmath no es obligatorio en Hostinger). */
    public static function comparar(string $a, string $b): int
    {
        [$ea, $fa] = array_pad(explode('.', $a, 2), 2, '');
        [$eb, $fb] = array_pad(explode('.', $b, 2), 2, '');
        $largo = max(strlen($fa), strlen($fb));
        $na = str_pad(ltrim($ea, '0'), 20, '0', STR_PAD_LEFT) . str_pad($fa, $largo, '0');
        $nb = str_pad(ltrim($eb, '0'), 20, '0', STR_PAD_LEFT) . str_pad($fb, $largo, '0');
        return $na <=> $nb;
    }

    /** Suma de decimales canónicos en centavos enteros (hasta 13 dígitos enteros cabe en int de 64 bits). */
    public static function sumar(string ...$valores): string
    {
        $centavos = 0;
        foreach ($valores as $valor) {
            [$e, $f] = array_pad(explode('.', $valor, 2), 2, '');
            $centavos += (int) $e * 100 + (int) str_pad(substr($f, 0, 2), 2, '0');
        }
        return intdiv($centavos, 100) . '.' . str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }
}
