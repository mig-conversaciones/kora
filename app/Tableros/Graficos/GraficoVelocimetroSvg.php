<?php

declare(strict_types=1);

namespace App\Tableros\Graficos;

/**
 * Velocímetro de IND_08 (RF-075), con escala propia de cada medida: el aprovechamiento global va de 0 a 120 % con la
 * meta (95 %) marcada; el rendimiento de un derivado va de 0 al doble del máximo del rango meta, donde «menos es
 * mejor». El arco del valor usa la clase del semáforo; el número lo escribe la vista junto al gráfico.
 */
final class GraficoVelocimetroSvg
{
    private const CX = 100.0;
    private const CY = 100.0;
    private const RADIO = 80.0;

    public static function svg(?float $valor, float $minimo, float $maximo, ?float $meta, bool $enMeta, string $etiqueta): string
    {
        $angulo = static function (float $v) use ($minimo, $maximo): float {
            return max(0.0, min(180.0, ($v - $minimo) / max($maximo - $minimo, 1e-9) * 180));
        };
        $partes = '<path class="velocimetro-pista" d="' . Svg::arco(self::CX, self::CY, self::RADIO, 0, 180) . '" fill="none" stroke-width="18"/>';
        if ($valor !== null && $angulo($valor) > 0.5) {
            $partes .= '<path class="' . ($enMeta ? 'velocimetro-valor velocimetro-en-meta' : 'velocimetro-valor velocimetro-fuera-meta') . '" d="'
                . Svg::arco(self::CX, self::CY, self::RADIO, 0, $angulo($valor)) . '" fill="none" stroke-width="18"/>';
        }
        if ($meta !== null) {
            [$x1, $y1] = Svg::punto(self::CX, self::CY, self::RADIO - 14, $angulo($meta));
            [$x2, $y2] = Svg::punto(self::CX, self::CY, self::RADIO + 14, $angulo($meta));
            $partes .= sprintf('<line class="velocimetro-meta" x1="%s" y1="%s" x2="%s" y2="%s"/>', Svg::n($x1), Svg::n($y1), Svg::n($x2), Svg::n($y2));
        }
        if ($valor !== null) {
            [$x, $y] = Svg::punto(self::CX, self::CY, self::RADIO - 24, $angulo($valor));
            $partes .= sprintf('<line class="velocimetro-aguja" x1="%s" y1="%s" x2="%s" y2="%s"/><circle class="velocimetro-eje" cx="%s" cy="%s" r="5"/>', Svg::n(self::CX), Svg::n(self::CY), Svg::n($x), Svg::n($y), Svg::n(self::CX), Svg::n(self::CY));
        }
        return '<svg class="grafico-velocimetro" viewBox="0 0 200 112" width="220" height="124" role="img" aria-label="' . Svg::e($etiqueta) . '">'
            . '<title>' . Svg::e($etiqueta) . '</title>' . $partes . '</svg>';
    }
}
