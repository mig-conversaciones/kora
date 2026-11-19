<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Markdown mínimo para la ayuda en línea (RDOC-009). Solo se usa con las guías del repositorio (docs/guias), nunca
 * con texto de usuarios, y aun así escapa todo el texto antes de dar formato: no hay HTML crudo.
 *
 * Admite títulos (#, ##, ###), párrafos, listas con viñeta y numeradas (con un nivel de anidación), tablas, citas,
 * negrita, código en línea y enlaces. Un enlace a otra guía (archivo.md) se convierte en su ruta de ayuda; los
 * externos solo se aceptan con https.
 */
final class Markdown
{
    /** @var list<array{nivel:int,texto:string,ancla:string}> */
    private array $titulos = [];

    /** @param callable(string):string $rutaGuia convierte «guia.md#ancla» en una URL de la aplicación */
    public function __construct(private readonly mixed $rutaGuia)
    {
    }

    /** @return list<array{nivel:int,texto:string,ancla:string}> títulos ## del último documento, para el índice */
    public function titulos(): array
    {
        return $this->titulos;
    }

    public function html(string $markdown): string
    {
        $this->titulos = [];
        $lineas = explode("\n", str_replace("\r\n", "\n", $markdown));
        $salida = [];
        $parrafo = [];
        $i = 0;
        while ($i < count($lineas)) {
            $linea = rtrim($lineas[$i]);
            if (trim($linea) === '') {
                $this->cerrarParrafo($parrafo, $salida);
                $i++;
                continue;
            }
            if (preg_match('/^(#{1,3})\s+(.+)$/', $linea, $m) === 1) {
                $this->cerrarParrafo($parrafo, $salida);
                $nivel = strlen($m[1]);
                $ancla = self::ancla($m[2]);
                if ($nivel === 2) {
                    $this->titulos[] = ['nivel' => $nivel, 'texto' => $m[2], 'ancla' => $ancla];
                }
                $salida[] = sprintf('<h%d id="%s">%s</h%d>', $nivel, $ancla, $this->enLinea($m[2]), $nivel);
                $i++;
                continue;
            }
            if (str_starts_with($linea, '|')) {
                $this->cerrarParrafo($parrafo, $salida);
                $filas = [];
                while ($i < count($lineas) && str_starts_with(trim($lineas[$i]), '|')) {
                    $filas[] = trim($lineas[$i]);
                    $i++;
                }
                $salida[] = $this->tabla($filas);
                continue;
            }
            if (str_starts_with($linea, '>')) {
                $this->cerrarParrafo($parrafo, $salida);
                $cita = [];
                while ($i < count($lineas) && str_starts_with(trim($lineas[$i]), '>')) {
                    $cita[] = ltrim(substr(trim($lineas[$i]), 1));
                    $i++;
                }
                $salida[] = '<aside class="nota-guia"><p>' . $this->enLinea(implode(' ', $cita)) . '</p></aside>';
                continue;
            }
            if (preg_match('/^(\s*)([-*]|\d+\.)\s+/', $linea) === 1) {
                $this->cerrarParrafo($parrafo, $salida);
                [$html, $i] = $this->lista($lineas, $i, 0);
                $salida[] = $html;
                continue;
            }
            $parrafo[] = trim($linea);
            $i++;
        }
        $this->cerrarParrafo($parrafo, $salida);
        return implode("\n", $salida);
    }

    /**
     * @param list<string> $parrafo líneas acumuladas del párrafo en curso; queda vacío
     * @param list<string> $salida
     */
    private function cerrarParrafo(array &$parrafo, array &$salida): void
    {
        if ($parrafo !== []) {
            $salida[] = '<p>' . $this->enLinea(implode(' ', $parrafo)) . '</p>';
            $parrafo = [];
        }
    }

    /**
     * @param list<string> $lineas
     * @return array{0:string,1:int}
     */
    private function lista(array $lineas, int $i, int $sangria): array
    {
        preg_match('/^\s*([-*]|\d+\.)\s+/', $lineas[$i], $m);
        $etiqueta = ctype_digit(rtrim($m[1], '.')) ? 'ol' : 'ul';
        $elementos = [];
        while ($i < count($lineas)) {
            $linea = rtrim($lineas[$i]);
            if (preg_match('/^(\s*)([-*]|\d+\.)\s+(.*)$/', $linea, $p) !== 1) {
                if (trim($linea) !== '' && $elementos !== [] && strlen($linea) - strlen(ltrim($linea)) > $sangria) {
                    $elementos[count($elementos) - 1] .= ' ' . $this->enLinea(trim($linea));
                    $i++;
                    continue;
                }
                break;
            }
            $nivel = strlen($p[1]);
            if ($nivel < $sangria) {
                break;
            }
            if ($nivel > $sangria && $elementos !== []) {
                [$anidada, $i] = $this->lista($lineas, $i, $nivel);
                $elementos[count($elementos) - 1] .= $anidada;
                continue;
            }
            $elementos[] = $this->enLinea($p[3]);
            $i++;
        }
        return ["<{$etiqueta}>" . implode('', array_map(static fn (string $e): string => "<li>{$e}</li>", $elementos)) . "</{$etiqueta}>", $i];
    }

    /** @param list<string> $filas */
    private function tabla(array $filas): string
    {
        $celdas = static fn (string $fila): array => array_map('trim', explode('|', trim($fila, ' |')));
        $encabezado = $celdas($filas[0]);
        $cuerpo = array_filter(array_slice($filas, 1), static fn (string $f): bool => preg_match('/^\|[\s:|-]+\|?$/', $f) !== 1);
        $html = '<div class="contenedor-tabla" tabindex="0" role="region" aria-label="' . Vista::escapar($encabezado[0]) . '"><table class="tabla"><thead><tr>';
        foreach ($encabezado as $c) {
            $html .= '<th scope="col">' . $this->enLinea($c) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($cuerpo as $fila) {
            $html .= '<tr>' . implode('', array_map(fn (string $c): string => '<td>' . $this->enLinea($c) . '</td>', $celdas($fila))) . '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private function enLinea(string $texto): string
    {
        $codigos = [];
        // El código en línea se aparta primero: dentro no se aplica ningún otro formato.
        $texto = (string) preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codigos): string {
            $codigos[] = '<code>' . Vista::escapar($m[1]) . '</code>';
            return "\x00" . (count($codigos) - 1) . "\x00";
        }, $texto);
        $html = Vista::escapar($texto);
        $html = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function (array $m): string {
            $destino = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);
            if (preg_match('/^([a-z0-9-]+)\.md(#[a-z0-9-]+)?$/', $destino, $g) === 1) {
                $url = ($this->rutaGuia)($g[1]) . ($g[2] ?? '');
            } elseif (str_starts_with($destino, '#') || str_starts_with($destino, 'https://')) {
                $url = $destino;
            } else {
                return $m[1];
            }
            $externo = str_starts_with($url, 'https://') ? ' rel="noopener noreferrer"' : '';
            return '<a href="' . Vista::escapar($url) . '"' . $externo . '>' . $m[1] . '</a>';
        }, $html);
        $html = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        return (string) preg_replace_callback("/\x00(\d+)\x00/", static fn (array $m): string => $codigos[(int) $m[1]], $html);
    }

    public static function ancla(string $titulo): string
    {
        $base = strtolower(strtr(trim($titulo), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n']));
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $base), '-');
    }
}
