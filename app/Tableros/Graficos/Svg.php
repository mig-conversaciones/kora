<?php

declare(strict_types=1);

namespace App\Tableros\Graficos;

/**
 * Ayudas comunes de los gráficos generados en el servidor (ADR-009, D-31, RUI-017). Los colores salen de clases CSS
 * que leen los tokens (modo claro y oscuro, RUI-005): ningún gráfico usa estilos en línea, que la CSP prohíbe.
 */
final class Svg
{
    public static function e(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Número para un atributo SVG: punto decimal y como máximo 2 decimales. */
    public static function n(float $valor): string
    {
        $texto = number_format($valor, 2, '.', '');
        return rtrim(rtrim($texto, '0'), '.') ?: '0';
    }

    /**
     * Punto de una circunferencia; el ángulo 0 está a la izquierda y crece en sentido horario (semicírculo superior).
     *
     * @return array{0:float,1:float}
     */
    public static function punto(float $cx, float $cy, float $radio, float $grados): array
    {
        $radianes = deg2rad(180 + $grados);
        return [$cx + $radio * cos($radianes), $cy + $radio * sin($radianes)];
    }

    /** Arco de un semicírculo superior entre dos ángulos (0 a 180). */
    public static function arco(float $cx, float $cy, float $radio, float $desde, float $hasta): string
    {
        [$x1, $y1] = self::punto($cx, $cy, $radio, $desde);
        [$x2, $y2] = self::punto($cx, $cy, $radio, $hasta);
        $grande = ($hasta - $desde) > 180 ? 1 : 0;
        return sprintf('M %s %s A %s %s 0 %d 1 %s %s', self::n($x1), self::n($y1), self::n($radio), self::n($radio), $grande, self::n($x2), self::n($y2));
    }
}
