<?php

declare(strict_types=1);

namespace App\Repositorios;

/** Hembras del hato (RF-052). Toda consulta lleva el alcance de datos del usuario (RDI-003). */
final class RepositorioAnimales extends RepositorioConAlcance
{
    protected const TABLA = 'animales';
    protected const ALIAS = 'a';
    protected const ORDENABLES = ['identificador' => 'a.identificador', 'nacimiento' => 'a.fecha_nacimiento'];
}
