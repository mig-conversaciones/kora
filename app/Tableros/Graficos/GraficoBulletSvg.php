<?php

declare(strict_types=1);

namespace App\Tableros\Graficos;

use App\Tableros\Formato;

/**
 * Barra de progreso tipo bullet de IND_002 (RF-067): avance del proceso con la marca del 100 % del cupo. La escala
 * llega hasta el 120 % para que un sobrecumplimiento se vea sin salirse del gráfico.
 */
final class GraficoBulletSvg
{
    private const ANCHO = 240;
    private const ESCALA = 120.0;

    public static function svg(?float $avance, string $etiqueta): string
    {
        $valor = $avance === null ? 0.0 : max(0.0, min(self::ESCALA, $avance));
        $ancho = $valor / self::ESCALA * self::ANCHO;
        $meta = 100 / self::ESCALA * self::ANCHO;
        $clase = $avance !== null && $avance >= 100 ? 'bullet-valor bullet-cumplido' : 'bullet-valor';
        return sprintf(
            '<svg class="grafico-bullet" viewBox="0 0 %d 18" width="%d" height="18" role="img" aria-label="%s">'
            . '<title>%s</title>'
            . '<rect class="bullet-pista" x="0" y="4" width="%d" height="10" rx="3"/>'
            . '<rect class="%s" x="0" y="4" width="%s" height="10" rx="3"/>'
            . '<line class="bullet-meta" x1="%s" y1="0" x2="%s" y2="18"/>'
            . '</svg>',
            self::ANCHO,
            self::ANCHO,
            Svg::e($etiqueta . ': ' . Formato::porcentaje($avance) . ' del cupo'),
            Svg::e($etiqueta . ': ' . Formato::porcentaje($avance) . ' del cupo'),
            self::ANCHO,
            $clase,
            Svg::n($ancho),
            Svg::n($meta),
            Svg::n($meta)
        );
    }
}
