<?php

declare(strict_types=1);

/**
 * Inventario de componentes (SBOM) en CycloneDX 1.5 JSON (ASVS V15.1.2, RS-015), a partir de composer.lock y
 * package-lock.json. Los paquetes de producción tienen alcance «required»; los de desarrollo, «excluded»: se usan
 * para construir y probar, pero no viajan al servidor. Sin dependencias, para correr también dentro del empaquetado.
 *
 *   php herramientas/generar-sbom.php [salida.cdx.json]
 */

$raiz = dirname(__DIR__);
$version = trim((string) @file_get_contents($raiz . '/VERSION')) ?: '0.0.0';
$componentes = [];

$composer = json_decode((string) file_get_contents($raiz . '/composer.lock'), true, 64, JSON_THROW_ON_ERROR);
foreach (['packages' => 'required', 'packages-dev' => 'excluded'] as $grupo => $alcance) {
    foreach ($composer[$grupo] ?? [] as $paquete) {
        $nombre = (string) $paquete['name'];
        $versionPaquete = ltrim((string) $paquete['version'], 'v');
        $componente = [
            'type' => 'library',
            'bom-ref' => "pkg:composer/{$nombre}@{$versionPaquete}",
            'group' => explode('/', $nombre)[0],
            'name' => explode('/', $nombre)[1] ?? $nombre,
            'version' => $versionPaquete,
            'scope' => $alcance,
            'purl' => "pkg:composer/{$nombre}@{$versionPaquete}",
        ];
        $licencias = array_map(static fn (string $id): array => ['license' => ['id' => $id]], (array) ($paquete['license'] ?? []));
        if ($licencias !== []) {
            $componente['licenses'] = $licencias;
        }
        if (!empty($paquete['source']['reference'])) {
            $componente['externalReferences'] = [['type' => 'vcs', 'url' => (string) $paquete['source']['url'], 'comment' => 'commit ' . $paquete['source']['reference']]];
        }
        $componentes[] = $componente;
    }
}

$npm = json_decode((string) file_get_contents($raiz . '/package-lock.json'), true, 64, JSON_THROW_ON_ERROR);
foreach ($npm['packages'] ?? [] as $ruta => $paquete) {
    if ($ruta === '' || !str_starts_with((string) $ruta, 'node_modules/') || !isset($paquete['version'])) {
        continue;
    }
    $nombre = substr((string) $ruta, (int) strrpos((string) $ruta, 'node_modules/') + strlen('node_modules/'));
    $purl = 'pkg:npm/' . str_replace('@', '%40', $nombre) . '@' . $paquete['version'];
    $componente = [
        'type' => 'library',
        'bom-ref' => $purl . '#' . $ruta,
        'name' => $nombre,
        'version' => (string) $paquete['version'],
        // Node solo se usa en local y en la CI (RNF-019): los activos construidos son lo único que se despliega.
        'scope' => !empty($paquete['dev']) ? 'excluded' : 'optional',
        'purl' => $purl,
    ];
    if (isset($paquete['license']) && is_string($paquete['license'])) {
        $componente['licenses'] = [['expression' => $paquete['license']]];
    }
    if (isset($paquete['integrity']) && preg_match('/^sha512-(.+)$/', (string) $paquete['integrity'], $m) === 1) {
        $componente['hashes'] = [['alg' => 'SHA-512', 'content' => bin2hex((string) base64_decode($m[1], true))]];
    }
    $componentes[] = $componente;
}

usort($componentes, static fn (array $a, array $b): int => strcmp($a['bom-ref'], $b['bom-ref']));
// UUID v4 (RFC 9562): versión 4 y variante 10 en sus bits.
$azar = random_bytes(16);
$azar[6] = chr((ord($azar[6]) & 0x0f) | 0x40);
$azar[8] = chr((ord($azar[8]) & 0x3f) | 0x80);
$uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($azar), 4));
$sbom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.5',
    'serialNumber' => 'urn:uuid:' . $uuid,
    'version' => 1,
    'metadata' => [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'tools' => ['components' => [['type' => 'application', 'name' => 'herramientas/generar-sbom.php']]],
        'component' => ['type' => 'application', 'bom-ref' => 'kora@' . $version, 'name' => 'KORA', 'version' => $version, 'description' => 'Gestión de la producción del Centro Agropecuario de Buga'],
    ],
    'components' => $componentes,
];
$json = json_encode($sbom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
if (isset($argv[1])) {
    file_put_contents($argv[1], $json);
    fwrite(STDERR, 'SBOM CycloneDX 1.5: ' . count($componentes) . " componentes en {$argv[1]}.\n");
} else {
    echo $json;
}
