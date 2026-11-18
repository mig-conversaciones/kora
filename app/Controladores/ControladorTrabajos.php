<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\GeneradorUlid;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Politicas\UsuarioSesion;

/**
 * Estado de un trabajo (equivale a JobController; RF-Q-012). Solo el actor que lo originó (en su centro) o un
 * superadministrador puede consultarlo; para los demás el trabajo no existe (404, RAC-028).
 *
 * La ruta exige el permiso trabajos.consultar_propios: hasta que la I2 entregue sesiones, el intermediario
 * Autorizar la rechaza para todos. La descarga de resultados (RF-Q-013) llega con las exportaciones (I6).
 */
final class ControladorTrabajos extends ControladorBase
{
    use RespuestaEstadoTrabajo;

    /** @param list<int> $esperas */
    public function __construct(Vista $vista, private readonly BaseDatos $bd, private readonly array $esperas)
    {
        parent::__construct($vista);
    }

    public function estado(Solicitud $solicitud, string $ulid): Respuesta
    {
        $trabajo = $this->trabajoAutorizado($solicitud, $ulid);
        return $this->respuestaEstado($solicitud, $trabajo, "/trabajos/{$ulid}", $this->esperas);
    }

    /** @return array<string,mixed> */
    private function trabajoAutorizado(Solicitud $solicitud, string $ulid): array
    {
        $usuario = $solicitud->atributo('usuario');
        if (!GeneradorUlid::esValido($ulid) || !$usuario instanceof UsuarioSesion) {
            throw new ErrorHttp(404);
        }
        $trabajo = $this->bd->fila('SELECT * FROM trabajos WHERE ulid = :ulid', ['ulid' => $ulid]);
        if ($trabajo === null) {
            throw new ErrorHttp(404);
        }
        $propio = (int) $trabajo['actor_id'] === $usuario->id && (int) $trabajo['centro_id'] === $usuario->centroId;
        if (!$propio && !$usuario->esSuperadministrador()) {
            throw new ErrorHttp(404);
        }
        return $trabajo;
    }
}
