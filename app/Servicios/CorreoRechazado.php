<?php

declare(strict_types=1);

namespace App\Servicios;

use RuntimeException;

/** Rechazo definitivo del correo (destinatario o plantilla inválidos): no se reintenta. */
final class CorreoRechazado extends RuntimeException
{
}
