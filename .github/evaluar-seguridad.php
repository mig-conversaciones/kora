<?php

declare(strict_types=1);

/**
 * Evaluación de los informes de seguridad de la CI (RS-016). Publica cada hallazgo como anotación de GitHub Actions
 * (legible sin autenticación por la API de check-runs), escribe el resumen del trabajo y termina con 1 si hay algún
 * hallazgo bloqueante: un riesgo alto de ZAP o cualquier secreto de gitleaks.
 *
 *   php .github/evaluar-seguridad.php zap informe.json [informe2.json ...]
 *   php .github/evaluar-seguridad.php gitleaks informe.json
 */

$tipo = $argv[1] ?? '';
$archivos = array_slice($argv, 2);
$resumen = getenv('GITHUB_STEP_SUMMARY') ?: 'php://stdout';
$escapar = static fn (string $t): string => strtr($t, ['%' => '%25', "\r" => '%0D', "\n" => '%0A']);
$bloqueantes = 0;
$lineas = [];

if ($tipo === 'zap') {
    $riesgos = ['0' => 'Informativo', '1' => 'Bajo', '2' => 'Medio', '3' => 'Alto'];
    foreach ($archivos as $archivo) {
        $informe = json_decode((string) @file_get_contents($archivo), true);
        if (!is_array($informe)) {
            echo "::error title=ZAP::No se pudo leer {$archivo}.\n";
            exit(1);
        }
        $lineas[] = '### ZAP — ' . basename($archivo);
        $lineas[] = '| Riesgo | Alerta | Instancias | Ejemplo |';
        $lineas[] = '|---|---|---|---|';
        $alertas = [];
        foreach ($informe['site'] ?? [] as $sitio) {
            foreach ($sitio['alerts'] ?? [] as $alerta) {
                $alertas[] = $alerta;
            }
        }
        usort($alertas, static fn (array $a, array $b): int => (int) $b['riskcode'] <=> (int) $a['riskcode']);
        foreach ($alertas as $alerta) {
            $riesgo = $riesgos[(string) $alerta['riskcode']] ?? (string) $alerta['riskcode'];
            $ejemplo = (string) ($alerta['instances'][0]['uri'] ?? '');
            $instancias = count($alerta['instances'] ?? []);
            $lineas[] = "| {$riesgo} | {$alerta['name']} (pluginid {$alerta['pluginid']}) | {$instancias} | {$ejemplo} |";
            $nivel = (int) $alerta['riskcode'] >= 3 ? 'error' : ((int) $alerta['riskcode'] === 2 ? 'warning' : 'notice');
            $bloqueantes += (int) $alerta['riskcode'] >= 3 ? 1 : 0;
            echo "::{$nivel} title=ZAP {$riesgo}: " . $escapar((string) $alerta['name']) . '::' . $escapar("{$instancias} instancia(s). Ejemplo: {$ejemplo}. Plugin {$alerta['pluginid']}.") . "\n";
        }
        if ($alertas === []) {
            $lineas[] = '| — | Sin alertas | 0 | |';
        }
    }
} elseif ($tipo === 'gitleaks') {
    $hallazgos = json_decode((string) @file_get_contents($archivos[0] ?? ''), true);
    $hallazgos = is_array($hallazgos) ? $hallazgos : [];
    $lineas[] = '### gitleaks';
    $lineas[] = $hallazgos === [] ? 'Sin secretos en el historial.' : count($hallazgos) . ' posible(s) secreto(s) (valores ocultos).';
    foreach ($hallazgos as $h) {
        $bloqueantes++;
        $ubicacion = "{$h['File']}:{$h['StartLine']}";
        $lineas[] = "- {$h['RuleID']} en {$ubicacion} (commit " . substr((string) $h['Commit'], 0, 8) . ')';
        echo '::error file=' . $escapar((string) $h['File']) . ',line=' . (int) $h['StartLine'] . ',title=gitleaks ' . $escapar((string) $h['RuleID']) . '::'
            . $escapar("Posible secreto en {$ubicacion}, commit " . substr((string) $h['Commit'], 0, 8) . '. El valor se oculta en el informe.') . "\n";
    }
} else {
    fwrite(STDERR, "Uso: evaluar-seguridad.php zap|gitleaks informe.json ...\n");
    exit(2);
}

file_put_contents($resumen, implode("\n", $lineas) . "\n\n", FILE_APPEND);
exit($bloqueantes > 0 ? 1 : 0);
