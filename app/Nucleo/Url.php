<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Ruta base de la aplicación. En Hostinger la aplicación vive en la raíz del dominio (ruta base vacía); en MAMP
 * local vive en un subdirectorio (http://localhost/dashboard_laila). Toda URL interna se construye con a() para
 * que el mismo código funcione en ambos casos.
 */
final class Url
{
    private static ?string $base = null;

    public static function base(): string
    {
        if (self::$base === null) {
            $ruta = (string) parse_url(Entorno::texto('APP_URL', '/'), PHP_URL_PATH);
            self::$base = rtrim($ruta, '/');
        }
        return self::$base;
    }

    /** Solo para pruebas: fuerza la ruta base. */
    public static function establecerBase(?string $base): void
    {
        self::$base = $base === null ? null : rtrim($base, '/');
    }

    /** URL interna a partir de una ruta de la aplicación que empieza por «/». */
    public static function a(string $ruta): string
    {
        return self::base() . '/' . ltrim($ruta, '/');
    }
}
