<?php

declare(strict_types=1);

/**
 * Plantilla «recuperacion» (Anexo I; RAC-006). Variables: enlace (un solo uso, 30 min).
 *
 * @var array<string,mixed> $datos
 */

return [
    'asunto' => 'Restablecimiento de contraseña de KORA',
    'texto' => "Hola:\n\n"
        . "Recibimos una solicitud para restablecer la contraseña de la cuenta asociada a este correo.\n\n"
        . "Usa este enlace dentro de los próximos 30 minutos (solo funciona una vez):\n{$datos['enlace']}\n\n"
        . "Al restablecerla se cerrarán todas tus sesiones abiertas.\n\n"
        . "Si no hiciste esta solicitud, puedes ignorar este mensaje; tu contraseña actual sigue siendo válida. "
        . "Te recomendamos informar a la coordinación del centro si recibes estos correos sin haberlos pedido.\n\n"
        . '— KORA · SENA, Centro Agropecuario de Buga',
];
