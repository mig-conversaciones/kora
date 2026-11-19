<?php

declare(strict_types=1);

namespace Pruebas\Unitarias;

use App\Controladores\ControladorAyuda;
use App\Nucleo\Markdown;
use PHPUnit\Framework\TestCase;

/**
 * Ayuda en línea (RDOC-009): el Markdown de las guías nunca produce HTML crudo ni enlaces inseguros, y cada guía del
 * catálogo existe con enlaces internos que llevan a guías y anclas reales.
 */
final class MarkdownGuiasTest extends TestCase
{
    private function markdown(): Markdown
    {
        return new Markdown(static fn (string $guia): string => '/ayuda/' . $guia);
    }

    public function testEscapaTodoYSoloEnlazaGuiasAnclasYHttps(): void
    {
        $html = $this->markdown()->html("# Título\n\nTexto <script>alert(1)</script> y **negrita** con `<b>código</b>`.\n\n"
            . '[guía](primeros-pasos.md#activar-tu-cuenta) [externo](https://www.sena.edu.co) [malo](javascript:alert(1)) [ancla](#arriba)');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('<strong>negrita</strong>', $html);
        self::assertStringContainsString('<code>&lt;b&gt;código&lt;/b&gt;</code>', $html);
        self::assertStringContainsString('<a href="/ayuda/primeros-pasos#activar-tu-cuenta">guía</a>', $html);
        self::assertStringContainsString('<a href="https://www.sena.edu.co" rel="noopener noreferrer">externo</a>', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('<a href="#arriba">ancla</a>', $html);
    }

    public function testTablasListasAnidadasCitasYTitulos(): void
    {
        $md = $this->markdown();
        $html = $md->html("## Lo que puedes hacer\n\n| Módulo | Qué |\n|---|---|\n| Hato | Consultar |\n\n1. Uno\n   - a\n   - b\n2. Dos\n\n> Nota importante");
        self::assertStringContainsString('<h2 id="lo-que-puedes-hacer">', $html);
        self::assertStringContainsString('<th scope="col">Módulo</th>', $html);
        self::assertStringContainsString('<td>Hato</td>', $html);
        self::assertStringContainsString('<ol><li>Uno<ul><li>a</li><li>b</li></ul></li><li>Dos</li></ol>', $html);
        self::assertStringContainsString('<aside class="nota-guia"><p>Nota importante</p></aside>', $html);
        self::assertSame([['nivel' => 2, 'texto' => 'Lo que puedes hacer', 'ancla' => 'lo-que-puedes-hacer']], $md->titulos());
    }

    public function testCadaGuiaExisteYSusEnlacesInternosResuelven(): void
    {
        $anclas = [];
        foreach (ControladorAyuda::GUIAS as [$archivo]) {
            $ruta = RAIZ . '/docs/guias/' . $archivo;
            self::assertFileExists($ruta);
            preg_match_all('/^#{1,3}\s+(.+)$/m', (string) file_get_contents($ruta), $m);
            $anclas[$archivo] = array_map([Markdown::class, 'ancla'], $m[1]);
        }
        foreach (ControladorAyuda::GUIAS as [$archivo]) {
            preg_match_all('/\]\(([a-z0-9-]+\.md)(?:#([a-z0-9-]+))?\)/', (string) file_get_contents(RAIZ . '/docs/guias/' . $archivo), $enlaces, PREG_SET_ORDER);
            foreach ($enlaces as $enlace) {
                self::assertArrayHasKey($enlace[1], $anclas, "{$archivo} enlaza {$enlace[1]}, que no está en la ayuda.");
                if (isset($enlace[2])) {
                    self::assertContains($enlace[2], $anclas[$enlace[1]], "{$archivo} enlaza el ancla #{$enlace[2]} de {$enlace[1]}, que no existe.");
                }
            }
            preg_match_all('/\]\(#([a-z0-9-]+)\)/', (string) file_get_contents(RAIZ . '/docs/guias/' . $archivo), $propias);
            foreach ($propias[1] as $ancla) {
                self::assertContains($ancla, $anclas[$archivo], "{$archivo} enlaza #{$ancla}, que no existe en la misma guía.");
            }
        }
    }
}
