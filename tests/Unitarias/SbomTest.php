<?php

declare(strict_types=1);

namespace Pruebas\Unitarias;

use PHPUnit\Framework\TestCase;

/**
 * SBOM CycloneDX 1.5 (ASVS V15.1.2): cada dependencia de producción de composer.lock aparece con su purl y alcance
 * «required»; las de desarrollo y las de Node, con «excluded».
 */
final class SbomTest extends TestCase
{
    public function testInventarioCompletoConAlcancePorUso(): void
    {
        $salida = (string) tempnam(sys_get_temp_dir(), 'kora-sbom-');
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(RAIZ . '/herramientas/generar-sbom.php') . ' ' . escapeshellarg($salida) . ' 2>&1', $mensajes, $codigo);
        self::assertSame(0, $codigo, implode("\n", $mensajes));
        $sbom = json_decode((string) file_get_contents($salida), true, 64, JSON_THROW_ON_ERROR);
        unlink($salida);

        self::assertSame(['CycloneDX', '1.5'], [$sbom['bomFormat'], $sbom['specVersion']]);
        self::assertMatchesRegularExpression('/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $sbom['serialNumber']);
        self::assertSame('KORA', $sbom['metadata']['component']['name']);

        $porPurl = array_column($sbom['components'], 'scope', 'purl');
        $lock = json_decode((string) file_get_contents(RAIZ . '/composer.lock'), true, 64, JSON_THROW_ON_ERROR);
        foreach ($lock['packages'] as $paquete) {
            $purl = 'pkg:composer/' . $paquete['name'] . '@' . ltrim($paquete['version'], 'v');
            self::assertSame('required', $porPurl[$purl] ?? null, $purl);
        }
        foreach ($lock['packages-dev'] as $paquete) {
            self::assertSame('excluded', $porPurl['pkg:composer/' . $paquete['name'] . '@' . ltrim($paquete['version'], 'v')] ?? null);
        }
        $npm = array_filter($sbom['components'], static fn (array $c): bool => str_starts_with($c['purl'], 'pkg:npm/'));
        self::assertNotEmpty($npm);
        foreach ($npm as $c) {
            self::assertNotSame('required', $c['scope'], 'Node no se despliega (RNF-019).');
        }
    }
}
