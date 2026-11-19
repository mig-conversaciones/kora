<?php

declare(strict_types=1);

namespace App\Politicas;

/**
 * Política de los recursos financieros por unidad: asignaciones, gastos y procesos de autoconsumo (RF-024, RF-025,
 * RF-036, Obs. 5). Exige el permiso RBAC y, si hay un recurso concreto, que su centro y su unidad estén en el
 * alcance del usuario. Crear o editar en una unidad ajena se deniega con 403 (criterio de RF-025); leer un registro
 * ajeno ya responde 404 en el repositorio, que nunca lo encuentra (RAC-028).
 *
 * El periodo cerrado (RF-028) no es una decisión de autorización sino un conflicto de estado: lo verifica
 * ServicioPeriodos y responde 409 «Periodo cerrado».
 */
final class PoliticaAlcanceUnidad implements Politica
{
    public function decidir(UsuarioSesion $usuario, string $accion, ?object $recurso, AlcanceDatos $alcance): Decision
    {
        if (!$usuario->tienePermiso($accion)) {
            return Decision::denegar('Permiso no asignado al rol.');
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
