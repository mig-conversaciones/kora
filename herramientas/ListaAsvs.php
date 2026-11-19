<?php

declare(strict_types=1);

/**
 * Lista ASVS 5.0.0 nivel 2 de KORA: une el texto oficial con el estado de cada requisito y la renderiza en Markdown.
 * La usan herramientas/generar-asvs.php y la prueba unitaria ListaAsvsTest.
 */
final class ListaAsvs
{
    public const FUENTE = 'docs/seguridad/fuentes/OWASP_ASVS_5.0.0_en.csv';
    public const ESTADO = 'docs/seguridad/asvs-estado.csv';
    /** Capítulos fuera del alcance de la v1 (RS-001). */
    public const SIN_ALCANCE = [
        'V9' => 'No hay tokens autocontenidos (JWT): las sesiones son de referencia en la base (ADR-006).',
        'V10' => 'No hay OAuth ni OIDC: la autenticación es propia (contraseña y TOTP).',
        'V17' => 'No hay WebRTC.',
    ];
    public const ESTADOS = ['CUMPLE' => 'Cumple', 'PARCIAL' => 'Parcial', 'STAGING' => 'Staging', 'DECISION' => 'Decisión', 'NO_APLICA' => 'No aplica'];

    /** @var list<array{capitulo:string,nombre_capitulo:string,seccion:string,nombre_seccion:string,id:string,texto:string,nivel:int}> */
    private array $requisitos = [];
    /** @var array<string,array{estado:string,evidencia:string}> */
    private array $estados = [];

    public function __construct(private readonly string $raiz)
    {
        $fuente = fopen($raiz . '/' . self::FUENTE, 'r');
        if ($fuente === false) {
            throw new RuntimeException('Falta el texto oficial de ASVS.');
        }
        fgetcsv($fuente, null, ',', '"', '');
        while (($fila = fgetcsv($fuente, null, ',', '"', '')) !== false) {
            if (count($fila) < 7 || !preg_match('/^V\d+\.\d+\.\d+$/', (string) $fila[4])) {
                continue;
            }
            $this->requisitos[] = [
                'capitulo' => (string) $fila[0], 'nombre_capitulo' => (string) $fila[1], 'seccion' => (string) $fila[2], 'nombre_seccion' => (string) $fila[3],
                'id' => (string) $fila[4], 'texto' => trim((string) $fila[5]), 'nivel' => (int) $fila[6],
            ];
        }
        fclose($fuente);
        $estado = fopen($raiz . '/' . self::ESTADO, 'r');
        if ($estado === false) {
            throw new RuntimeException('Falta el estado de la lista ASVS.');
        }
        fgetcsv($estado, null, ',', '"', '');
        while (($fila = fgetcsv($estado, null, ',', '"', '')) !== false) {
            if (count($fila) >= 3) {
                $this->estados[(string) $fila[0]] = ['estado' => (string) $fila[1], 'evidencia' => trim((string) $fila[2])];
            }
        }
        fclose($estado);
    }

    /** @return list<array{capitulo:string,nombre_capitulo:string,seccion:string,nombre_seccion:string,id:string,texto:string,nivel:int}> requisitos de nivel 1 y 2 en alcance */
    public function enAlcance(): array
    {
        return array_values(array_filter($this->requisitos, static fn (array $r): bool => $r['nivel'] <= 2 && !isset(self::SIN_ALCANCE[$r['capitulo']])));
    }

    /** @return list<string> */
    public function errores(): array
    {
        $errores = [];
        $ids = [];
        foreach ($this->enAlcance() as $r) {
            $ids[$r['id']] = true;
            $e = $this->estados[$r['id']] ?? null;
            if ($e === null) {
                $errores[] = "{$r['id']} no tiene estado.";
            } elseif (!isset(self::ESTADOS[$e['estado']])) {
                $errores[] = "{$r['id']} tiene un estado desconocido: {$e['estado']}.";
            } elseif (mb_strlen($e['evidencia']) < 10) {
                $errores[] = "{$r['id']} no tiene evidencia ni justificación.";
            }
        }
        foreach (array_keys($this->estados) as $id) {
            if (!isset($ids[$id])) {
                $errores[] = "{$id} no es un requisito de nivel 1 o 2 en alcance.";
            }
        }
        return $errores;
    }

    /** @return array<string,int> */
    public function conteo(): array
    {
        $conteo = array_fill_keys(array_keys(self::ESTADOS), 0);
        foreach ($this->enAlcance() as $r) {
            $conteo[$this->estados[$r['id']]['estado'] ?? 'PARCIAL']++;
        }
        return $conteo;
    }

