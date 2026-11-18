<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;

/**
 * Presentación común del estado de un trabajo (RF-Q-012): fragmento que se consulta a sí mismo con espera
 * creciente (2, 3, 5, 8, 13 y 15 s) y se detiene en estado terminal porque el fragmento terminal ya no lleva
 * disparador. Para HTMX, el estado terminal emite el mensaje final una sola vez.
 *
 * No se usa el código 286 de htmx («detener la consulta»): Apache no lo reconoce y lo convierte en 500, y con
 * un fragmento sin disparador no hace falta.
 */
trait RespuestaEstadoTrabajo
{
    /**
     * @param array<string,mixed> $trabajo fila de trabajos
     * @param list<int> $esperas segundos de espera por intento
     */
    protected function respuestaEstado(Solicitud $solicitud, array $trabajo, string $rutaEstado, array $esperas): Respuesta
    {
        $terminal = in_array($trabajo['estado'], ['completado', 'fallido', 'cancelado'], true);
        $indice = min(count($esperas) - 1, max(0, (int) ($solicitud->consulta['intento'] ?? 0)));
        $respuesta = $this->renderizar($solicitud, 'trabajos/pagina_estado', 'trabajos/estado', [
            'titulo' => 'Estado del proceso',
            'trabajo' => $trabajo,
            'rutaEstado' => $rutaEstado,
            'espera' => $esperas[$indice],
            'intento' => $indice + 1,
            'terminal' => $terminal,
        ]);
        if ($terminal && $solicitud->esHtmx()) {
            $respuesta = $trabajo['estado'] === 'completado'
                ? $this->conToast($respuesta, 'success', 'El proceso terminó correctamente.')
                : $this->conToast($respuesta, 'error', 'El proceso no se pudo completar. Revisa el detalle.');
        }
        return $respuesta;
    }
}
