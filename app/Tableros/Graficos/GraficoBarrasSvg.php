<?php

declare(strict_types=1);

namespace App\Tableros\Graficos;

use App\Tableros\Formato;

/**
 * IND_001 (RF-064): barras combinadas por unidad. La barra A es la asignación anual y la barra B se apila con la venta
 * directa abajo y el autoconsumo arriba, en la misma escala. Cada columna es un enlace enfocable con Tab que abre el
 * tablero técnico (RF-071) y su información se anuncia por lector de pantalla (aria-label y aria-describedby al
 * tooltip). El tooltip se abre con el puntero y con el foco, solo con CSS. La tabla equivalente la arma la vista.
 */
final class GraficoBarrasSvg
{
    private const ALTO = 180;
    private const ANCHO = 44;

    /**
     * @param list<array<string,mixed>> $unidades filas de ServicioIndicadores::central()['unidades']
     * @param callable(array<string,mixed>):?string $enlace URL del tablero técnico de la unidad o null
     */
    public static function html(array $unidades, callable $enlace): string
    {
        if ($unidades === []) {
            return '';
        }
        $maximo = 0.0;
        foreach ($unidades as $u) {
            $maximo = max($maximo, (float) $u['asignacion'], (float) $u['total']);
        }
        $maximo = $maximo > 0 ? $maximo : 1.0;
        $columnas = '';
        foreach ($unidades as $u) {
            $columnas .= self::columna($u, $maximo, $enlace($u));
        }
        return '<div class="grafico-barras-area">' . $columnas . '</div>'
            . '<ul class="grafico-leyenda" aria-label="Leyenda">'
            . '<li><span class="leyenda-marca leyenda-asignacion" aria-hidden="true"></span>A · Asignación anual</li>'
            . '<li><span class="leyenda-marca leyenda-venta" aria-hidden="true"></span>B · Venta directa</li>'
            . '<li><span class="leyenda-marca leyenda-autoconsumo leyenda-patron" aria-hidden="true"></span>B · Autoconsumo</li>'
            . '</ul>';
    }

    /** @param array<string,mixed> $u */
    private static function columna(array $u, float $maximo, ?string $url): string
    {
        $alto = static fn (mixed $v): float => max(0.0, (float) $v / $maximo * self::ALTO);
        $hA = $alto($u['asignacion']);
        $hV = $alto($u['venta_directa']);
        $hC = $alto($u['autoconsumo']);
        // Una barra con valor nunca desaparece: mínimo de 1,5 px para que se perciba (la cifra exacta está en el tooltip).
        $hV = (float) $u['venta_directa'] > 0 ? max($hV, 1.5) : 0.0;
        $hC = (float) $u['autoconsumo'] > 0 ? max($hC, 1.5) : 0.0;
        $base = self::ALTO;
        $svg = sprintf(
            '<svg class="grafico-columna-svg" viewBox="0 0 %d %d" width="%d" height="%d" aria-hidden="true" focusable="false">'
            . '<line class="grafico-eje" x1="0" y1="%d" x2="%d" y2="%d"/>'
            . '<rect class="barra-asignacion" x="3" y="%s" width="17" height="%s" rx="2"/>'
            . '<rect class="barra-venta" x="24" y="%s" width="17" height="%s"/>'
            . '<rect class="barra-autoconsumo" x="24" y="%s" width="17" height="%s"/>'
            . '</svg>',
            self::ANCHO,
            self::ALTO,
            self::ANCHO,
            self::ALTO,
            $base,
            self::ANCHO,
            $base,
            Svg::n($base - $hA),
            Svg::n($hA),
            Svg::n($base - $hV),
            Svg::n($hV),
            Svg::n($base - $hV - $hC),
            Svg::n($hC)
        );
        $id = 'detalle-ind001-' . preg_replace('/[^A-Za-z0-9]/', '', (string) $u['codigo']);
        $etiqueta = sprintf(
            '%s: asignación %s; venta directa %s; autoconsumo %s; cobertura %s.%s',
            $u['nombre'],
            Formato::moneda($u['asignacion']),
            Formato::moneda($u['venta_directa']),
            Formato::moneda($u['autoconsumo']),
            Formato::porcentaje($u['cobertura']),
            $url === null ? '' : ' Abrir el tablero técnico.'
        );
        $detalle = '<div class="grafico-tooltip" role="tooltip" id="' . Svg::e($id) . '">'
            . '<p class="grafico-tooltip-titulo">' . Svg::e($u['nombre']) . '</p>'
            . '<dl class="lista-datos lista-datos-compacta">'
            . '<dt>Asignación anual</dt><dd class="cifras">' . Svg::e(Formato::moneda($u['asignacion'])) . '</dd>'
            . '<dt>· Mantenimiento</dt><dd class="cifras">' . Svg::e(Formato::moneda($u['mantenimiento'])) . '</dd>'
            . '<dt>· Alimentación</dt><dd class="cifras">' . Svg::e(Formato::moneda($u['alimentacion'])) . '</dd>'
            . '<dt>· Materiales de formación</dt><dd class="cifras">' . Svg::e(Formato::moneda($u['materiales_formacion'])) . '</dd>'
            . '<dt>Venta directa</dt><dd class="cifras">' . Svg::e(Formato::moneda($u['venta_directa'])) . ' (' . Svg::e(Formato::porcentaje($u['participacion_venta'])) . ')</dd>'
            . '<dt>Autoconsumo</dt><dd class="cifras">' . Svg::e(Formato::moneda($u['autoconsumo'])) . ' (' . Svg::e(Formato::porcentaje($u['participacion_autoconsumo'])) . ')</dd>'
            . '<dt>Cobertura</dt><dd class="cifras">' . Svg::e(Formato::porcentaje($u['cobertura'])) . '</dd>'
            . '</dl></div>';
        $contenido = $svg . '<span class="grafico-etiqueta">' . Svg::e($u['codigo']) . '</span>';
        $control = $url === null
            ? '<span class="grafico-columna-control" tabindex="0" role="img" aria-label="' . Svg::e($etiqueta) . '" aria-describedby="' . Svg::e($id) . '">' . $contenido . '</span>'
            : '<a class="grafico-columna-control" href="' . Svg::e($url) . '" aria-label="' . Svg::e($etiqueta) . '" aria-describedby="' . Svg::e($id) . '">' . $contenido . '</a>';
        return '<div class="grafico-columna">' . $control . $detalle . '</div>';
    }
}
