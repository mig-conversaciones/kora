<?php

declare(strict_types=1);

namespace App\Tableros\Graficos;

/**
 * Anillo del inventario completo del hato (RF-074): Producción, Horra y Práctica, siempre con las tres categorías
 * aunque el interruptor de práctica cambie el denominador de la tasa. Los segmentos son círculos con
 * stroke-dasharray (atributos, no estilos). La leyenda con cifras la pone la vista.
 */
final class GraficoAnilloSvg
{
    private const RADIO = 42.0;

    /** @param list<array{clase:string,etiqueta:string,valor:int}> $segmentos */
    public static function svg(array $segmentos, string $centro, string $etiqueta): string
    {
        $total = array_sum(array_map(static fn (array $s): int => $s['valor'], $segmentos));
        $circunferencia = 2 * M_PI * self::RADIO;
        $partes = '<circle class="anillo-pista" cx="60" cy="60" r="' . Svg::n(self::RADIO) . '" fill="none" stroke-width="16"/>';
        $acumulado = 0.0;
        foreach ($segmentos as $s) {
            if ($total === 0 || $s['valor'] === 0) {
                continue;
            }
            $largo = $s['valor'] / $total * $circunferencia;
            $partes .= sprintf(
                '<circle class="%s" cx="60" cy="60" r="%s" fill="none" stroke-width="16" stroke-dasharray="%s %s" stroke-dashoffset="%s" transform="rotate(-90 60 60)"/>',
                Svg::e($s['clase']),
                Svg::n(self::RADIO),
                Svg::n($largo),
                Svg::n($circunferencia - $largo),
                Svg::n(-$acumulado)
            );
            $acumulado += $largo;
        }
        return '<svg class="grafico-anillo" viewBox="0 0 120 120" width="160" height="160" role="img" aria-label="' . Svg::e($etiqueta) . '">'
            . '<title>' . Svg::e($etiqueta) . '</title>' . $partes
            . '<text class="anillo-texto" x="60" y="66" text-anchor="middle">' . Svg::e($centro) . '</text></svg>';
    }
}
