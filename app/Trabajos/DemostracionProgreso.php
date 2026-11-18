<?php

declare(strict_types=1);

namespace App\Trabajos;

use App\Cola\ContextoTrabajo;
use App\Cola\ErrorDefinitivo;
use App\Cola\ManejadorTrabajo;

/**
 * Trabajo de demostración (solo fuera de producción): avanza por pasos, informa progreso con latidos, guarda un
 * punto de control y, si se acaba el presupuesto de la ejecución, pide continuar en la siguiente sin consumir
 * un intento (RF-Q-007, RF-Q-011). Lo usan la vitrina del sistema de diseño y las pruebas de la cola.
 */
final class DemostracionProgreso implements ManejadorTrabajo
{
    public function ejecutar(array $trabajo, ContextoTrabajo $contexto): ?array
    {
        /** @var array<string,mixed> $carga */
        $carga = $trabajo['carga'];
        $pasos = (int) ($carga['pasos'] ?? 5);
        $milisegundos = (int) ($carga['ms_por_paso'] ?? 400);
        if ($pasos < 1 || $pasos > 50 || $milisegundos < 0 || $milisegundos > 5000) {
            throw new ErrorDefinitivo('Parámetros de la demostración fuera de rango.');
        }
        $punto = json_decode((string) ($trabajo['punto_control'] ?? 'null'), true);
        $inicio = is_array($punto) ? (int) ($punto['paso'] ?? 0) : 0;
        for ($paso = $inicio + 1; $paso <= $pasos; $paso++) {
            if (!$contexto->quedaTiempo(($milisegundos / 1000) + 1)) {
                $contexto->solicitarContinuacion();
                return null;
            }
            usleep($milisegundos * 1000);
            $contexto->latido((int) floor($paso * 100 / $pasos), "Paso {$paso} de {$pasos} completado.", ['paso' => $paso]);
        }
        return ['pasos' => $pasos, 'mensaje' => 'Demostración terminada.'];
    }
}
