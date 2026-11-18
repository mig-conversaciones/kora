<?php

declare(strict_types=1);

namespace App\Nucleo;

/** Respuesta HTTP. Las cabeceras de mensajes y seguridad se agregan con conCabecera; las cookies, con agregarCookie. */
final class Respuesta
{
    /** @var list<string> valores completos de Set-Cookie (puede haber varios) */
    public array $cookies = [];

    /** @param array<string,string> $cabeceras */
    public function __construct(public string $cuerpo = '', public int $estado = 200, public array $cabeceras = [])
    {
    }

    public function agregarCookie(string $valor): self
    {
        $this->cookies[] = $valor;
        return $this;
    }

    /** Redirección para HTMX: el navegador navega a la URL completa (HX-Redirect), sin intercambiar el fragmento. */
    public static function redireccionHtmx(string $url): self
    {
        return (new self('', 204))->conCabecera('HX-Redirect', $url);
    }

    public static function html(string $html, int $estado = 200): self
    {
        return new self($html, $estado, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<string,mixed> $datos */
    public static function json(array $datos, int $estado = 200): self
    {
        return new self(json_encode($datos, JSON_THROW_ON_ERROR), $estado, ['Content-Type' => 'application/json']);
    }

    public static function redireccion(string $url, int $estado = 303): self
    {
        return new self('', $estado, ['Location' => $url]);
    }

    /** 503 controlado cuando la base de datos no responde; sin detalles internos (RS-010). */
    public static function servicioNoDisponible(Solicitud $solicitud): self
    {
        $html = '<!doctype html><html lang="es"><meta charset="utf-8"><title>Servicio no disponible</title>'
            . '<main role="status"><h1>El servicio no está disponible en este momento</h1>'
            . '<p>Intenta de nuevo en unos minutos. Si el problema continúa, informa al área de soporte.</p></main></html>';
        $respuesta = self::html($html, 503)
            ->conCabecera('Retry-After', '120')
            ->conCabecera('Cache-Control', 'no-store');
        return $solicitud->esHtmx()
            ? $respuesta->conCabecera('HX-Retarget', '#contenedor-errores')->conCabecera('HX-Reswap', 'innerHTML')
            : $respuesta;
    }

    public function conCabecera(string $nombre, string $valor): self
    {
        $this->cabeceras[$nombre] = $valor;
        return $this;
    }

    public function enviar(): void
    {
        foreach ($this->cabeceras as $nombre => $valor) {
            header("{$nombre}: {$valor}", true);
        }
        foreach ($this->cookies as $cookie) {
            header("Set-Cookie: {$cookie}", false);
        }
        // El código se fija después de las cabeceras: PHP convierte en 302 cualquier respuesta con Location que no
        // sea 201 ni 3xx, y el 202 Accepted de la cola (RF-Q-002) lleva Location.
        http_response_code($this->estado);
        echo $this->cuerpo;
    }
}
