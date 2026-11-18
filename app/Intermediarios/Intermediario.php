<?php

declare(strict_types=1);

namespace App\Intermediarios;

use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;

/** Eslabón de la cadena de la aplicación (04-07, 4.7). Llama a $siguiente o corta la cadena con su propia respuesta. */
interface Intermediario
{
    /** @param callable(Solicitud):Respuesta $siguiente */
    public function procesar(Solicitud $solicitud, callable $siguiente): Respuesta;
}
