<?php

declare(strict_types=1);

namespace App\Nucleo;

/** Solicitud HTTP normalizada. Los intermediarios agregan atributos: request_id, nonce, ruta y, desde la I2, usuario y alcance. */
final class Solicitud
{
    /** @var array<string,mixed> */
    private array $atributos = [];

    /**
     * @param array<string,mixed> $consulta
     * @param array<string,mixed> $cuerpo
     * @param array<string,string> $cabeceras nombres en minúsculas
     * @param array<string,string> $cookies
     */
    public function __construct(
        public readonly string $metodo,
        public readonly string $ruta,
        public readonly array $consulta,
        public readonly array $cuerpo,
        public readonly array $cabeceras,
        public readonly array $cookies,
        public readonly string $ip,
    ) {
    }

    /**
     * @param string $rutaBase prefijo que se retira de la ruta (subdirectorio local, ver Url)
     */
    public static function desdeGlobales(string $rutaBase = ''): self
    {
        $cabeceras = [];
        foreach ($_SERVER as $clave => $valor) {
            if (str_starts_with((string) $clave, 'HTTP_')) {
                $cabeceras[strtolower(str_replace('_', '-', substr((string) $clave, 5)))] = (string) $valor;
            }
        }
        $ruta = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalizarRuta($ruta, $rutaBase),
            $_GET,
            $_POST,
            $cabeceras,
            $_COOKIE,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    /** Retira la ruta base y la barra final: «/dashboard_laila/sistema-diseno/» → «/sistema-diseno». */
    public static function normalizarRuta(string $ruta, string $rutaBase = ''): string
    {
        $rutaBase = rtrim($rutaBase, '/');
        if ($rutaBase !== '' && ($ruta === $rutaBase || str_starts_with($ruta, $rutaBase . '/'))) {
            $ruta = substr($ruta, strlen($rutaBase));
        }
        $ruta = rtrim($ruta, '/');
        return $ruta === '' ? '/' : $ruta;
    }

    public function cabecera(string $nombre): ?string
    {
        return $this->cabeceras[strtolower($nombre)] ?? null;
    }

    public function esHtmx(): bool
    {
        return $this->cabecera('HX-Request') === 'true';
    }

    public function esEscritura(): bool
    {
        return !in_array($this->metodo, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function tieneCookie(string $nombre): bool
    {
        return isset($this->cookies[$nombre]) && $this->cookies[$nombre] !== '';
    }

    public function atributo(string $clave, mixed $defecto = null): mixed
    {
        return $this->atributos[$clave] ?? $defecto;
    }

    public function conAtributo(string $clave, mixed $valor): self
    {
        $copia = clone $this;
        $copia->atributos[$clave] = $valor;
        return $copia;
    }
}
