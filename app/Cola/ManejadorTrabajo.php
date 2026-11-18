<?php

declare(strict_types=1);

namespace App\Cola;

/**
 * Contrato de cada tipo de trabajo. La entrega es al-menos-una-vez: el manejador debe ser idempotente
 * para su operación y ámbito (RF-Q-010). Debe reevaluar los permisos del actor antes de producir efectos
 * (RAC-024) y lanzar ErrorTransitorio para reintentar o ErrorDefinitivo para no reintentar.
 */
interface ManejadorTrabajo
{
    /**
     * @param array<string,mixed> $trabajo fila de `trabajos` con `carga` ya decodificada
     * @return array<string,mixed>|null resultado serializable (por ejemplo, ruta privada del archivo generado)
     */
    public function ejecutar(array $trabajo, ContextoTrabajo $contexto): ?array;
}