    public function resumenTexto(): string
    {
        $partes = [];
        foreach ($this->conteo() as $estado => $n) {
            $partes[] = self::ESTADOS[$estado] . " {$n}";
        }
        return count($this->enAlcance()) . ' requisitos (' . implode(', ', $partes) . ')';
    }

    public function markdown(): string
    {
        $plantilla = str_replace("\r\n", "\n", (string) file_get_contents($this->raiz . '/docs/seguridad/asvs-plantilla.md'));
        $version = trim((string) @file_get_contents($this->raiz . '/VERSION')) ?: 'sin versión';
        return strtr($plantilla, [
            '{{VERSION}}' => $version,
            '{{FUENTE}}' => '`' . self::FUENTE . '`, SHA-256 `' . substr(hash_file('sha256', $this->raiz . '/' . self::FUENTE) ?: '', 0, 16) . '…`',
            '{{RESUMEN}}' => $this->resumen(),
            '{{DECISIONES}}' => $this->decisiones(),
            '{{CAPITULOS}}' => $this->capitulos(),
            '{{HALLAZGOS}}' => $this->hallazgos(),
        ]);
    }

    private function resumen(): string
    {
        $por = [];
        foreach ($this->enAlcance() as $r) {
            $por[$r['capitulo']]['nombre'] = $r['nombre_capitulo'];
            $estado = $this->estados[$r['id']]['estado'];
            $por[$r['capitulo']][$estado] = ($por[$r['capitulo']][$estado] ?? 0) + 1;
        }
        $lineas = ['| Capítulo | ' . implode(' | ', self::ESTADOS) . ' | Total |', '|---|' . str_repeat('---|', count(self::ESTADOS) + 1)];
        foreach ($por as $capitulo => $datos) {
            $celdas = [];
            $total = 0;
            foreach (array_keys(self::ESTADOS) as $estado) {
                $celdas[] = (string) ($datos[$estado] ?? 0);
                $total += $datos[$estado] ?? 0;
            }
            $lineas[] = "| {$capitulo} {$datos['nombre']} | " . implode(' | ', $celdas) . " | {$total} |";
        }
        $conteo = $this->conteo();
        $lineas[] = '| **Total** | **' . implode('** | **', $conteo) . '** | **' . array_sum($conteo) . '** |';
        $lineas[] = '';
        foreach (self::SIN_ALCANCE as $capitulo => $motivo) {
            $lineas[] = "- **{$capitulo}, fuera de alcance:** {$motivo}";
        }
        return implode("\n", $lineas);
    }

    private function decisiones(): string
    {
        $lineas = [];
        foreach ($this->enAlcance() as $r) {
            $e = $this->estados[$r['id']];
            if ($e['estado'] === 'DECISION') {
                $lineas[] = "- **{$r['id']}:** {$e['evidencia']}";
            }
        }
        return $lineas === [] ? 'Ninguna.' : implode("\n", $lineas);
    }

    private function capitulos(): string
    {
        $salida = [];
        $capitulo = '';
        foreach ($this->enAlcance() as $r) {
            if ($r['capitulo'] !== $capitulo) {
                $capitulo = $r['capitulo'];
                $salida[] = '';
                $salida[] = "### {$capitulo} {$r['nombre_capitulo']}";
                $salida[] = '';
                $salida[] = '| Requisito | Nivel | Texto oficial | Estado | Evidencia o justificación |';
                $salida[] = '|---|---|---|---|---|';
            }
            $e = $this->estados[$r['id']];
            $texto = mb_strlen($r['texto']) > 220 ? mb_substr($r['texto'], 0, 217) . '…' : $r['texto'];
            $salida[] = sprintf('| %s | %d | %s | %s | %s |', $r['id'], $r['nivel'], self::celda($texto), self::ESTADOS[$e['estado']], self::celda($e['evidencia']));
        }
        return ltrim(implode("\n", $salida));
    }

    private function hallazgos(): string
    {
        return implode("\n", [
            '- **SAST:** PHPStan nivel 6 sin errores, PSR-12 y ESLint (`no-eval`, `no-alert`, `no-console`) en cada push (trabajo «PHP» y «Activos»).',
            '- **Secretos:** gitleaks sobre todo el historial en cada push (trabajo «Secretos»).',
            '- **Dependencias:** `composer audit` y `npm audit --omit=dev` sin avisos altos; avisos solo en herramientas de desarrollo (V15.2.1).',
            '- **DAST:** OWASP ZAP baseline sin sesión y escaneo completo autenticado en cada push contra el servidor de la CI (trabajo «Navegador, accesibilidad y ZAP»). El resumen y los informes quedan en el trabajo; un riesgo alto lo hace fallar. El escaneo autenticado en staging, antes de producción, sigue pendiente (RS-016).',
        ]);
    }

    private static function celda(string $texto): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $texto);
    }
}
