<?php

declare(strict_types=1);

namespace App\Cola;

use RuntimeException;

/** La reserva venció y otro trabajador pudo tomar el trabajo: se abandona sin escribir resultados. */
final class ReservaPerdida extends RuntimeException
{
}
