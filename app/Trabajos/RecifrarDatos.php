<?php

declare(strict_types=1);

namespace App\Trabajos;

use App\Cola\ContextoTrabajo;
use App\Cola\ManejadorTrabajo;
use App\Nucleo\Registro;
use App\Operacion\ServicioRecifrado;

/**
 * Recifrado tras una rotación de claves (tipo seguridad.recifrar; RS-013, D-15). Trabaja por bloques dentro del
 * presupuesto de la cola y pide continuar si queda algo pendiente. Se encola con `php cli/recifrar.php encolar`.
 */
final class RecifrarDatos implements ManejadorTrabajo
{
    public function __construct(private readonly ServicioRecifrado $recifrado, private readonly Registro $registro)
    {
    }

    public function ejecutar(array $trabajo, ContextoTrabajo $contexto): array
    {
        $contexto->latido(5, 'Buscando datos cifrados con una clave anterior.');
        $resultado = $this->recifrado->recifrar(500, static fn (): bool => $contexto->quedaTiempo(5.0));
        $this->registro->info('seguridad.recifrar', $resultado);
        if ($resultado['pendientes'] > 0) {
            $contexto->latido(50, "Recifrado parcial: quedan {$resultado['pendientes']}.");
            $contexto->solicitarContinuacion();
        } else {
            $contexto->latido(100, 'Todo quedó cifrado con la clave vigente.');
        }
        return $resultado;
    }
}
