<?php

declare(strict_types=1);

namespace App\Intermediarios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Registro;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Politicas\UsuarioSesion;

/**
 * Correlación y registro técnico de cada solicitud (RAU-008, RAU-009).
 *
 * retroalimentacion-ui.js envía X-Request-ID (32 hexadecimales); si falta o no tiene el formato esperado se genera
 * uno en el servidor. Se devuelve en la respuesta, se usa como «código de soporte» en los errores y se guarda en
 * los trabajos (correlacion_id) y la auditoría. Cada solicitud deja una línea JSON con usuario, ruta, estado,
 * duración, memoria y número de consultas; nivel «aviso» si falla con 5xx o tarda más de 1.500 ms.
 */
final class IdSolicitud implements Intermediario
{
    private const UMBRAL_LENTA_MS = 1500;

    public function __construct(private readonly Registro $registro, private readonly BaseDatos $bd)
    {
    }

    public function procesar(Solicitud $solicitud, callable $siguiente): Respuesta
    {
        $recibido = strtolower((string) $solicitud->cabecera('X-Request-ID'));
        $id = preg_match('/^[a-f0-9]{32}$/', $recibido) === 1 ? $recibido : bin2hex(random_bytes(16));
        Registro::fijarIdSolicitud($id);
        $inicio = hrtime(true);
        $consultasPrevias = $this->bd->totalConsultas();
        $solicitud = $solicitud->conAtributo('request_id', $id);

        $respuesta = $siguiente($solicitud);

        $milisegundos = (int) ((hrtime(true) - $inicio) / 1_000_000);
        $ruta = $solicitud->atributo('ruta');
        $usuario = $solicitud->atributo('usuario');
        $this->registro->registrar(
            $respuesta->estado >= 500 || $milisegundos > self::UMBRAL_LENTA_MS ? 'aviso' : 'info',
            'solicitud',
            [
                'user_id' => $usuario instanceof UsuarioSesion ? $usuario->id : null,
                'metodo' => $solicitud->metodo,
                'ruta' => $solicitud->ruta,
                'accion' => is_array($ruta) && isset($ruta['accion'][0], $ruta['accion'][1])
                    ? substr((string) strrchr('\\' . $ruta['accion'][0], '\\'), 1) . '::' . $ruta['accion'][1]
                    : null,
                'htmx' => $solicitud->esHtmx(),
                'status' => $respuesta->estado,
                'duration_ms' => $milisegundos,
                'memoria_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                'consultas' => $this->bd->totalConsultas() - $consultasPrevias,
            ]
        );
        return $respuesta->conCabecera('X-Request-ID', $id);
    }
}
