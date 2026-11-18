<?php

declare(strict_types=1);

namespace App\Cola;

use RuntimeException;

/** Fallo no recuperable (validación, permiso revocado, tipo inexistente): va a trabajos_fallidos sin reintentos. */
final class ErrorDefinitivo extends RuntimeException
{
}
