<?php

declare(strict_types=1);

namespace App\Nucleo;

use RuntimeException;

/**
 * Lee .env sin dependencias externas. El archivo vive fuera de public_html con permisos 600 (RS-012);
 * aquí solo se interpreta el formato CLAVE=valor con comillas opcionales (04-02, 4.2.3).
 */
final class Entorno
{
    /** @var array<string,string> */
    private static array $valores = [];

    public static function cargar(string $ruta): void
    {
        if (!is_readable($ruta)) {
            throw new RuntimeException('No se encontró la configuración del entorno.');
        }
        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
                continue;
            }
            [$clave, $valor] = array_map('trim', explode('=', $linea, 2));
            if (strlen($valor) >= 2 && ($valor[0] === '"' || $valor[0] === "'") && $valor[-1] === $valor[0]) {
                $valor = substr($valor, 1, -1);
            }
            self::$valores[$clave] = $valor;
        }
    }

    /** Fija un valor en memoria; lo usan las pruebas y las herramientas de la CLI (p. ej. --base). */
    public static function establecer(string $clave, string $valor): void
    {
        self::$valores[$clave] = $valor;
    }

    public static function texto(string $clave, ?string $defecto = null): string
    {
        $valor = self::$valores[$clave] ?? getenv($clave);
        if ($valor === false || $valor === '') {
            if ($defecto === null) {
                throw new RuntimeException("Falta la variable de entorno {$clave}.");
            }
            return $defecto;
        }
        return (string) $valor;
    }

    public static function entero(string $clave, int $defecto): int
    {
        return (int) self::texto($clave, (string) $defecto);
    }

    public static function booleano(string $clave, bool $defecto): bool
    {
        return filter_var(self::texto($clave, $defecto ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function esProduccion(): bool
    {
        return self::texto('APP_ENTORNO', 'produccion') === 'produccion';
    }
}
