<?php

declare(strict_types=1);

namespace App\Politicas;

/**
 * Recaudos (RF-029, Obs. 2). Solo el Grupo de Recaudo registra, importa, clasifica, concilia, edita y archiva. Se exige
 * el rol además del permiso: aunque la matriz concediera recaudos.gestionar a otro rol, la escritura sigue denegada.
 * La consulta respeta el alcance: un recaudo sin unidad solo lo ve quien tiene alcance de centro.
 */
final class PoliticaRecaudos implements Politica
{
    private const ESCRITURA = ['recaudos.gestionar', 'recaudos.importar', 'recaudos.clasificar', 'recaudos.conciliar'];

    public function decidir(UsuarioSesion $usuario, string $accion, ?object $recurso, AlcanceDatos $alcance): Decision
    {
        if (!$usuario->tienePermiso($accion)) {
            return Decision::denegar('Permiso no asignado al rol.');
        }
        if (in_array($accion, self::ESCRITURA, true) && !$usuario->tieneRol('grupo_recaudo')) {
            return Decision::denegar('Solo el Grupo de Recaudo escribe recaudos (RF-029).');
        }
        if ($recurso === null) {
            return Decision::permitir();
        }
        if (!$recurso instanceof RecursoUnidad) {
            return Decision::denegar('Recurso no reconocido por la política.');
        }
        if ($recurso->centroId !== $alcance->centroId || !$alcance->permiteUnidad($recurso->unidadId)) {
            return Decision::denegar('La unidad no está en el alcance del usuario.');
        }
        return Decision::permitir();
    }
}
