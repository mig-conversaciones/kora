<?php

declare(strict_types=1);

namespace Pruebas\Unitarias;

use ListaAsvs;
use PHPUnit\Framework\TestCase;

require_once RAIZ . '/herramientas/ListaAsvs.php';

/**
 * Lista ASVS 5.0.0 nivel 2 (RS-001, RGO-009): cada requisito de nivel 1 y 2 en alcance tiene estado y evidencia, y
 * docs/seguridad/asvs.md es exactamente lo que genera su fuente (nadie la edita a mano ni la deja desactualizada).
 */
final class ListaAsvsTest extends TestCase
{
    public function testCadaRequisitoEnAlcanceTieneEstadoYEvidencia(): void
    {
        $lista = new ListaAsvs(RAIZ);
        self::assertSame([], $lista->errores());
        self::assertCount(210, $lista->enAlcance(), 'ASVS 5.0.0: 253 requisitos de nivel 1 y 2, menos los 43 de V9, V10 y V17.');
    }

    public function testElDocumentoCoincideConSuFuente(): void
    {
        $actual = str_replace("\r\n", "\n", (string) file_get_contents(RAIZ . '/docs/seguridad/asvs.md'));
        self::assertSame((new ListaAsvs(RAIZ))->markdown(), $actual, 'Regenera con: php herramientas/generar-asvs.php');
    }

    public function testLosCapitulosSinAlcanceSonLosDeRs001(): void
    {
        self::assertSame(['V9', 'V10', 'V17'], array_keys(ListaAsvs::SIN_ALCANCE));
    }
}
