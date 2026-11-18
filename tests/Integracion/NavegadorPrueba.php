<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Nucleo\Aplicacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Url;
use Closure;

/**
 * Navegador mínimo para las pruebas de integración: conserva las cookies entre solicitudes, extrae el token CSRF
 * de los formularios y crea una Aplicacion nueva en cada solicitud (como PHP en el servidor, sin estado
 * compartido). $configurar recibe el contenedor de cada aplicación para sustituir servicios (señal de
 * mantenimiento temporal, etc.).
 */
final class NavegadorPrueba
{
    /** @var array<string,string> */
    public array $cookies = [];
    public string $ultimoCsrf = '';
    public ?Respuesta $ultima = null;

    public function __construct(private readonly ?Closure $configurar = null, private readonly string $ip = '190.85.12.34')
    {
    }

    /**
     * @param array<string,string> $cuerpo
     * @param array<string,string> $cabeceras nombres en minúsculas
     */
    public function solicitar(string $metodo, string $rutaConConsulta, array $cuerpo = [], array $cabeceras = []): Respuesta
    {
        $partes = parse_url($rutaConConsulta);
        $consulta = [];
        parse_str((string) ($partes['query'] ?? ''), $consulta);
        $cabeceras += ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Pruebas'];
        $solicitud = new Solicitud($metodo, (string) ($partes['path'] ?? '/'), $consulta, $cuerpo, $cabeceras, $this->cookies, $this->ip);
        $aplicacion = Aplicacion::crear(require RAIZ . '/config/app.php');
        if ($this->configurar !== null) {
            ($this->configurar)($aplicacion->contenedor());
        }
        $respuesta = $aplicacion->manejar($solicitud);
        foreach ($respuesta->cookies as $cookie) {
            [$par] = explode(';', $cookie, 2);
            [$nombre, $valor] = array_pad(explode('=', $par, 2), 2, '');
            if (str_contains($cookie, 'Max-Age=0')) {
                unset($this->cookies[$nombre]);
            } else {
                $this->cookies[$nombre] = $valor;
            }
        }
        if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $respuesta->cuerpo, $m) === 1
            || preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $respuesta->cuerpo, $m) === 1) {
            $this->ultimoCsrf = $m[1];
        }
        $this->ultima = $respuesta;
        return $respuesta;
    }

    /** @param array<string,string> $cabeceras */
    public function get(string $ruta, array $cabeceras = []): Respuesta
    {
        return $this->solicitar('GET', $ruta, [], $cabeceras);
    }

    /**
     * POST de formulario con el token CSRF de la última página (campo _csrf).
     *
     * @param array<string,string> $cuerpo
     * @param array<string,string> $cabeceras
     */
    public function post(string $ruta, array $cuerpo = [], array $cabeceras = []): Respuesta
    {
        return $this->solicitar('POST', $ruta, $cuerpo + ['_csrf' => $this->ultimoCsrf], $cabeceras);
    }

    /**
     * POST HTMX con el token en la cabecera X-CSRF-Token (como retroalimentacion-ui.js).
     *
     * @param array<string,string> $cuerpo
     * @param array<string,string> $cabeceras
     */
    public function postHtmx(string $ruta, array $cuerpo = [], array $cabeceras = []): Respuesta
    {
        return $this->solicitar('POST', $ruta, $cuerpo, $cabeceras + ['hx-request' => 'true', 'x-csrf-token' => $this->ultimoCsrf]);
    }

    /** Ruta de aplicación (sin la ruta base) de la redirección de la última respuesta (Location o HX-Redirect). */
    public function destino(): string
    {
        $url = $this->ultima?->cabeceras['Location'] ?? $this->ultima?->cabeceras['HX-Redirect'] ?? '';
        $partes = parse_url($url);
        $ruta = Solicitud::normalizarRuta((string) ($partes['path'] ?? ''), Url::base());
        return $ruta . (isset($partes['query']) ? '?' . $partes['query'] : '');
    }

    public function seguir(): Respuesta
    {
        return $this->get($this->destino());
    }
}
